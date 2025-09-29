<?php
/**
 * Reconcile Missing Payments Script
 * 
 * This script uses the new reconciliation engine to find and sync missing payments
 * for the 6 identified unpaid invoices.
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/reconcile-missing-payments.php?password=change_me_this_at_earliest_if_using
 */

// Require WHMCS libraries
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';
require_once __DIR__ . '/rzpordermapping.php';

// Initialize WHMCS database connection
use Illuminate\Database\Capsule\Manager as Capsule;

// Security check
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Simple authentication
$syncPassword = 'change_me_this_at_earliest_if_using';
if (isset($_GET['password']) && $_GET['password'] !== $syncPassword) {
    die("<h2>Access Denied</h2><p>Please provide the correct password: ?password=your_password</p>");
}

echo "<h2>Reconcile Missing Payments</h2>\n";
echo "<p>This script uses the new reconciliation engine to find and sync missing payments.</p>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

// List of 6 target invoices from cross-check results
$targetInvoices = [
    300003260, // Amount: 13560.00, Candidate: pay_RL49eXFWS0A8DC
    300003227, // Amount: 3597.59, Candidate: pay_R19KrUGuQWDkuz  
    300003244, // Amount: 1749.00, Candidate: pay_Qs5bSplVlobRYu
    300003245, // Amount: 1749.00, Candidate: pay_Qs5bSplVlobRYu
    300003267, // Amount: 4990.00, Candidate: pay_QoZRXrgS5mTAjA
    300003264, // Amount: 4990.00, Candidate: pay_QoZRXrgS5mTAjA
    300003265, // Amount: 13990.00, Candidate: pay_RECuoPBH3y00F2
];

echo "<h3>Target Invoices for Reconciliation</h3>\n";
echo "<p>Processing " . count($targetInvoices) . " invoices...</p>\n";

$rzpOrderMapping = new RZPOrderMapping('Razorpay');
$successCount = 0;
$errorCount = 0;
$skippedCount = 0;

foreach ($targetInvoices as $invoiceId) {
    echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>\n";
    echo "<h4>Invoice #{$invoiceId}</h4>\n";
    
    // Check current invoice status
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        echo "<p style='color: red;'>❌ Invoice not found</p>\n";
        $errorCount++;
        echo "</div>\n";
        continue;
    }
    
    echo "<p><strong>Current Status:</strong> {$invoice->status}</p>\n";
    echo "<p><strong>Amount:</strong> {$invoice->total} {$invoice->currency}</p>\n";
    
    // Check if already paid
    if ($invoice->status === 'Paid') {
        echo "<p style='color: green;'>✅ Invoice already paid</p>\n";
        $skippedCount++;
        echo "</div>\n";
        continue;
    }
    
    // Attempt reconciliation
    echo "<p>Attempting reconciliation...</p>\n";
    $result = $rzpOrderMapping->reconcileMissingPayment($invoiceId, $gatewayParams);
    
    if ($result['success']) {
        echo "<p style='color: green;'>✅ Payment reconciled successfully!</p>\n";
        echo "<p><strong>Payment ID:</strong> {$result['payment_id']}</p>\n";
        echo "<p><strong>Amount:</strong> {$result['amount']}</p>\n";
        echo "<p><strong>Fees:</strong> {$result['fees']}</p>\n";
        echo "<p><strong>Date:</strong> {$result['date']}</p>\n";
        $successCount++;
    } else {
        echo "<p style='color: red;'>❌ Reconciliation failed: {$result['error']}</p>\n";
        $errorCount++;
    }
    
    echo "</div>\n";
}

echo "<h3>Reconciliation Summary</h3>\n";
echo "<p>✅ Successfully reconciled: {$successCount} payments</p>\n";
echo "<p>❌ Errors: {$errorCount} invoices</p>\n";
echo "<p>⏭️ Skipped (already paid): {$skippedCount} invoices</p>\n";
echo "<p>📊 Total processed: " . count($targetInvoices) . " invoices</p>\n";

if ($successCount > 0) {
    echo "<p style='color: green;'>🎉 Reconciliation completed! Check the invoice statuses in WHMCS.</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='cross-check-tool.php?password=$syncPassword&days=90&limit=500&detailed=1'>Run Cross-Check Tool</a></li>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword&days=90&limit=50'>Run Payment Sync Tool</a></li>\n";
echo "</ul>\n";

echo "<p><strong>Security Note:</strong> Remove this file after use in production.</p>\n";
?>
