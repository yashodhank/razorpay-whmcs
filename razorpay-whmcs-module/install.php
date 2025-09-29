<?php
/**
 * Razorpay WHMCS Gateway Module Installer
 * 
 * This script helps install the Razorpay gateway module into your WHMCS installation.
 * Run this script from your WHMCS root directory.
 * 
 * Usage: php install.php
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

echo "Razorpay WHMCS Gateway Module Installer v2.2.1\n";
echo "===============================================\n\n";

// Check WHMCS version
$whmcsVersion = $CONFIG['Version'];
echo "WHMCS Version: $whmcsVersion\n";

// Check PHP version
$phpVersion = PHP_VERSION;
echo "PHP Version: $phpVersion\n";

// Check if module is already installed
if (file_exists('modules/gateways/razorpay.php')) {
    echo "\n⚠️  Warning: Razorpay module appears to be already installed.\n";
    echo "Do you want to continue with the installation? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'y') {
        echo "Installation cancelled.\n";
        exit(0);
    }
}

echo "\n📁 Installing files...\n";

// Create directories if they don't exist
$directories = [
    'modules/gateways',
    'modules/gateways/callback',
    'modules/gateways/razorpay',
    'modules/gateways/razorpay/lib',
    'modules/gateways/razorpay/scripts',
    'modules/gateways/razorpay/docs',
    'modules/gateways/razorpay/backups'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "✅ Created directory: $dir\n";
        } else {
            echo "❌ Failed to create directory: $dir\n";
            exit(1);
        }
    }
}

// Copy files
$files = [
    'razorpay.php' => 'modules/gateways/razorpay.php',
    'callback/razorpay.php' => 'modules/gateways/callback/razorpay.php',
    'razorpay/razorpay-webhook.php' => 'modules/gateways/razorpay/razorpay-webhook.php',
    'razorpay/rzpordermapping.php' => 'modules/gateways/razorpay/rzpordermapping.php',
    'razorpay/index.php' => 'modules/gateways/razorpay/index.php',
    'razorpay/README.md' => 'modules/gateways/razorpay/README.md'
];

foreach ($files as $source => $dest) {
    if (file_exists($source)) {
        if (copy($source, $dest)) {
            echo "✅ Copied: $source → $dest\n";
        } else {
            echo "❌ Failed to copy: $source → $dest\n";
            exit(1);
        }
    } else {
        echo "⚠️  Source file not found: $source\n";
    }
}

// Copy Razorpay SDK
echo "\n📦 Installing Razorpay SDK...\n";
if (is_dir('razorpay/lib/razorpay-sdk')) {
    if (recursive_copy('razorpay/lib/razorpay-sdk', 'modules/gateways/razorpay/lib/razorpay-sdk')) {
        echo "✅ Razorpay SDK installed\n";
    } else {
        echo "❌ Failed to install Razorpay SDK\n";
        exit(1);
    }
} else {
    echo "❌ Razorpay SDK not found\n";
    exit(1);
}

// Copy utility scripts
echo "\n🛠️  Installing utility scripts...\n";
if (is_dir('razorpay/scripts')) {
    if (recursive_copy('razorpay/scripts', 'modules/gateways/razorpay/scripts')) {
        echo "✅ Utility scripts installed\n";
    } else {
        echo "❌ Failed to install utility scripts\n";
        exit(1);
    }
} else {
    echo "❌ Utility scripts not found\n";
    exit(1);
}

// Copy documentation
echo "\n📚 Installing documentation...\n";
if (is_dir('razorpay/docs')) {
    if (recursive_copy('razorpay/docs', 'modules/gateways/razorpay/docs')) {
        echo "✅ Documentation installed\n";
    } else {
        echo "❌ Failed to install documentation\n";
        exit(1);
    }
} else {
    echo "❌ Documentation not found\n";
    exit(1);
}

// Set permissions
echo "\n🔐 Setting permissions...\n";
$permissions = [
    'modules/gateways/razorpay.php' => 0644,
    'modules/gateways/callback/razorpay.php' => 0644,
    'modules/gateways/razorpay/' => 0755
];

foreach ($permissions as $path => $perm) {
    if (file_exists($path)) {
        if (chmod($path, $perm)) {
            echo "✅ Set permissions for: $path\n";
        } else {
            echo "⚠️  Could not set permissions for: $path\n";
        }
    }
}

// Create database table
echo "\n🗄️  Creating database table...\n";
try {
    $rzpOrderMapping = new RZPOrderMapping('razorpay');
    $rzpOrderMapping->createTable();
    echo "✅ Database table created successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to create database table: " . $e->getMessage() . "\n";
    exit(1);
}

// Test module loading
echo "\n🧪 Testing module loading...\n";
try {
    if (function_exists('razorpay_MetaData')) {
        echo "✅ Module loaded successfully\n";
    } else {
        echo "❌ Module failed to load\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Module test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 Installation completed successfully!\n\n";

echo "Next steps:\n";
echo "1. Go to WHMCS Admin → Setup → Payments → Payment Gateways\n";
echo "2. Find 'Razorpay' and click 'Configure'\n";
echo "3. Enter your Razorpay API keys\n";
echo "4. Set up webhooks in Razorpay Dashboard\n";
echo "5. Test with a small transaction\n\n";

echo "For detailed setup instructions, see:\n";
echo "- README.md\n";
echo "- modules/gateways/razorpay/docs/WEBHOOK_SETUP.md\n\n";

echo "Support:\n";
echo "- GitHub Issues: https://github.com/yourusername/razorpay-whmcs-gateway/issues\n";
echo "- Documentation: https://github.com/yourusername/razorpay-whmcs-gateway/wiki\n\n";

/**
 * Recursively copy a directory
 */
function recursive_copy($src, $dst) {
    $dir = opendir($src);
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir($src . '/' . $file)) {
                recursive_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
    return true;
}
