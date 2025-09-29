<?php
/**
 * Add Late Fee Column to WHMCS Database
 * 
 * This script adds the latefee column to tblinvoices if it doesn't exist.
 * This is required for late fee handling functionality.
 * 
 * USAGE:
 * https://my.securiace.com/modules/gateways/razorpay/add-latefee-column.php?password=change_me_this_at_earliest_if_using
 */

// Require WHMCS libraries
require_once __DIR__ . '/../../../../init.php';

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

echo "<h2>Add Late Fee Column to WHMCS Database</h2>\n";

try {
    // Check if latefee column already exists
    $columns = Capsule::select("SHOW COLUMNS FROM tblinvoices LIKE 'latefee'");
    
    if (count($columns) > 0) {
        echo "<p style='color: green;'>✅ Late fee column already exists in tblinvoices table.</p>\n";
        echo "<p>You can now use the late fee handling features.</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠ Late fee column not found. Adding it now...</p>\n";
        
        // Add the latefee column
        $sql = "ALTER TABLE `tblinvoices` ADD `latefee` DECIMAL(10,2) NOT NULL DEFAULT '0.00' AFTER `total`";
        Capsule::statement($sql);
        
        echo "<p style='color: green;'>✅ Late fee column added successfully!</p>\n";
        echo "<p>The column has been added with the following properties:</p>\n";
        echo "<ul>\n";
        echo "<li>Column name: latefee</li>\n";
        echo "<li>Type: DECIMAL(10,2)</li>\n";
        echo "<li>Default value: 0.00</li>\n";
        echo "<li>Position: After 'total' column</li>\n";
        echo "</ul>\n";
        
        echo "<p style='color: blue;'>💡 <strong>Note:</strong> This column is used to store late fees added to invoices.</p>\n";
        echo "<p>You can now use the late fee handling features in the sync tools.</p>\n";
    }
    
    // Verify the column was added correctly
    $columns = Capsule::select("SHOW COLUMNS FROM tblinvoices LIKE 'latefee'");
    if (count($columns) > 0) {
        $column = $columns[0];
        echo "<h3>Column Details</h3>\n";
        echo "<table border='1' cellpadding='5' cellspacing='0'>\n";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
        echo "<tr>";
        echo "<td>{$column->Field}</td>";
        echo "<td>{$column->Type}</td>";
        echo "<td>{$column->Null}</td>";
        echo "<td>{$column->Key}</td>";
        echo "<td>{$column->Default}</td>";
        echo "<td>{$column->Extra}</td>";
        echo "</tr>\n";
        echo "</table>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
    echo "<p>This might be due to insufficient database permissions or the column already exists with a different structure.</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='sync-payments.php?password=$syncPassword'>Run Payment Sync Tool</a></li>\n";
echo "<li><a href='late-fee-handler.php?password=$syncPassword'>Run Late Fee Handler</a></li>\n";
echo "<li><a href='webhook-diagnostic.php?password=$syncPassword'>Run Webhook Diagnostic</a></li>\n";
echo "</ul>\n";

echo "<p><strong>Security Note:</strong> Remove this file after use in production.</p>\n";
?>
