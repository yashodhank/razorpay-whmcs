<?php
/**
 * Razorpay WHMCS Gateway Module - Example Configuration
 * 
 * This file shows how to configure the Razorpay gateway module.
 * Copy the relevant settings to your WHMCS gateway configuration.
 */

// Example gateway configuration
$gatewayConfig = [
    'gateway' => 'razorpay',
    'name' => 'Razorpay',
    'type' => 'CC',
    'setting' => [
        'keyId' => 'rzp_test_xxxxxxxxxxxxx', // Your Razorpay Key ID
        'keySecret' => 'xxxxxxxxxxxxxxxxxxxx', // Your Razorpay Key Secret
        'webhookSecret' => 'xxxxxxxxxxxxxxxxxxxx', // Your Webhook Secret
        'enableWebhook' => 'on',
        'feeMode' => 'merchant_absorbs',
        'supportedCurrencies' => 'INR,USD,EUR,GBP,AED,SGD',
        'paymentAction' => 'capture',
        'environment' => 'auto'
    ]
];

// Example webhook URL
$webhookUrl = 'https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php';

// Example test cards for Razorpay test mode
$testCards = [
    'success' => '4111 1111 1111 1111',
    'failure' => '4000 0000 0000 0002',
    '3d_secure' => '4000 0000 0000 0002'
];
