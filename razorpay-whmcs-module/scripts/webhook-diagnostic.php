<?php
/**
 * Razorpay Webhook Diagnostic Tool
 * 
 * This tool checks the current webhook configuration and status
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/webhook-diagnostic.php?password=change_me_this_at_earliest_if_using
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

echo "<h2>Razorpay Webhook Diagnostic Tool</h2>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

echo "<h3>1. WHMCS Configuration</h3>\n";
echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>\n";

$webhookEnabled = $gatewayParams['enableWebhook'] ?? false;
echo "<tr><td>Webhook Enabled</td><td>" . ($webhookEnabled ? 'Yes' : 'No') . "</td><td>" . ($webhookEnabled ? '✅' : '❌') . "</td></tr>\n";

$webhookSecret = $gatewayParams['webhookSecret'] ?? '';
echo "<tr><td>Webhook Secret</td><td>" . (empty($webhookSecret) ? 'Not Set' : 'Set (' . strlen($webhookSecret) . ' chars)') . "</td><td>" . (empty($webhookSecret) ? '❌' : '✅') . "</td></tr>\n";

$keyId = $gatewayParams['keyId'] ?? '';
echo "<tr><td>API Key ID</td><td>" . (empty($keyId) ? 'Not Set' : 'Set') . "</td><td>" . (empty($keyId) ? '❌' : '✅') . "</td></tr>\n";

$keySecret = $gatewayParams['keySecret'] ?? '';
echo "<tr><td>API Key Secret</td><td>" . (empty($keySecret) ? 'Not Set' : 'Set') . "</td><td>" . (empty($keySecret) ? '❌' : '✅') . "</td></tr>\n";

echo "</table>\n";

// Webhook URL
$webhookUrl = $gatewayParams['systemurl'] . '/modules/gateways/razorpay/razorpay-webhook.php';
echo "<p><strong>Webhook URL:</strong> <a href='$webhookUrl' target='_blank'>$webhookUrl</a></p>\n";

// Test webhook URL accessibility
echo "<h3>2. Webhook URL Test</h3>\n";
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'method' => 'GET'
    ]
]);

$webhookResponse = @file_get_contents($webhookUrl, false, $context);
if ($webhookResponse !== false) {
    echo "<p style='color: green;'>✅ Webhook URL is accessible</p>\n";
} else {
    echo "<p style='color: red;'>❌ Webhook URL is not accessible</p>\n";
}

// Check recent webhook logs
echo "<h3>3. Recent Webhook Activity</h3>\n";
try {
    $recentLogs = Capsule::table('tblgatewaylog')
        ->where('gateway', 'razorpay')
        ->where('data', 'like', '%Webhook%')
        ->orderBy('date', 'desc')
        ->limit(10)
        ->get();

    if (count($recentLogs) > 0) {
        echo "<p>Found " . count($recentLogs) . " recent webhook log entries:</p>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
        echo "<tr><th>Date</th><th>Status</th><th>Data</th></tr>\n";
        
        foreach ($recentLogs as $log) {
            $data = json_decode($log->data, true);
            $status = $log->status;
            $date = date('Y-m-d H:i:s', strtotime($log->date));
            $dataPreview = is_array($data) ? json_encode($data) : $log->data;
            $dataPreview = strlen($dataPreview) > 100 ? substr($dataPreview, 0, 100) . '...' : $dataPreview;
            
            echo "<tr><td>$date</td><td>$status</td><td>$dataPreview</td></tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: orange;'>⚠ No recent webhook activity found</p>\n";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error checking logs: " . $e->getMessage() . "</p>\n";
}

// Check unpaid invoices
echo "<h3>4. Unpaid Invoices Status</h3>\n";
try {
    $unpaidCount = Capsule::table('tblinvoices')
        ->where('status', 'Unpaid')
        ->where('paymentmethod', 'razorpay')
        ->count();
    
    echo "<p>Unpaid Razorpay invoices: <strong>$unpaidCount</strong></p>\n";
    
    if ($unpaidCount > 0) {
        echo "<p style='color: orange;'>⚠ You have $unpaidCount unpaid invoices. Run the sync tool to check for missed payments.</p>\n";
    } else {
        echo "<p style='color: green;'>✅ No unpaid Razorpay invoices found</p>\n";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error checking invoices: " . $e->getMessage() . "</p>\n";
}

// Recommendations
echo "<h3>5. Recommendations</h3>\n";
$issues = [];

if (!$webhookEnabled) {
    $issues[] = "Enable webhook in WHMCS configuration";
}

if (empty($webhookSecret)) {
    $issues[] = "Set webhook secret in WHMCS configuration";
}

if (empty($keyId) || empty($keySecret)) {
    $issues[] = "Configure Razorpay API keys";
}

if (count($issues) > 0) {
    echo "<p style='color: red;'><strong>Issues found:</strong></p>\n";
    echo "<ul>\n";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>\n";
    }
    echo "</ul>\n";
} else {
    echo "<p style='color: green;'>✅ Configuration looks good!</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword'>Run Payment Sync Tool</a></li>\n";
echo "<li><a href='WEBHOOK_SETUP.md' target='_blank'>View Webhook Setup Guide</a></li>\n";
echo "<li>Check <a href='../../../admin/systemgatewaylog.php' target='_blank'>Gateway Logs</a> for detailed activity</li>\n";
echo "</ul>\n";
?>
