<?php
/**
 * Razorpay-WHMCS Cross-Check Tool
 * 
 * This tool performs comprehensive cross-checking between Razorpay payments 
 * and WHMCS invoice statuses to identify synchronization issues.
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/cross-check-tool.php?password=change_me_this_at_earliest_if_using
 * 
 * PARAMETERS:
 * - days: Number of days to check (default: 30)
 * - limit: Maximum payments to process (default: 100)
 * - detailed: Show detailed comparison (default: false)
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

// Simple authentication
$syncPassword = 'change_me_this_at_earliest_if_using';
if (isset($_GET['password']) && $_GET['password'] !== $syncPassword) {
    die("<h2>Access Denied</h2><p>Please provide the correct password: ?password=your_password</p>");
}

// Parse parameters - support both CLI and web
$isCLI = php_sapi_name() === 'cli';
if ($isCLI) {
    $options = getopt('', ['days:', 'limit:', 'detailed', 'password:']);
    $days = isset($options['days']) ? (int)$options['days'] : 30;
    $limit = isset($options['limit']) ? (int)$options['limit'] : 100;
    $detailed = isset($options['detailed']) ? true : false;
} else {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $detailed = isset($_GET['detailed']) ? true : false;
}

echo "<h2>Razorpay-WHMCS Cross-Check Tool</h2>\n";
echo "<p>This tool performs comprehensive cross-checking between Razorpay payments and WHMCS invoice statuses.</p>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

echo "<h3>Analysis Parameters</h3>\n";
echo "<p><strong>Analysis Period:</strong> Last {$days} days</p>\n";
echo "<p><strong>Payment Limit:</strong> {$limit} payments</p>\n";
echo "<p><strong>Detailed Mode:</strong> " . ($detailed ? 'Enabled' : 'Disabled') . "</p>\n";

echo "<h3>Gateway Configuration</h3>\n";
echo "<p><strong>Key ID:</strong> " . substr($gatewayParams['keyId'], 0, 8) . "...</p>\n";
echo "<p><strong>Environment:</strong> " . ($gatewayParams['environment'] ?? 'Not set') . "</p>\n";

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
    echo "<p style='color: green;'>✅ API connection successful</p>\n";
    
    // Calculate date range - ensure valid timestamp range for Razorpay API
    $fromDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $toDate = date('Y-m-d H:i:s');
    
    // Ensure timestamps are within Razorpay's valid range (in seconds, not milliseconds)
    $fromTimestamp = max(strtotime($fromDate), 946684800); // Jan 1, 2000
    $toTimestamp = min(strtotime($toDate), 4765046400); // Dec 31, 2120
    
    echo "<h3>Fetching Razorpay Data</h3>\n";
    echo "<p>Fetching payments from {$fromDate} to {$toDate}...</p>\n";
    
    // Fetch all captured payments from Razorpay
    $allPayments = [];
    $offset = 0;
    $count = 100; // Fetch in batches of 100
    
    do {
        $payments = $api->payment->all([
            'count' => $count,
            'from' => $fromTimestamp,
            'to' => $toTimestamp,
            'skip' => $offset
        ]);
        
        if (empty($payments['items'])) {
            break;
        }
        
        // Filter only captured payments
        foreach ($payments['items'] as $payment) {
            if ($payment['status'] === 'captured') {
                $allPayments[] = $payment;
            }
        }
        
        $offset += $count;
        
        // Limit total payments processed
        if (count($allPayments) >= $limit) {
            $allPayments = array_slice($allPayments, 0, $limit);
            break;
        }
        
    } while (count($payments['items']) === $count);
    
    echo "<p style='color: green;'>✅ Found " . count($allPayments) . " captured payments</p>\n";
    
    if (count($allPayments) === 0) {
        echo "<p style='color: orange;'>⚠ No captured payments found in the specified period.</p>\n";
        exit;
    }
    
    // Get WHMCS invoices for comparison
    echo "<h3>Fetching WHMCS Invoice Data</h3>\n";
    
    $whmcsInvoices = Capsule::table('tblinvoices')
        ->where('paymentmethod', 'razorpay')
        ->where('date', '>=', $fromDate)
        ->where('date', '<=', $toDate)
        ->get();
    
    echo "<p>Found " . count($whmcsInvoices) . " WHMCS Razorpay invoices in the same period</p>\n";
    
    // Cross-check analysis
    echo "<h3>Cross-Check Analysis</h3>\n";
    
    $analysis = performCrossCheck($allPayments, $whmcsInvoices, $gatewayParams, $api);
    
    // Display summary
    displayAnalysisSummary($analysis);
    
    if ($detailed) {
        displayDetailedAnalysis($analysis);
    }
    
    // Recommendations
    displayRecommendations($analysis);
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ API Error: " . $e->getMessage() . "</p>\n";
    echo "<p>This might be due to:</p>\n";
    echo "<ul>\n";
    echo "<li>Invalid API keys</li>\n";
    echo "<li>Network connectivity issues</li>\n";
    echo "<li>Razorpay API downtime</li>\n";
    echo "</ul>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword'>Run Payment Sync Tool</a></li>\n";
echo "<li><a href='webhook-diagnostic.php?password=$syncPassword'>Run Webhook Diagnostic</a></li>\n";
echo "<li><a href='?password=$syncPassword&days=$days&limit=$limit&detailed=1'>View Detailed Analysis</a></li>\n";
echo "</ul>\n";

echo "<p><strong>Security Note:</strong> Remove this file after use in production.</p>\n";

// Helper functions
function performCrossCheck($razorpayPayments, $whmcsInvoices, $gatewayParams, $api) {
    $analysis = [
        'total_razorpay_payments' => count($razorpayPayments),
        'total_whmcs_invoices' => count($whmcsInvoices),
        'matched_payments' => 0,
        'unmatched_payments' => [],
        'unpaid_whmcs_invoices' => [],
        'discrepancies' => [],
        'potential_sync_issues' => [],
        'amount_discrepancies' => [],
        'status_discrepancies' => []
    ];
    
    // Create lookup arrays for efficient matching
    $whmcsByAmount = [];
    $whmcsByReceipt = [];
    
    foreach ($whmcsInvoices as $invoice) {
        $whmcsByAmount[round($invoice->total, 2)][] = $invoice;
        $whmcsByReceipt[$invoice->id][] = $invoice;
    }
    
    // Check each Razorpay payment
    foreach ($razorpayPayments as $payment) {
        $paymentAmount = round($payment['amount'] / 100, 2);
        $paymentId = $payment['id'];
        $orderId = $payment['order_id'] ?? null;
        $matched = false;
        
        // Try to match by order ID first
        if ($orderId) {
            try {
                $order = $api->order->fetch($orderId);
                if (isset($order['receipt'])) {
                    $receipt = $order['receipt'];
                    if (isset($whmcsByReceipt[$receipt])) {
                        foreach ($whmcsByReceipt[$receipt] as $invoice) {
                            if (round($invoice->total, 2) === $paymentAmount) {
                                $analysis['matched_payments']++;
                                $matched = true;
                                
                                // Check for discrepancies
                                checkForDiscrepancies($payment, $invoice, $analysis);
                                break;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Order fetch failed, continue with other matching methods
            }
        }
        
        // If not matched by order, try by amount
        if (!$matched && isset($whmcsByAmount[$paymentAmount])) {
            foreach ($whmcsByAmount[$paymentAmount] as $invoice) {
                // Check if this invoice is already paid
                $existingPayments = Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoice->id)
                    ->get();
                
                if (count($existingPayments) > 0) {
                    $analysis['matched_payments']++;
                    $matched = true;
                    checkForDiscrepancies($payment, $invoice, $analysis);
                    break;
                }
            }
        }
        
        // If still not matched, it's an unmatched payment
        if (!$matched) {
            $analysis['unmatched_payments'][] = [
                'payment_id' => $paymentId,
                'amount' => $paymentAmount,
                'currency' => $payment['currency'],
                'created_at' => date('Y-m-d H:i:s', $payment['created_at']),
                'order_id' => $orderId,
                'method' => $payment['method'] ?? 'Unknown'
            ];
        }
    }
    
    // Check for unpaid WHMCS invoices that might have payments
    foreach ($whmcsInvoices as $invoice) {
        if ($invoice->status !== 'Paid') {
            $existingPayments = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoice->id)
                ->get();
            
            if (count($existingPayments) === 0) {
                $analysis['unpaid_whmcs_invoices'][] = [
                    'invoice_id' => $invoice->id,
                    'amount' => $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => $invoice->status,
                    'date' => $invoice->date,
                    'client_id' => $invoice->userid
                ];
            }
        }
    }
    
    return $analysis;
}

function checkForDiscrepancies($payment, $invoice, &$analysis) {
    $paymentAmount = round($payment['amount'] / 100, 2);
    $invoiceAmount = round($invoice->total, 2);
    
    // Check amount discrepancies
    if ($paymentAmount !== $invoiceAmount) {
        $analysis['amount_discrepancies'][] = [
            'payment_id' => $payment['id'],
            'invoice_id' => $invoice->id,
            'payment_amount' => $paymentAmount,
            'invoice_amount' => $invoiceAmount,
            'difference' => $paymentAmount - $invoiceAmount
        ];
    }
    
    // Check if invoice is marked as paid
    if ($invoice->status !== 'Paid') {
        $analysis['status_discrepancies'][] = [
            'payment_id' => $payment['id'],
            'invoice_id' => $invoice->id,
            'payment_amount' => $paymentAmount,
            'invoice_status' => $invoice->status,
            'issue' => 'Payment exists but invoice not marked as paid'
        ];
    }
}

function displayAnalysisSummary($analysis) {
    echo "<div style='background-color: #f0f8ff; padding: 15px; margin: 10px 0; border-left: 4px solid #007cba;'>\n";
    echo "<h4>📊 Analysis Summary</h4>\n";
    echo "<p><strong>Total Razorpay Payments:</strong> {$analysis['total_razorpay_payments']}</p>\n";
    echo "<p><strong>Total WHMCS Invoices:</strong> {$analysis['total_whmcs_invoices']}</p>\n";
    echo "<p><strong>Matched Payments:</strong> {$analysis['matched_payments']}</p>\n";
    echo "<p><strong>Unmatched Payments:</strong> " . count($analysis['unmatched_payments']) . "</p>\n";
    echo "<p><strong>Unpaid WHMCS Invoices:</strong> " . count($analysis['unpaid_whmcs_invoices']) . "</p>\n";
    echo "<p><strong>Amount Discrepancies:</strong> " . count($analysis['amount_discrepancies']) . "</p>\n";
    echo "<p><strong>Status Discrepancies:</strong> " . count($analysis['status_discrepancies']) . "</p>\n";
    echo "</div>\n";
}

function displayDetailedAnalysis($analysis) {
    // Unmatched payments
    if (!empty($analysis['unmatched_payments'])) {
        echo "<h4>🔍 Unmatched Razorpay Payments</h4>\n";
        echo "<p style='color: orange;'>These payments exist in Razorpay but don't have corresponding WHMCS records:</p>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>\n";
        echo "<tr><th>Payment ID</th><th>Amount</th><th>Currency</th><th>Created</th><th>Order ID</th><th>Method</th><th>Action</th></tr>\n";
        
        foreach ($analysis['unmatched_payments'] as $payment) {
            echo "<tr>";
            echo "<td>{$payment['payment_id']}</td>";
            echo "<td>{$payment['amount']}</td>";
            echo "<td>{$payment['currency']}</td>";
            echo "<td>{$payment['created_at']}</td>";
            echo "<td>{$payment['order_id']}</td>";
            echo "<td>{$payment['method']}</td>";
            echo "<td><a href='sync-single-invoice.php?password=change_me_this_at_earliest_if_using&payment_id={$payment['payment_id']}' style='color: blue;'>Sync Payment</a></td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Unpaid WHMCS invoices
    if (!empty($analysis['unpaid_whmcs_invoices'])) {
        echo "<h4>📋 Unpaid WHMCS Invoices</h4>\n";
        echo "<p style='color: orange;'>These WHMCS invoices are marked as unpaid but might have payments in Razorpay:</p>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>\n";
        echo "<tr><th>Invoice ID</th><th>Amount</th><th>Currency</th><th>Status</th><th>Date</th><th>Client ID</th><th>Action</th></tr>\n";
        
        foreach ($analysis['unpaid_whmcs_invoices'] as $invoice) {
            echo "<tr>";
            echo "<td>{$invoice['invoice_id']}</td>";
            echo "<td>{$invoice['amount']}</td>";
            echo "<td>{$invoice['currency']}</td>";
            echo "<td>{$invoice['status']}</td>";
            echo "<td>{$invoice['date']}</td>";
            echo "<td>{$invoice['client_id']}</td>";
            echo "<td><a href='sync-single-invoice.php?password=change_me_this_at_earliest_if_using&invoice_id={$invoice['invoice_id']}' style='color: blue;'>Check Payment</a></td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Amount discrepancies
    if (!empty($analysis['amount_discrepancies'])) {
        echo "<h4>💰 Amount Discrepancies</h4>\n";
        echo "<p style='color: red;'>These payments have amount mismatches with their corresponding invoices:</p>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>\n";
        echo "<tr><th>Payment ID</th><th>Invoice ID</th><th>Payment Amount</th><th>Invoice Amount</th><th>Difference</th></tr>\n";
        
        foreach ($analysis['amount_discrepancies'] as $discrepancy) {
            $diffColor = $discrepancy['difference'] > 0 ? 'green' : 'red';
            echo "<tr>";
            echo "<td>{$discrepancy['payment_id']}</td>";
            echo "<td>{$discrepancy['invoice_id']}</td>";
            echo "<td>{$discrepancy['payment_amount']}</td>";
            echo "<td>{$discrepancy['invoice_amount']}</td>";
            echo "<td style='color: {$diffColor};'>{$discrepancy['difference']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Status discrepancies
    if (!empty($analysis['status_discrepancies'])) {
        echo "<h4>⚠️ Status Discrepancies</h4>\n";
        echo "<p style='color: red;'>These payments exist but their invoices are not marked as paid:</p>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='margin: 10px 0;'>\n";
        echo "<tr><th>Payment ID</th><th>Invoice ID</th><th>Payment Amount</th><th>Invoice Status</th><th>Issue</th><th>Action</th></tr>\n";
        
        foreach ($analysis['status_discrepancies'] as $discrepancy) {
            echo "<tr>";
            echo "<td>{$discrepancy['payment_id']}</td>";
            echo "<td>{$discrepancy['invoice_id']}</td>";
            echo "<td>{$discrepancy['payment_amount']}</td>";
            echo "<td>{$discrepancy['invoice_status']}</td>";
            echo "<td>{$discrepancy['issue']}</td>";
            echo "<td><a href='sync-single-invoice.php?password=change_me_this_at_earliest_if_using&invoice_id={$discrepancy['invoice_id']}' style='color: blue;'>Fix Status</a></td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
}

function displayRecommendations($analysis) {
    echo "<h4>💡 Recommendations</h4>\n";
    
    $recommendations = [];
    
    if (count($analysis['unmatched_payments']) > 0) {
        $recommendations[] = "🔍 " . count($analysis['unmatched_payments']) . " payments in Razorpay don't have corresponding WHMCS records. Run the sync tool to resolve these.";
    }
    
    if (count($analysis['unpaid_whmcs_invoices']) > 0) {
        $recommendations[] = "📋 " . count($analysis['unpaid_whmcs_invoices']) . " WHMCS invoices are marked as unpaid but might have payments. Check these manually.";
    }
    
    if (count($analysis['amount_discrepancies']) > 0) {
        $recommendations[] = "💰 " . count($analysis['amount_discrepancies']) . " payments have amount mismatches. Review these for gateway fees or calculation errors.";
    }
    
    if (count($analysis['status_discrepancies']) > 0) {
        $recommendations[] = "⚠️ " . count($analysis['status_discrepancies']) . " payments exist but invoices aren't marked as paid. These need immediate attention.";
    }
    
    if (empty($recommendations)) {
        $recommendations[] = "✅ All payments and invoices are properly synchronized! No issues found.";
    }
    
    echo "<ul>\n";
    foreach ($recommendations as $recommendation) {
        echo "<li>{$recommendation}</li>\n";
    }
    echo "</ul>\n";
    
    // Overall health score
    $totalIssues = count($analysis['unmatched_payments']) + count($analysis['unpaid_whmcs_invoices']) + 
                   count($analysis['amount_discrepancies']) + count($analysis['status_discrepancies']);
    
    $healthScore = max(0, 100 - ($totalIssues * 10));
    
    $healthColor = $healthScore >= 90 ? 'green' : ($healthScore >= 70 ? 'orange' : 'red');
    $healthStatus = $healthScore >= 90 ? 'Excellent' : ($healthScore >= 70 ? 'Good' : 'Needs Attention');
    
    echo "<div style='background-color: #f9f9f9; padding: 10px; margin: 10px 0; border-radius: 5px;'>\n";
    echo "<p><strong>Overall Health Score: <span style='color: {$healthColor};'>{$healthScore}/100 ({$healthStatus})</span></strong></p>\n";
    echo "<p>This score is based on the number of discrepancies found. Lower scores indicate more synchronization issues.</p>\n";
    echo "</div>\n";
}
?>
