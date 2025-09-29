<?php
/**
 * Sync Single Invoice Payment
 * 
 * This script syncs payment for a single invoice to avoid timeout
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/sync-single-invoice.php?password=change_me_this_at_earliest_if_using&invoice_id=300003248
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

$invoiceId = $_GET['invoice_id'] ?? '300003248';
echo "<h2>Sync Payment for Invoice #{$invoiceId}</h2>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
    
    // Get invoice details
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->where('paymentmethod', 'razorpay')
        ->first();
    
    if (!$invoice) {
        die("<p style='color: red;'>Invoice #{$invoiceId} not found or not using Razorpay payment method.</p>");
    }
    
    echo "<h3>Invoice Details</h3>\n";
    echo "<p><strong>Invoice ID:</strong> {$invoice->id}</p>\n";
    echo "<p><strong>Amount:</strong> {$invoice->total} {$invoice->currency}</p>\n";
    echo "<p><strong>Status:</strong> {$invoice->status}</p>\n";
    
    // Search for orders
    echo "<h3>Searching Razorpay Orders</h3>\n";
    $orders = $api->order->all(['count' => 100]);
    echo "<p>Found " . count($orders['items']) . " orders</p>\n";
    
    $foundOrder = null;
    $foundPayment = false;
    $razorpayPaymentId = null;
    
    foreach ($orders['items'] as $order) {
        // Check by receipt number
        if (isset($order['receipt']) && $order['receipt'] == $invoiceId) {
            echo "<p style='color: green;'>✅ Found order: {$order['id']}</p>\n";
            echo "<p><strong>Order Status:</strong> {$order['status']}</p>\n";
            
            $foundOrder = $order;
            
            // Get payments for this order
            $payments = $api->order->fetch($order['id'])->payments();
            echo "<p>Found " . count($payments['items']) . " payments</p>\n";
            
            foreach ($payments['items'] as $payment) {
                $amount = $payment['amount'] / 100;
                $exactMatch = $payment['amount'] == ($invoice->total * 100);
                $gatewayFeeMatch = $payment['amount'] > ($invoice->total * 100) && 
                                 $payment['amount'] <= (($invoice->total * 100) * 1.05);
                
                echo "<p><strong>Payment:</strong> {$payment['id']} - {$amount} {$payment['currency']} - {$payment['status']}</p>\n";
                
                if ($exactMatch) {
                    echo "<p style='color: green;'>✓ Exact amount match</p>\n";
                } elseif ($gatewayFeeMatch) {
                    $gatewayFee = $amount - $invoice->total;
                    echo "<p style='color: blue;'>✓ Gateway fee match (+{$gatewayFee})</p>\n";
                } else {
                    echo "<p style='color: red;'>✗ No match</p>\n";
                }
                
                if ($payment['status'] === 'captured' && ($exactMatch || $gatewayFeeMatch)) {
                    $razorpayPaymentId = $payment['id'];
                    $foundPayment = true;
                }
            }
            break;
        }
    }
    
    if (!$foundOrder) {
        echo "<p style='color: red;'>❌ No order found for invoice #{$invoiceId}</p>\n";
    } elseif (!$foundPayment) {
        echo "<p style='color: orange;'>⚠ Order found but no captured payment found</p>\n";
    } else {
        echo "<p style='color: green;'>✅ Found captured payment: {$razorpayPaymentId}</p>\n";
        
        // Check if payment already exists in WHMCS
        $existingPayment = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('transid', $razorpayPaymentId)
            ->first();
        
        if ($existingPayment) {
            echo "<p style='color: orange;'>⚠ Payment already exists in WHMCS</p>\n";
        } else {
            // Calculate gateway fee if payment amount is higher than invoice amount
            $actualPaymentAmount = $invoice->total;
            $gatewayFee = 0;
            
            // Get the actual payment amount from Razorpay
            try {
                $paymentDetails = $api->payment->fetch($razorpayPaymentId);
                $actualPaymentAmount = $paymentDetails['amount'] / 100;
                
                if ($actualPaymentAmount > $invoice->total) {
                    $gatewayFee = $actualPaymentAmount - $invoice->total;
                    echo "<p><strong>Gateway fee detected:</strong> {$gatewayFee} (Total payment: {$actualPaymentAmount})</p>\n";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>Could not fetch payment details: " . $e->getMessage() . "</p>\n";
            }
            
            // Add the payment to WHMCS
            try {
                // Record only the invoice amount as payment (gateway fee is separate)
                addInvoicePayment(
                    $invoice->id,
                    $razorpayPaymentId,
                    $invoice->total, // Record only the invoice amount
                    0, // No gateway fee recorded as client credit
                    'razorpay'
                );
                
                echo "<p style='color: green;'>✅ Payment synchronized successfully!</p>\n";
                if ($gatewayFee > 0) {
                    echo "<p style='color: blue;'>💡 Gateway fee of {$gatewayFee} is paid directly to Razorpay (not recorded as client credit)</p>\n";
                }
                
                // Verify the invoice status
                $updatedInvoice = Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->first();
                
                echo "<p><strong>Updated Invoice Status:</strong> {$updatedInvoice->status}</p>\n";
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Error adding payment: " . $e->getMessage() . "</p>\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword&limit=5'>Run Batch Sync (5 invoices)</a></li>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword&limit=10'>Run Batch Sync (10 invoices)</a></li>\n";
echo "<li><a href='test-razorpay-api.php?password=$syncPassword'>Test Razorpay API</a></li>\n";
echo "</ul>\n";
?>
