<?php
/**
 * Razorpay WHMCS Gateway Module - Test Configuration
 * 
 * This file contains test configuration for the Razorpay gateway module.
 * Use this for testing the module in a development environment.
 */

// Test configuration
define('RAZORPAY_TEST_MODE', true);
define('RAZORPAY_DEBUG', true);

// Test API keys (replace with your test keys)
define('RAZORPAY_TEST_KEY_ID', 'rzp_test_xxxxxxxxxxxxx');
define('RAZORPAY_TEST_KEY_SECRET', 'xxxxxxxxxxxxxxxxxxxx');

// Test webhook secret
define('RAZORPAY_TEST_WEBHOOK_SECRET', 'xxxxxxxxxxxxxxxxxxxx');

// Test webhook URL
define('RAZORPAY_TEST_WEBHOOK_URL', 'https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php');
