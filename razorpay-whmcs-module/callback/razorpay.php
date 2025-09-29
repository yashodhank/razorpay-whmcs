<?php
/**
 * Razorpay WHMCS Gateway Callback File
 * 
 * This file handles the callback from Razorpay after payment processing.
 * It verifies the payment status and updates the WHMCS invoice accordingly.
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

// Include the main gateway file
require_once __DIR__ . '/../razorpay.php';

/**
 * Handle Razorpay payment callback
 */
function razorpay_callback_handler($vars) {
    // Get the payment ID from the callback
    $payment_id = $_GET['razorpay_payment_id'] ?? '';
    $order_id = $_GET['razorpay_order_id'] ?? '';
    $signature = $_GET['razorpay_signature'] ?? '';
    
    if (empty($payment_id) || empty($order_id) || empty($signature)) {
        logActivity('Razorpay Callback: Missing required parameters');
        return [
            'status' => 'error',
            'message' => 'Missing required parameters'
        ];
    }
    
    // Get gateway configuration
    $gateway_config = getGatewayVariables('razorpay');
    if (empty($gateway_config['key_id']) || empty($gateway_config['key_secret'])) {
        logActivity('Razorpay Callback: Gateway not configured');
        return [
            'status' => 'error',
            'message' => 'Gateway not configured'
        ];
    }
    
    try {
        // Initialize Razorpay API
        $api = new Razorpay\Api\Api($gateway_config['key_id'], $gateway_config['key_secret']);
        
        // Verify the payment
        $attributes = [
            'razorpay_order_id' => $order_id,
            'razorpay_payment_id' => $payment_id,
            'razorpay_signature' => $signature
        ];
        
        $api->utility->verifyPaymentSignature($attributes);
        
        // Get payment details
        $payment = $api->payment->fetch($payment_id);
        
        if ($payment->status === 'captured') {
            // Payment successful
            $invoice_id = $payment->notes['invoice_id'] ?? '';
            $amount = $payment->amount / 100; // Convert from paise to rupees
            
            if (!empty($invoice_id)) {
                // Update invoice status
                $result = localAPI('UpdateInvoice', [
                    'invoiceid' => $invoice_id,
                    'status' => 'Paid',
                    'paymentmethod' => 'razorpay'
                ]);
                
                if ($result['result'] === 'success') {
                    logActivity("Razorpay Payment Successful: Payment ID {$payment_id}, Invoice ID {$invoice_id}, Amount {$amount}");
                    return [
                        'status' => 'success',
                        'message' => 'Payment processed successfully'
                    ];
                } else {
                    logActivity("Razorpay Payment Error: Failed to update invoice {$invoice_id}");
                    return [
                        'status' => 'error',
                        'message' => 'Failed to update invoice'
                    ];
                }
            } else {
                logActivity("Razorpay Payment Error: No invoice ID found in payment notes");
                return [
                    'status' => 'error',
                    'message' => 'No invoice ID found'
                ];
            }
        } else {
            logActivity("Razorpay Payment Error: Payment not captured. Status: {$payment->status}");
            return [
                'status' => 'error',
                'message' => 'Payment not captured'
            ];
        }
        
    } catch (Exception $e) {
        logActivity("Razorpay Callback Error: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Payment verification failed: ' . $e->getMessage()
        ];
    }
}

// Handle the callback
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['razorpay_payment_id'])) {
    $result = razorpay_callback_handler($_GET);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// If accessed directly without parameters, show error
http_response_code(400);
echo json_encode([
    'status' => 'error',
    'message' => 'Invalid callback request'
]);
?>
