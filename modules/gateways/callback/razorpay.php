<?php
/**
 * WHMCS Razorpay Gateway Callback Stub
 * 
 * This is the canonical callback location expected by WHMCS.
 * It acts as a thin proxy to the actual callback handler.
 * 
 * @package Razorpay WHMCS Gateway
 * @version 2.2.1
 * @author Razorpay
 * @license MIT
 */

// Prevent direct access
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Security: Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// Basic parameter validation
if (empty($_POST['razorpay_payment_id']) || empty($_POST['razorpay_order_id']) || empty($_POST['razorpay_signature'])) {
    http_response_code(400);
    die('Missing required parameters');
}

// Include the actual callback handler
require_once __DIR__ . '/../razorpay/callback/razorpay.php';
?>
