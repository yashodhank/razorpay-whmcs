<?php
/**
 * Razorpay Late Fee Handler Tool
 * 
 * This tool specifically handles invoices with late fees that may have been paid
 * before the late fee was added.
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/late-fee-handler.php?password=change_me_this_at_earliest_if_using
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

echo "<h2>Razorpay Late Fee Handler Tool</h2>\n";
echo "<p>This tool helps handle invoices with late fees that may have been paid before late fees were added.</p>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
} catch (Exception $e) {
    die("<p style='color: red;'>Error: Failed to initialize Razorpay API - " . $e->getMessage() . "</p>");
}

try {
    // Check if latefee column exists first
    $hasLateFeeColumn = false;
    try {
        $testQuery = Capsule::table('tblinvoices')->select('latefee')->limit(1)->get();
        $hasLateFeeColumn = true;
    } catch (Exception $e) {
        $hasLateFeeColumn = false;
    }
    
    if (!$hasLateFeeColumn) {
        echo "<p style='color: red;'>❌ Late fee column not found in database. This tool requires the latefee column to function.</p>\n";
        echo "<p>This column is available in WHMCS v7.0+ or can be added manually to older versions.</p>\n";
        exit;
    }
    
    // Get invoices with late fees
    $invoicesWithLateFees = Capsule::table('tblinvoices')
        ->where('status', 'Unpaid')
        ->where('paymentmethod', 'razorpay')
        ->where('latefee', '>', 0)
        ->get();

    echo "<h3>Found " . count($invoicesWithLateFees) . " invoices with late fees</h3>\n";

    if (count($invoicesWithLateFees) == 0) {
        echo "<p style='color: green;'>✅ No invoices with late fees found.</p>\n";
        exit;
    }

    $processedCount = 0;
    $foundPaymentsCount = 0;

    foreach ($invoicesWithLateFees as $invoice) {
        $originalAmount = $invoice->total - $invoice->latefee;
        
        echo "<div style='border: 2px solid orange; margin: 15px; padding: 15px; background-color: #fff8dc;'>\n";
        echo "<h4>Invoice #{$invoice->id} - Late Fee Case</h4>\n";
        echo "<p><strong>Original Amount:</strong> {$originalAmount} {$invoice->currency}</p>\n";
        echo "<p><strong>Late Fee:</strong> {$invoice->latefee} {$invoice->currency}</p>\n";
        echo "<p><strong>Total Amount:</strong> {$invoice->total} {$invoice->currency}</p>\n";
        echo "<p><strong>Invoice Date:</strong> " . date('Y-m-d', strtotime($invoice->date)) . "</p>\n";
        
        try {
            // Search for payments matching the original amount
            $orders = $api->order->all([
                'count' => 100,
                'from' => strtotime('-90 days') * 1000,
                'to' => time() * 1000
            ]);

            $foundPayment = false;
            $razorpayPaymentId = null;
            $paymentDetails = null;

            foreach ($orders['items'] as $order) {
                // Check by whmcs_order_id
                if (isset($order['notes']['whmcs_order_id']) && 
                    $order['notes']['whmcs_order_id'] == $invoice->id) {
                    
                    echo "<p>Found Razorpay Order: {$order['id']}</p>\n";
                    
                    // Get payments for this order
                    $payments = $api->order->fetch($order['id'])->payments();
                    
                    foreach ($payments['items'] as $payment) {
                        if ($payment['status'] === 'captured' && 
                            $payment['amount'] == ($originalAmount * 100)) {
                            
                            $razorpayPaymentId = $payment['id'];
                            $foundPayment = true;
                            $paymentDetails = $payment;
                            echo "<p style='color: green;'>✅ Found payment for original amount: {$payment['id']}</p>\n";
                            break;
                        }
                    }
                    break;
                }
                
                // Check by receipt
                if (!$foundPayment && isset($order['receipt']) && $order['receipt'] == $invoice->id) {
                    echo "<p>Found Razorpay Order by receipt: {$order['id']}</p>\n";
                    
                    $payments = $api->order->fetch($order['id'])->payments();
                    
                    foreach ($payments['items'] as $payment) {
                        if ($payment['status'] === 'captured' && 
                            $payment['amount'] == ($originalAmount * 100)) {
                            
                            $razorpayPaymentId = $payment['id'];
                            $foundPayment = true;
                            $paymentDetails = $payment;
                            echo "<p style='color: green;'>✅ Found payment for original amount: {$payment['id']}</p>\n";
                            break;
                        }
                    }
                    break;
                }
            }

            if ($foundPayment && $razorpayPaymentId) {
                $foundPaymentsCount++;
                
                // Check if payment already exists in WHMCS
                $existingPayment = Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoice->id)
                    ->where('transid', $razorpayPaymentId)
                    ->first();

                if (!$existingPayment) {
                    echo "<p style='color: blue;'>💡 <strong>Recommendation:</strong> This invoice appears to have been paid for the original amount before late fee was added.</p>\n";
                    echo "<p><strong>Payment Details:</strong></p>\n";
                    echo "<ul>\n";
                    echo "<li>Payment ID: {$paymentDetails['id']}</li>\n";
                    echo "<li>Amount: " . ($paymentDetails['amount'] / 100) . " {$paymentDetails['currency']}</li>\n";
                    echo "<li>Status: {$paymentDetails['status']}</li>\n";
                    echo "<li>Date: " . date('Y-m-d H:i:s', $paymentDetails['created_at']) . "</li>\n";
                    echo "</ul>\n";
                    
                    echo "<p><strong>Options:</strong></p>\n";
                    echo "<ol>\n";
                    echo "<li><strong>Mark as paid for original amount:</strong> This will mark the invoice as paid for the original amount, leaving the late fee as a separate charge.</li>\n";
                    echo "<li><strong>Create separate late fee invoice:</strong> Create a new invoice for just the late fee amount.</li>\n";
                    echo "<li><strong>Manual adjustment:</strong> Manually adjust the payment amount in WHMCS to include the late fee.</li>\n";
                    echo "</ol>\n";
                    
                    // Option to automatically mark as paid for original amount
                    if (isset($_GET['action']) && $_GET['action'] === 'mark_paid' && isset($_GET['invoice_id']) && $_GET['invoice_id'] == $invoice->id) {
                        // Add payment for original amount
                        addInvoicePayment(
                            $invoice->id,
                            $razorpayPaymentId,
                            $originalAmount,
                            0,
                            'razorpay'
                        );
                        
                        echo "<p style='color: green;'>✅ Invoice marked as paid for original amount. Late fee remains as separate charge.</p>\n";
                        $processedCount++;
                    } else {
                        echo "<p><a href='?password=$syncPassword&action=mark_paid&invoice_id={$invoice->id}' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Mark as Paid for Original Amount</a></p>\n";
                    }
                } else {
                    echo "<p style='color: orange;'>⚠ Payment already exists in WHMCS</p>\n";
                }
            } else {
                echo "<p style='color: red;'>❌ No payment found for original amount</p>\n";
            }

        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
        }

        echo "</div>\n";
    }

    echo "<h3>Summary</h3>\n";
    echo "<p>Invoices with late fees: " . count($invoicesWithLateFees) . "</p>\n";
    echo "<p>Found payments for original amount: {$foundPaymentsCount}</p>\n";
    echo "<p>Processed: {$processedCount}</p>\n";

} catch (Exception $e) {
    echo "<p style='color: red;'>Fatal Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Note:</strong> This tool helps identify and handle late fee cases. Always verify payments before marking invoices as paid.</p>\n";
?>
