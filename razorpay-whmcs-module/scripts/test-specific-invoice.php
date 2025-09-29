<?php
/**
 * Test Specific Invoice Payment Sync
 * 
 * This script tests payment sync for a specific invoice
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/test-specific-invoice.php?password=change_me_this_at_earliest_if_using&invoice_id=300003248
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
echo "<h2>Test Payment Sync for Invoice #{$invoiceId}</h2>\n";

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
    echo "<p><strong>Date:</strong> {$invoice->date}</p>\n";
    
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
            echo "<p style='color: green;'>✅ Found order by receipt: {$order['id']}</p>\n";
            echo "<p><strong>Order Details:</strong></p>\n";
            echo "<ul>\n";
            echo "<li>Order ID: {$order['id']}</li>\n";
            echo "<li>Receipt: {$order['receipt']}</li>\n";
            echo "<li>Amount: " . ($order['amount'] / 100) . " {$order['currency']}</li>\n";
            echo "<li>Status: {$order['status']}</li>\n";
            echo "<li>Created: " . date('Y-m-d H:i:s', $order['created_at']) . "</li>\n";
            echo "</ul>\n";
            
            $foundOrder = $order;
            
            // Get payments for this order
            echo "<h4>Payments for this Order</h4>\n";
            $payments = $api->order->fetch($order['id'])->payments();
            echo "<p>Found " . count($payments['items']) . " payments</p>\n";
            
            if (count($payments['items']) > 0) {
                echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
                echo "<tr><th>Payment ID</th><th>Amount</th><th>Status</th><th>Method</th><th>Created</th><th>Match</th></tr>\n";
                
                foreach ($payments['items'] as $payment) {
                    $createdDate = date('Y-m-d H:i:s', $payment['created_at']);
                    $amount = $payment['amount'] / 100;
                    $exactMatch = $payment['amount'] == ($invoice->total * 100);
                    $gatewayFeeMatch = $payment['amount'] > ($invoice->total * 100) && 
                                     $payment['amount'] <= (($invoice->total * 100) * 1.05);
                    
                    $matchStatus = '';
                    if ($exactMatch) {
                        $matchStatus = '<span style="color: green;">Exact Match</span>';
                    } elseif ($gatewayFeeMatch) {
                        $gatewayFee = $amount - $invoice->total;
                        $matchStatus = '<span style="color: blue;">Gateway Fee Match (+' . $gatewayFee . ')</span>';
                    } else {
                        $matchStatus = '<span style="color: red;">No Match</span>';
                    }
                    
                    echo "<tr>";
                    echo "<td>{$payment['id']}</td>";
                    echo "<td>{$amount} {$payment['currency']}</td>";
                    echo "<td>{$payment['status']}</td>";
                    echo "<td>" . ($payment['method'] ?? 'N/A') . "</td>";
                    echo "<td>{$createdDate}</td>";
                    echo "<td>{$matchStatus}</td>";
                    echo "</tr>\n";
                    
                    if ($payment['status'] === 'captured' && ($exactMatch || $gatewayFeeMatch)) {
                        $razorpayPaymentId = $payment['id'];
                        $foundPayment = true;
                    }
                }
                echo "</table>\n";
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
            echo "<p style='color: blue;'>💡 Payment can be synchronized to WHMCS</p>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword'>Run Full Payment Sync Tool</a></li>\n";
echo "<li><a href='test-razorpay-api.php?password=$syncPassword'>Test Razorpay API</a></li>\n";
echo "</ul>\n";
?>
