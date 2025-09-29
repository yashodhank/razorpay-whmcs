<?php
/**
 * Razorpay WHMCS Gateway Module Uninstaller
 * 
 * This script helps remove the Razorpay gateway module from your WHMCS installation.
 * Run this script from your WHMCS root directory.
 * 
 * Usage: php uninstall.php
 * 
 * WARNING: This will remove all Razorpay module files and data!
 */

// Prevent direct access
if (!defined('WHMCS')) {
    die('This script must be run from within WHMCS');
}

// Check if we're in the right directory
if (!file_exists('init.php')) {
    die('Error: Please run this script from your WHMCS root directory');
}

// Include WHMCS
require_once 'init.php';

echo "Razorpay WHMCS Gateway Module Uninstaller v2.2.1\n";
echo "================================================\n\n";

echo "⚠️  WARNING: This will permanently remove the Razorpay gateway module!\n";
echo "This includes:\n";
echo "- All module files\n";
echo "- Database tables\n";
echo "- Configuration settings\n";
echo "- Payment history (if using Razorpay exclusively)\n\n";

echo "Are you sure you want to continue? (yes/NO): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) !== 'yes') {
    echo "Uninstallation cancelled.\n";
    exit(0);
}

echo "\n🔄 Starting uninstallation...\n";

// Check if module is installed
if (!file_exists('modules/gateways/razorpay.php')) {
    echo "❌ Razorpay module not found. Nothing to uninstall.\n";
    exit(0);
}

// Backup configuration
echo "💾 Creating backup of configuration...\n";
$backupDir = 'modules/gateways/razorpay/backups/uninstall-' . date('Y-m-d-H-i-s');
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Backup gateway configuration
try {
    $gatewayConfig = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'razorpay')
        ->get();
    
    if ($gatewayConfig->count() > 0) {
        file_put_contents($backupDir . '/gateway_config.json', json_encode($gatewayConfig, JSON_PRETTY_PRINT));
        echo "✅ Gateway configuration backed up\n";
    }
} catch (Exception $e) {
    echo "⚠️  Could not backup gateway configuration: " . $e->getMessage() . "\n";
}

// Backup order mapping data
try {
    if (Capsule::schema()->hasTable('tblrzpordermapping')) {
        $orderMapping = Capsule::table('tblrzpordermapping')->get();
        if ($orderMapping->count() > 0) {
            file_put_contents($backupDir . '/order_mapping.json', json_encode($orderMapping, JSON_PRETTY_PRINT));
            echo "✅ Order mapping data backed up\n";
        }
    }
} catch (Exception $e) {
    echo "⚠️  Could not backup order mapping data: " . $e->getMessage() . "\n";
}

// Remove database table
echo "\n🗄️  Removing database table...\n";
try {
    if (Capsule::schema()->hasTable('tblrzpordermapping')) {
        Capsule::schema()->drop('tblrzpordermapping');
        echo "✅ Database table removed\n";
    } else {
        echo "ℹ️  Database table not found\n";
    }
} catch (Exception $e) {
    echo "❌ Failed to remove database table: " . $e->getMessage() . "\n";
}

// Remove gateway configuration
echo "\n⚙️  Removing gateway configuration...\n";
try {
    Capsule::table('tblpaymentgateways')
        ->where('gateway', 'razorpay')
        ->delete();
    echo "✅ Gateway configuration removed\n";
} catch (Exception $e) {
    echo "❌ Failed to remove gateway configuration: " . $e->getMessage() . "\n";
}

// Remove files
echo "\n📁 Removing files...\n";

$filesToRemove = [
    'modules/gateways/razorpay.php',
    'modules/gateways/callback/razorpay.php'
];

foreach ($filesToRemove as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "✅ Removed: $file\n";
        } else {
            echo "❌ Failed to remove: $file\n";
        }
    } else {
        echo "ℹ️  File not found: $file\n";
    }
}

// Remove directory
echo "\n📁 Removing module directory...\n";
if (is_dir('modules/gateways/razorpay')) {
    if (recursive_remove('modules/gateways/razorpay')) {
        echo "✅ Module directory removed\n";
    } else {
        echo "❌ Failed to remove module directory\n";
    }
} else {
    echo "ℹ️  Module directory not found\n";
}

// Clean up any remaining references
echo "\n🧹 Cleaning up references...\n";

// Check for any remaining references in templates
$templateFiles = glob('templates/*/invoice.tpl');
foreach ($templateFiles as $template) {
    $content = file_get_contents($template);
    if (strpos($content, 'razorpay') !== false) {
        echo "⚠️  Found Razorpay references in: $template\n";
        echo "   You may need to manually remove these references\n";
    }
}

// Check for any remaining references in configuration
try {
    $config = Capsule::table('tblconfiguration')
        ->where('value', 'like', '%razorpay%')
        ->get();
    
    if ($config->count() > 0) {
        echo "⚠️  Found Razorpay references in configuration:\n";
        foreach ($config as $setting) {
            echo "   - {$setting->setting}: {$setting->value}\n";
        }
        echo "   You may need to manually clean these up\n";
    }
} catch (Exception $e) {
    echo "⚠️  Could not check configuration references: " . $e->getMessage() . "\n";
}

echo "\n✅ Uninstallation completed!\n\n";

echo "Summary:\n";
echo "- Module files removed\n";
echo "- Database table removed\n";
echo "- Gateway configuration removed\n";
echo "- Backup created in: $backupDir\n\n";

echo "Important notes:\n";
echo "1. Check your templates for any Razorpay references\n";
echo "2. Update any custom code that references Razorpay\n";
echo "3. Test your payment processing with other gateways\n";
echo "4. Keep the backup files for at least 30 days\n\n";

echo "If you need to restore the module, you can:\n";
echo "1. Re-run the installation script\n";
echo "2. Restore configuration from backup if needed\n";
echo "3. Re-configure your Razorpay settings\n\n";

/**
 * Recursively remove a directory
 */
function recursive_remove($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            recursive_remove($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}
