<?php
/**
 * Fix Gateway Fee Issues
 * 
 * This script fixes invoices where gateway fees were incorrectly recorded as client credits
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/fix-gateway-fees.php?password=change_me_this_at_earliest_if_using
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

echo "<h2>Fix Gateway Fee Issues</h2>\n";
echo "<p>This tool fixes invoices where Razorpay gateway fees were incorrectly recorded as client credits.</p>\n";

// Get gateway configuration
$gatewayParams = getGatewayVariables('razorpay');

// Validate gateway configuration
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    die("<p style='color: red;'>Error: Razorpay gateway not properly configured. Please check your API keys.</p>");
}

try {
    $api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
    
    // Find paid Razorpay invoices
    $paidInvoices = Capsule::table('tblinvoices')
        ->where('status', 'Paid')
        ->where('paymentmethod', 'razorpay')
        ->get();
    
    echo "<h3>Found " . count($paidInvoices) . " paid Razorpay invoices</h3>\n";
    
    if (count($paidInvoices) == 0) {
        echo "<p style='color: green;'>✅ No paid invoices found!</p>\n";
        exit;
    }
    
    $fixedCount = 0;
    $errorCount = 0;
    $problematicCount = 0;
    
    foreach ($paidInvoices as $invoice) {
        // Calculate total payments for this invoice
        $totalPayments = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoice->id)
            ->sum('amount');
        
        $balance = $invoice->total - $totalPayments;
        
        // Only process invoices with overpayments (negative balance)
        if ($balance >= 0) {
            continue; // Skip invoices that are correctly balanced
        }
        
        $problematicCount++;
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>\n";
        echo "<h4>Invoice #{$invoice->id}</h4>\n";
        echo "<p><strong>Amount:</strong> {$invoice->total} {$invoice->currency}</p>\n";
        echo "<p><strong>Total Payments:</strong> {$totalPayments} {$invoice->currency}</p>\n";
        echo "<p><strong>Balance:</strong> {$balance} {$invoice->currency} <span style='color: red;'>(Overpaid)</span></p>\n";
        
        // Get payment transactions for this invoice
        $payments = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoice->id)
            ->where('paymentmethod', 'razorpay')
            ->get();
        
        echo "<p><strong>Payments found:</strong> " . count($payments) . "</p>\n";
        
        foreach ($payments as $payment) {
            echo "<p>Payment ID: {$payment->transid} - Amount: {$payment->amount} - Fee: {$payment->fees}</p>\n";
            
            // Check if this payment has a fee that should be removed
            if ($payment->fees > 0) {
                echo "<p style='color: orange;'>⚠ This payment has a fee of {$payment->fees} that should be removed</p>\n";
                
                // Update the payment to remove the fee
                try {
                    Capsule::table('tblaccounts')
                        ->where('id', $payment->id)
                        ->update([
                            'fees' => 0,
                            'amount' => $invoice->total // Set amount to invoice total
                        ]);
                    
                    echo "<p style='color: green;'>✅ Fixed payment record</p>\n";
                    $fixedCount++;
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ Error fixing payment: " . $e->getMessage() . "</p>\n";
                    $errorCount++;
                }
            }
        }
        
        // Recalculate invoice balance
        try {
            // Get updated payments
            $updatedPayments = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoice->id)
                ->sum('amount');
            
            $newBalance = $invoice->total - $updatedPayments;
            
            echo "<p><strong>Updated Balance:</strong> {$newBalance} {$invoice->currency}</p>\n";
            
            if ($newBalance == 0) {
                echo "<p style='color: green;'>✅ Invoice balance corrected!</p>\n";
            } else {
                echo "<p style='color: orange;'>⚠ Invoice balance: {$newBalance}</p>\n";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error calculating balance: " . $e->getMessage() . "</p>\n";
            $errorCount++;
        }
        
        echo "</div>\n";
    }
    
    echo "<h3>Summary</h3>\n";
    echo "<p>✅ Fixed: {$fixedCount} payments</p>\n";
    echo "<p>❌ Errors: {$errorCount} issues</p>\n";
    echo "<p>🔍 Problematic invoices found: {$problematicCount}</p>\n";
    echo "<p>📊 Total paid invoices checked: " . count($paidInvoices) . "</p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword&limit=5'>Run Payment Sync Tool</a></li>\n";
echo "<li><a href='test-razorpay-api.php?password=$syncPassword'>Test Razorpay API</a></li>\n";
echo "</ul>\n";

echo "<p><strong>Security Note:</strong> Remove this file after use in production.</p>\n";
?>
