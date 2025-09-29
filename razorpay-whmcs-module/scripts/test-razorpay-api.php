<?php
/**
 * Test Razorpay API Connection
 * 
 * This script tests the Razorpay API connection and shows recent orders
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/test-razorpay-api.php?password=change_me_this_at_earliest_if_using
 */

// Require WHMCS libraries
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
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

echo "<h2>Razorpay API Connection Test</h2>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

echo "<h3>Gateway Configuration</h3>\n";
echo "<p><strong>Key ID:</strong> " . substr($gatewayParams['keyId'], 0, 8) . "...</p>\n";
echo "<p><strong>Environment:</strong> " . ($gatewayParams['environment'] ?? 'Not set') . "</p>\n";

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
    echo "<p style='color: green;'>✅ API connection successful</p>\n";
    
    // Test orders API
    echo "<h3>Recent Orders (Last 10)</h3>\n";
    $orders = $api->order->all(['count' => 10]);
    
    echo "<p>Found " . count($orders['items']) . " orders</p>\n";
    
    if (count($orders['items']) > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
        echo "<tr><th>Order ID</th><th>Receipt</th><th>Amount</th><th>Status</th><th>Created</th><th>Notes</th></tr>\n";
        
        foreach ($orders['items'] as $order) {
            $createdDate = date('Y-m-d H:i:s', $order['created_at']);
            $amount = $order['amount'] / 100;
            $notes = isset($order['notes']) ? json_encode($order['notes']) : 'None';
            
            echo "<tr>";
            echo "<td>{$order['id']}</td>";
            echo "<td>{$order['receipt']}</td>";
            echo "<td>{$amount} {$order['currency']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "<td>{$createdDate}</td>";
            echo "<td>{$notes}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: orange;'>⚠ No orders found</p>\n";
    }
    
    // Test payments API
    echo "<h3>Recent Payments (Last 10)</h3>\n";
    $payments = $api->payment->all(['count' => 10]);
    
    echo "<p>Found " . count($payments['items']) . " payments</p>\n";
    
    if (count($payments['items']) > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
        echo "<tr><th>Payment ID</th><th>Order ID</th><th>Amount</th><th>Status</th><th>Created</th><th>Method</th></tr>\n";
        
        foreach ($payments['items'] as $payment) {
            $createdDate = date('Y-m-d H:i:s', $payment['created_at']);
            $amount = $payment['amount'] / 100;
            $orderId = isset($payment['order_id']) ? $payment['order_id'] : 'N/A';
            $method = isset($payment['method']) ? $payment['method'] : 'N/A';
            
            echo "<tr>";
            echo "<td>{$payment['id']}</td>";
            echo "<td>{$orderId}</td>";
            echo "<td>{$amount} {$payment['currency']}</td>";
            echo "<td>{$payment['status']}</td>";
            echo "<td>{$createdDate}</td>";
            echo "<td>{$method}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: orange;'>⚠ No payments found</p>\n";
    }
    
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
echo "</ul>\n";

echo "<p><strong>Security Note:</strong> Remove this file after use in production.</p>\n";
?>
