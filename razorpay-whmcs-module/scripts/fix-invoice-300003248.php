<?php
/**
 * Fix Specific Invoice #300003248
 * 
 * This script fixes the specific invoice that has gateway fee issues
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/fix-invoice-300003248.php?password=change_me_this_at_earliest_if_using
 */

// Require WHMCS libraries
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';

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

echo "<h2>Fix Invoice #300003248 Gateway Fee Issue</h2>\n";

try {
    $invoiceId = 300003248;
    
    // Get invoice details
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first();
    
    if (!$invoice) {
        die("<p style='color: red;'>Invoice #{$invoiceId} not found.</p>");
    }
    
    echo "<h3>Current Invoice Status</h3>\n";
    echo "<p><strong>Invoice ID:</strong> {$invoice->id}</p>\n";
    echo "<p><strong>Amount:</strong> {$invoice->total} {$invoice->currency}</p>\n";
    echo "<p><strong>Status:</strong> {$invoice->status}</p>\n";
    
    // Get payment transactions
    $payments = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->get();
    
    echo "<h3>Current Payments</h3>\n";
    echo "<p>Found " . count($payments) . " payment records</p>\n";
    
    foreach ($payments as $payment) {
        echo "<p>Payment ID: {$payment->transid} - Amount: {$payment->amountin} - Fee: {$payment->fees}</p>\n";
        
        // Check if this payment has a fee that should be removed
        if ($payment->fees > 0) {
            echo "<p style='color: orange;'>⚠ This payment has a fee of {$payment->fees} that should be removed</p>\n";
            
            // Update the payment to remove the fee and set correct amount
            try {
                Capsule::table('tblaccounts')
                    ->where('id', $payment->id)
                    ->update([
                        'fees' => 0,
                        'amountin' => $invoice->total // Set amount to invoice total
                    ]);
                
                echo "<p style='color: green;'>✅ Fixed payment record</p>\n";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Error fixing payment: " . $e->getMessage() . "</p>\n";
            }
        }
    }
    
    // Recalculate total payments
    $totalPayments = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->sum('amountin');
    
    $balance = $invoice->total - $totalPayments;
    
    echo "<h3>Updated Status</h3>\n";
    echo "<p><strong>Total Payments:</strong> {$totalPayments} {$invoice->currency}</p>\n";
    echo "<p><strong>Balance:</strong> {$balance} {$invoice->currency}</p>\n";
    
    if ($balance == 0) {
        echo "<p style='color: green;'>✅ Invoice balance is now correct!</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠ Invoice balance: {$balance}</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword&limit=5'>Run Payment Sync Tool</a></li>\n";
echo "<li><a href='test-razorpay-api.php?password=$syncPassword'>Test Razorpay API</a></li>\n";
echo "</ul>\n";
?>
