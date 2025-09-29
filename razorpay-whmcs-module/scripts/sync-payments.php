<?php
/**
 * Razorpay Payment Synchronization Tool
 * 
 * This tool synchronizes payments between Razorpay and WHMCS for invoices
 * that are marked as unpaid but have successful payments in Razorpay.
 * 
 * USAGE:
 * 1. Run from command line: php sync-payments.php
 * 2. Or access via web: https://yourdomain.com/modules/gateways/razorpay/sync-payments.php?password=change_me_this_at_earliest_if_using
 * 3. CLI parameters: --since=2025-09-20 --until=2025-09-27 --limit=50 --dry-run
 * 
 * SECURITY: Change the password in production!
 */

// Require WHMCS libraries
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../lib/razorpay-sdk/Razorpay.php';

// Initialize WHMCS database connection
use Illuminate\Database\Capsule\Manager as Capsule;

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

// Security check
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Check if running from CLI
$isCLI = php_sapi_name() === 'cli';
$maxExecutionTime = $isCLI ? 0 : 25; // No limit for CLI, 25s for web
set_time_limit($maxExecutionTime);

// Parse command line arguments if CLI
if ($isCLI) {
    $options = getopt('', ['since:', 'until:', 'limit:', 'dry-run', 'password:']);
    $syncPassword = $options['password'] ?? 'change_me_this_at_earliest_if_using';
} else {
    $syncPassword = $_GET['password'] ?? '';
}

// Simple authentication - CHANGE THIS PASSWORD IN PRODUCTION
if ($syncPassword !== 'change_me_this_at_earliest_if_using') {
    die("<h2>Access Denied</h2><p>Please provide the correct password: ?password=your_password</p>");
}

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

// Configure API timeouts
function configureApiTimeouts($api) {
    // Set timeouts via headers (if supported by SDK)
    $api->setHeader('X-Request-Timeout', '30');
    $api->setHeader('X-Connection-Timeout', '10');
    return $api;
}

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
    $api = configureApiTimeouts($api);
} catch (Exception $e) {
    die("<p style='color: red;'>Error: Failed to initialize Razorpay API - " . $e->getMessage() . "</p>");
}

echo "<h2>Razorpay Payment Synchronization Tool</h2>\n";
echo "<p>This tool will check for unpaid invoices and verify their payment status with Razorpay.</p>\n";

// Parse parameters - use broader default range
$since = $isCLI ? ($options['since'] ?? date('Y-m-d H:i:s', strtotime('-90 days'))) : ($_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-90 days')));
$until = $isCLI ? ($options['until'] ?? date('Y-m-d H:i:s')) : ($_GET['until'] ?? date('Y-m-d H:i:s'));
$limit = $isCLI ? ($options['limit'] ?? 50) : ($_GET['limit'] ?? 50);
$dryRun = $isCLI ? isset($options['dry-run']) : false;

// Convert to timestamps for Razorpay API (in seconds, not milliseconds)
$sinceTimestamp = strtotime($since);
$untilTimestamp = strtotime($until);

// Get last sync checkpoint
$lastSyncedAt = $gatewayParams['last_synced_at'] ?? null;
if ($lastSyncedAt && !$isCLI) {
    $since = $lastSyncedAt;
    $sinceTimestamp = strtotime($since) * 1000;
}

echo "<h3>Sync Parameters</h3>\n";
echo "<p><strong>Since:</strong> {$since}</p>\n";
echo "<p><strong>Until:</strong> {$until}</p>\n";
echo "<p><strong>Limit:</strong> {$limit}</p>\n";
if ($dryRun) echo "<p><strong>Mode:</strong> DRY RUN (no changes will be made)</p>\n";

try {
    // Get all unpaid invoices including those with late fees
    // Limit to prevent timeout
    $offset = 0;
    
    $unpaidInvoices = Capsule::table('tblinvoices')
        ->where('status', 'Unpaid')
        ->where('paymentmethod', 'razorpay')
        ->orderBy('date', 'desc')
        ->limit($limit)
        ->offset($offset)
        ->get();
    
    // Also filter by date if specified
    if ($since !== date('Y-m-d H:i:s', strtotime('-24 hours'))) {
        $unpaidInvoices = $unpaidInvoices->filter(function($invoice) use ($since) {
            return strtotime($invoice->date) >= strtotime($since);
        });
    }

    // Also get invoices that might have been paid but have late fees added later
    // Check if latefee column exists first
    $hasLateFeeColumn = false;
    $invoicesWithLateFees = collect();
    try {
        $testQuery = Capsule::table('tblinvoices')->select('latefee')->limit(1)->get();
        $hasLateFeeColumn = true;
    } catch (Exception $e) {
        // Column doesn't exist, skip late fee handling
        $hasLateFeeColumn = false;
    }
    
    if ($hasLateFeeColumn) {
        try {
            $invoicesWithLateFees = Capsule::table('tblinvoices')
                ->where('status', 'Unpaid')
                ->where('paymentmethod', 'razorpay')
                ->where('latefee', '>', 0)
                ->orderBy('date', 'desc')
                ->get();
        } catch (Exception $e) {
            // Fallback if latefee column doesn't exist
            $invoicesWithLateFees = collect();
        }
    }

    // Get total count for pagination
    $totalUnpaidCount = Capsule::table('tblinvoices')
        ->where('status', 'Unpaid')
        ->where('paymentmethod', 'razorpay')
        ->where('date', '>=', $since)
        ->count();
    
    echo "<h3>Processing " . count($unpaidInvoices) . " of {$totalUnpaidCount} unpaid Razorpay invoices</h3>\n";
    echo "<p><strong>Batch:</strong> 1 to " . count($unpaidInvoices) . " of {$totalUnpaidCount}</p>\n";
    
    if (!$hasLateFeeColumn) {
        echo "<p style='color: orange;'>⚠ Late fee column not found in database. Late fee handling is disabled.</p>\n";
    }
    
    // Batch fetch Razorpay data to minimize API calls
    $razorpayData = fetchRazorpayDataBatch($api, $sinceTimestamp, $untilTimestamp);
    
    // Add pagination controls
    if ($offset > 0) {
        $prevOffset = max(0, $offset - $limit);
        echo "<p><a href='?password=$syncPassword&since=" . urlencode($since) . "&limit=$limit&offset=$prevOffset'>← Previous {$limit} invoices</a></p>\n";
    }
    if (($offset + count($unpaidInvoices)) < $totalUnpaidCount) {
        $nextOffset = $offset + $limit;
        $nextSince = date('Y-m-d H:i:s', strtotime($unpaidInvoices->last()->date));
        echo "<p><a href='?password=$syncPassword&since=" . urlencode($nextSince) . "&limit=$limit'>Next {$limit} invoices →</a></p>\n";
    }

    if (count($invoicesWithLateFees) > 0) {
        echo "<h3 style='color: orange;'>⚠ " . count($invoicesWithLateFees) . " invoices have late fees added</h3>\n";
        echo "<p>These invoices may have been paid before late fees were added. Checking for partial payments...</p>\n";
        
        echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>\n";
        echo "<tr><th>Invoice #</th><th>Original Amount</th><th>Late Fee</th><th>Total Amount</th><th>Status</th></tr>\n";
        
        foreach ($invoicesWithLateFees as $invoice) {
            $originalAmount = $invoice->total - $invoice->latefee;
            echo "<tr>";
            echo "<td>{$invoice->id}</td>";
            echo "<td>{$originalAmount} {$invoice->currency}</td>";
            echo "<td style='color: red;'>{$invoice->latefee} {$invoice->currency}</td>";
            echo "<td><strong>{$invoice->total} {$invoice->currency}</strong></td>";
            echo "<td>Late fee added</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

    $syncedCount = 0;
    $errorCount = 0;
    $lateFeeInvoices = 0;
    $startTime = time();
    
    // Process invoices with batch data
    $processedCount = 0;
    $maxProcessTime = $isCLI ? 0 : 20; // 20s for web, no limit for CLI

    $invoiceCount = 0;
    foreach ($unpaidInvoices as $invoice) {
        $invoiceCount++;
        $processedCount++;
        $hasLateFee = $hasLateFeeColumn && isset($invoice->latefee) && $invoice->latefee > 0;
        $originalAmount = $hasLateFee ? ($invoice->total - $invoice->latefee) : $invoice->total;
        
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>\n";
        echo "<strong>Invoice #{$invoice->id}</strong> - Amount: {$invoice->total} {$invoice->currency}";
        if ($hasLateFee) {
            echo " <span style='color: red;'>(Original: {$originalAmount} + Late Fee: {$invoice->latefee})</span>";
            $lateFeeInvoices++;
        }
        echo " <span style='color: gray;'>({$invoiceCount}/" . count($unpaidInvoices) . ")</span><br>\n";
        
        // Check time limit for web mode
        if (!$isCLI && (time() - $startTime) > $maxProcessTime) {
            echo "<p style='color: orange;'>⏰ Time limit reached. Processed {$processedCount} invoices.</p>\n";
            echo "<p><a href='?password=$syncPassword&since=" . urlencode(date('Y-m-d H:i:s', strtotime($invoice->date))) . "&limit=$limit'>Continue Next Batch</a></p>\n";
            break;
        }
        
        try {
            // Use batch data instead of individual API calls
            $foundPayment = false;
            $razorpayPaymentId = null;
            $gatewayFee = 0;

            // Search in batch data
            $matchingOrder = findMatchingOrder($razorpayData['orders'], $invoice);
            if ($matchingOrder) {
                $matchingPayment = findMatchingPayment($razorpayData['payments'], $invoice, $matchingOrder);
                if ($matchingPayment) {
                    $foundPayment = true;
                    $razorpayPaymentId = $matchingPayment['id'];
                    $gatewayFee = calculateGatewayFee($matchingPayment, $invoice);
                    echo "✓ Found payment: {$razorpayPaymentId}<br>\n";
                }
            }
            
            // If no payment found, try direct payment search
            if (!$foundPayment) {
                $matchingPayment = findMatchingPaymentDirect($razorpayData['payments'], $invoice);
                if ($matchingPayment) {
                    $foundPayment = true;
                    $razorpayPaymentId = $matchingPayment['id'];
                    $gatewayFee = calculateGatewayFee($matchingPayment, $invoice);
                    echo "✓ Found payment (direct): {$razorpayPaymentId}<br>\n";
                }
            }
            
            if ($foundPayment && $razorpayPaymentId) {
                // Check if payment already exists in WHMCS
                $existingPayment = Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoice->id)
                    ->where('transid', $razorpayPaymentId)
                    ->first();

                if (!$existingPayment) {
                    if (!$dryRun) {
                        // Get gateway configuration for fee mode
                        $feeMode = $gatewayParams['feeMode'] ?? 'merchant_absorbs';
                        
                        if ($feeMode === 'merchant_absorbs') {
                            // Record only invoice amount, fee absorbed by merchant
                            addInvoicePayment(
                                $invoice->id,
                                $razorpayPaymentId,
                                $invoice->total,
                                0,
                                'razorpay'
                            );
                            
                            // Log gateway fee as merchant expense
                            if ($gatewayFee > 0) {
                                logTransaction('razorpay', "Gateway fee absorbed: {$gatewayFee} {$invoice->currency}", "Fee Expense");
                            }
                        } elseif ($feeMode === 'client_pays') {
                            // Record full amount including fee
                            addInvoicePayment(
                                $invoice->id,
                                $razorpayPaymentId,
                                $invoice->total + $gatewayFee,
                                0,
                                'razorpay'
                            );
                        }
                        
                        echo "<span style='color: green;'>✓ Payment synchronized successfully!</span><br>\n";
                        if ($gatewayFee > 0) {
                            if ($feeMode === 'merchant_absorbs') {
                                echo "<span style='color: blue;'>💡 Gateway fee of {$gatewayFee} is absorbed by merchant (logged as expense)</span><br>\n";
                            } else {
                                echo "<span style='color: blue;'>💡 Gateway fee of {$gatewayFee} is included in payment amount</span><br>\n";
                            }
                        }
                        $syncedCount++;
                    } else {
                        echo "<span style='color: blue;'>🔍 DRY RUN: Would sync payment {$razorpayPaymentId}</span><br>\n";
                    }
                } else {
                    echo "<span style='color: orange;'>⚠ Payment already exists in WHMCS</span><br>\n";
                }
            } else {
                echo "<span style='color: red;'>✗ No captured payment found in Razorpay</span><br>\n";
            }
            
        } catch (Exception $e) {
            echo "<span style='color: red;'>✗ Error: " . $e->getMessage() . "</span><br>\n";
            $errorCount++;
        }

        echo "</div>\n";
    }
    
    // Update checkpoint
    if (!$dryRun && $processedCount > 0) {
        $lastProcessedTime = $unpaidInvoices->last()->date ?? date('Y-m-d H:i:s');
        updateGatewayVariable('razorpay', 'last_synced_at', $lastProcessedTime);
    }
    
    // Show progress summary
    $elapsedTime = time() - $startTime;
    $rate = ($processedCount > 0 && $elapsedTime > 0) ? $processedCount / $elapsedTime : 0;
    
    echo "<h3>Summary</h3>\n";
    echo "<p>✓ Synchronized: {$syncedCount} payments</p>\n";
    echo "<p>✗ Errors: {$errorCount} invoices</p>\n";
    echo "<p>Total processed: {$processedCount} invoices</p>\n";
    echo "<p>Invoices with late fees: {$lateFeeInvoices}</p>\n";
    echo "<p>Processing time: {$elapsedTime} seconds</p>\n";
    echo "<p>Processing rate: " . round($rate, 1) . " invoices/second</p>\n";
    
    if ($isCLI) {
        echo "CLI Mode: All batches processed\n";
    } else {
        echo "<p>Web Mode: Processed one batch</p>\n";
        if (($offset + count($unpaidInvoices)) < $totalUnpaidCount) {
            echo "<p><a href='?password=$syncPassword&since=" . urlencode($lastProcessedTime) . "&limit=$limit'>Continue Next Batch</a></p>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fatal Error: " . $e->getMessage() . "</p>\n";
}

// Helper functions
function fetchRazorpayDataBatch($api, $sinceTimestamp, $untilTimestamp) {
    $data = ['orders' => [], 'payments' => []];
    
    try {
        // Fetch orders in batch
        $orders = $api->order->all([
            'count' => 100,
            'from' => $sinceTimestamp,
            'to' => $untilTimestamp
        ]);
        $data['orders'] = $orders['items'] ?? [];
        
        // Fetch payments in batch
        $payments = $api->payment->all([
            'count' => 100,
            'from' => $sinceTimestamp,
            'to' => $untilTimestamp
        ]);
        $data['payments'] = $payments['items'] ?? [];
        
    } catch (Exception $e) {
        logTransaction('razorpay', "Batch fetch error: " . $e->getMessage(), "Error");
    }
    
    return $data;
}

function findMatchingOrder($orders, $invoice) {
    foreach ($orders as $order) {
        // Check by whmcs_order_id
        if (isset($order['notes']['whmcs_order_id']) && $order['notes']['whmcs_order_id'] == $invoice->id) {
            return $order;
        }
        // Check by receipt
        if (isset($order['receipt']) && $order['receipt'] == $invoice->id) {
            return $order;
        }
    }
    return null;
}

function findMatchingPayment($payments, $invoice, $order) {
    foreach ($payments as $payment) {
        if (isset($payment['order_id']) && $payment['order_id'] === $order['id']) {
            if ($payment['status'] === 'captured') {
                // Check for exact match
                if ($payment['amount'] == ($invoice->total * 100)) {
                    return $payment;
                }
                // Check for gateway fee match
                if ($payment['amount'] > ($invoice->total * 100) && 
                    $payment['amount'] <= (($invoice->total * 100) * 1.05)) {
                    return $payment;
                }
            }
        }
    }
    return null;
}

function findMatchingPaymentDirect($payments, $invoice) {
    foreach ($payments as $payment) {
        if ($payment['status'] === 'captured') {
            // Check for exact match
            if ($payment['amount'] == ($invoice->total * 100)) {
                return $payment;
            }
            // Check for gateway fee match
            if ($payment['amount'] > ($invoice->total * 100) && 
                $payment['amount'] <= (($invoice->total * 100) * 1.05)) {
                return $payment;
            }
        }
    }
    return null;
}

function calculateGatewayFee($payment, $invoice) {
    $paymentAmount = $payment['amount'] / 100;
    if ($paymentAmount > $invoice->total) {
        return $paymentAmount - $invoice->total;
    }
    return 0;
}

function updateGatewayVariable($gateway, $key, $value) {
    // Update gateway variable (implementation depends on WHMCS version)
    try {
        Capsule::table('tblpaymentgateways')
            ->where('gateway', $gateway)
            ->update([$key => $value]);
    } catch (Exception $e) {
        // Fallback: log the checkpoint
        logTransaction($gateway, "Checkpoint: {$key} = {$value}", "Info");
    }
}

echo "<hr>\n";
echo "<p><strong>Note:</strong> This tool should be run regularly to ensure payment synchronization.</p>\n";
echo "<p><strong>Security:</strong> Remove or secure this file after use in production.</p>\n";
?>