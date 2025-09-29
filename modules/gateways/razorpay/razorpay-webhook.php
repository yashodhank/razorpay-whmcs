<?php

// Include the main gateway file for sync functions
require_once __DIR__ . '/../razorpay.php';

/**
 * WHMCS Razorpay Compatibility Layer
 * PHP 5.6+ safe with WHMCS 6/7/8 support
 */
if (!class_exists('RzpWhmcsCompat')) {
class RzpWhmcsCompat
{
    private static $whmcsVersion = null;
    private static $phpVersion = null;
    
    /**
     * Detect WHMCS capabilities via feature detection
     */
    public static function hasMetaDataFn()
    {
        return function_exists('razorpay_MetaData');
    }
    
    /**
     * Check if NoLocalCreditCardInput is supported
     */
    public static function supportsNoLocalCC()
    {
        return self::hasMetaDataFn();
    }
    
    /**
     * Unified payment recording with fallbacks
     */
    public static function addPayment($invoiceId, $transId, $amount, $fees = 0, $gateway = 'razorpay', $date = null)
    {
        // Try modern localAPI first (WHMCS 7+)
        if (function_exists('localAPI')) {
            $result = localAPI('AddInvoicePayment', array(
                'invoiceid' => $invoiceId,
                'transid' => $transId,
                'gateway' => $gateway,
                'amount' => $amount,
                'fees' => $fees,
                'date' => $date ?: date('Y-m-d H:i:s')
            ));
            
            if ($result['result'] === 'success') {
                return true;
            }
        }
        
        // Fallback to legacy addInvoicePayment (WHMCS 6)
        if (function_exists('addInvoicePayment')) {
            addInvoicePayment($invoiceId, $transId, $amount, $fees, $gateway);
            return true;
        }
        
        return false;
    }
    
    /**
     * Constant-time string comparison
     */
    public static function constantTimeEquals($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        
        // PHP 5.6 polyfill
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
    
    /**
     * Convert Razorpay Unix timestamp to WHMCS datetime using WHMCS timezone
     */
    public static function tzConvertFromUnix($timestamp)
    {
        // Get WHMCS timezone setting
        $whmcsTimezone = 'Asia/Calcutta'; // Default fallback
        if (class_exists('Illuminate\Database\Capsule\Manager')) {
            try {
                $result = self::safeQuery('tblconfiguration', 'value', array('setting' => 'cronTimeZone'));
                if ($result && !empty($result->value)) {
                    $whmcsTimezone = $result->value;
                }
            } catch (Exception $e) {
                // Fallback to default if database query fails
            }
        }
        
        // Create DateTime object with WHMCS timezone
        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        $dateTime->setTimezone(new DateTimeZone($whmcsTimezone));
        
        return $dateTime->format('Y-m-d H:i:s');
    }
    
    /**
     * Safe query method with fallbacks
     */
    public static function safeQuery($table, $fields, $where)
    {
        // Try Capsule first (WHMCS 6+)
        if (class_exists('Illuminate\Database\Capsule\Manager')) {
            $query = \Illuminate\Database\Capsule\Manager::table($table)->select($fields);
            foreach ($where as $key => $value) {
                $query->where($key, $value);
            }
            return $query->first();
        }
        
        // Fallback to legacy select_query
        if (function_exists('select_query')) {
            $result = select_query($table, $fields, $where);
            if (function_exists('mysql_fetch_assoc')) {
                return mysql_fetch_assoc($result);
            }
        }
        
        return false;
    }
}
}

// Polyfills for PHP 5.6
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b)
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
}

if (!function_exists('random_bytes')) {
    function random_bytes($length)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($strong === true) {
                return $bytes;
            }
        }
        
        // Fallback to mt_rand (less secure but functional)
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        
        return $bytes;
    }
}

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

/**
 * Event constants
 */
const PAYMENT_CAPTURED = 'payment.captured';
const ORDER_PAID  = 'order.paid';
const REFUND_CREATED = 'refund.created';
const REFUND_PROCESSED = 'refund.processed';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

$api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);

/**
 * Process a Razorpay Webhook. We exit in the following cases:
 * - Successful processed
 * - Exception while fetching the payment
 *
 * It passes on the webhook in the following cases:
 * - invoice_id set in payment.authorized
 * - order refunded
 * - Invalid JSON
 * - Signature mismatch
 * - Secret isn't setup
 * - Event not recognized
 *
 * @return void|WP_Error
 * @throws Exception
 */

$post = file_get_contents('php://input');

$data = json_decode($post, true);

if (json_last_error() !== 0)
{
    return;
}

$enabled = $gatewayParams['enableWebhook'];

// CRITICAL: Log all webhook attempts for debugging
logTransaction($gatewayParams['name'], [
    'webhook_enabled' => $enabled,
    'event' => $data['event'] ?? 'unknown',
    'has_signature' => isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']),
    'raw_data' => $data
], 'Webhook Received');

if ($enabled === 'on' and
    (empty($data['event']) === false))
{
    if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
    {
        $razorpayWebhookSecret = $gatewayParams['webhookSecret'];

        //
        // If the webhook secret isn't set on wordpress, return
        //
        if (empty($razorpayWebhookSecret) === true)
        {
            return;
        }

        try
        {
            $expectedSignature = hash_hmac('sha256', $post, $razorpayWebhookSecret);
            $actualSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
            if (!RzpWhmcsCompat::constantTimeEquals($expectedSignature, $actualSignature)) {
                throw new Errors\SignatureVerificationError('Invalid signature');
            }
        }
        catch (Errors\SignatureVerificationError $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $data,
                'event'     => 'razorpay.whmcs.signature.verify_failed'
            );

            logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$e->getMessage());

            header('HTTP/1.1 401 Unauthorized', true, 401);

            return;
        }

        switch ($data['event'])
        {
            case PAYMENT_CAPTURED:
                return paymentCaptured($data, $gatewayParams);
            case ORDER_PAID:
                return orderPaid($data, $gatewayParams);
            case REFUND_CREATED:
            case REFUND_PROCESSED:
                return refundProcessed($data, $gatewayParams);
            default:
                return;
        }
    }
}
else
{
    // CRITICAL: Log when webhook is disabled
    logTransaction($gatewayParams['name'], [
        'webhook_enabled' => $enabled,
        'event' => $data['event'] ?? 'unknown',
        'message' => 'Webhook processing disabled - payments will not be recorded!'
    ], 'Webhook Disabled Warning');
}


/**
 * Order Paid webhook
 *
 * @param array $data
 */
function orderPaid(array $data, $gatewayParams)
{
    // We don't process subscription/invoice payments here
    if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
    {
        logTransaction($gatewayParams['name'], "returning order.paid webhook", "Invoice ID exists");
        return;
    }

    //
    // Order entity should be sent as part of the webhook payload
    //
    $orderId = $data['payload']['order']['entity']['notes']['whmcs_order_id'];
    $razorpayPaymentId = $data['payload']['payment']['entity']['id'];
    $razorpayOrderId = $data['payload']['order']['entity']['id'];

    // Validate Callback Invoice ID.
    $merchant_order_id = checkCbInvoiceID($orderId, $gatewayParams['name']);
    
    // Check Callback Transaction ID.
    checkCbTransID($razorpayPaymentId);

    // MAJOR FIX: Replace deprecated mysql_fetch_assoc with Capsule
    $orderTableId = Capsule::table('tblorders')
        ->select('id')
        ->where('invoiceid', $orderId)
        ->first();

    // CRITICAL FIX: Handle null result
    if (!$orderTableId) {
        logTransaction($gatewayParams['name'], "Order not found for invoice ID: $orderId", "Order Not Found");
        return;
    }

    $command = 'GetOrders';

    $postData = array(
        'id' => $orderTableId->id,
    );

    $order = localAPI($command, $postData);

    // If order detail not found then ignore.
    // If it is already marked as paid or failed ignore the event
    // MAJOR FIX: Enhanced idempotency check
    if($order['totalresults'] == 0 or $order['orders']['order'][0]['paymentstatus'] === 'Paid')
    {
        logTransaction($gatewayParams['name'], "order detail not found or already paid or failed", "INFO");
        return;
    }

    // Additional idempotency check using transaction ID

    $success = false;
    $error = "";
    $error = 'The payment has failed.';

    $amount = getOrderAmountAsInteger($order);

    // Enhanced amount validation with better error messages
    if($data['payload']['payment']['entity']['amount'] === $amount)
    {
        $success = true;
    }
    else
    {
        $error = 'WHMCS_ERROR: Payment to Razorpay Failed. Amount mismatch.';
        logTransaction($gatewayParams['name'], "Amount mismatch: Expected $amount, Got " . $data['payload']['payment']['entity']['amount'], "Amount Mismatch");
    }

    $log = [
        'merchant_order_id'   => $orderId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'razorpay_order_id' => $razorpayOrderId,
        'payment_created_at' => $data['payload']['payment']['entity']['created_at'],
        'webhook_received_at' => time(),
        'webhook' => true
    ];

    if ($success === true)
    {
        # Successful
        # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
        $razorpayCreatedAt = $data['payload']['payment']['entity']['created_at'];
        $orderAmount=$order['orders']['order'][0]['amount'];
        
        // Handle gateway fees based on configuration
        $feeMode = $gatewayParams['feeMode'] ?? 'merchant_absorbs';
        $paymentAmount = $orderAmount;
        $gatewayFee = 0;
        
        if ($feeMode === 'merchant_absorbs' || $feeMode === 'client_pays') {
            // Get the actual payment amount from Razorpay to check for gateway fees
            try {
                $api = new \Razorpay\Api\Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
                $paymentDetails = $api->payment->fetch($razorpayPaymentId);
                $actualPaymentAmount = $paymentDetails['amount'] / 100;
                
                // If payment amount is higher than order amount, it includes gateway fee
                if ($actualPaymentAmount > $orderAmount) {
                    $gatewayFee = $actualPaymentAmount - $orderAmount;
                    // Record only the order amount as payment (gateway fee goes to Razorpay)
                    $paymentAmount = $orderAmount;
                }
            } catch (Exception $e) {
                // If we can't fetch payment details, use order amount
                $paymentAmount = $orderAmount;
            }
        }
        
        // Convert Razorpay Unix timestamp to WHMCS datetime format (IST timezone)
        $paymentDate = RzpWhmcsCompat::tzConvertFromUnix($razorpayCreatedAt);
        
        // Use compatibility layer for payment recording
        $success = RzpWhmcsCompat::addPayment(
            $orderId,
            $razorpayPaymentId,
            $paymentAmount,
            $gatewayFee,
            'razorpay',
            $paymentDate
        );
        
        logTransaction($gatewayParams["name"], $log, "Successful"); # Save to Gateway Log: name, data array, status
    }
    else
    {
        # Unsuccessful
        # Save to Gateway Log: name, data array, status
        logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$razorpayPaymentId);
    }

    // Graceful exit since payment is now processed.
    exit;
}

/**
 * Returns the order amount, rounded as integer
 * @param WHMCS_Order $order WHMCS Order instance
 * @return int Order Amount
 */
function getOrderAmountAsInteger($order)
{
    return (int) round($order['orders']['order'][0]['amount'] * 100);
}

/**
 * Payment Captured webhook - Enhanced payment processing
 * @param array $data
 * @param array $gatewayParams
 */
function paymentCaptured(array $data, $gatewayParams)
{
    // Handle payment.captured events for better payment tracking
    $paymentId = $data['payload']['payment']['entity']['id'];
    $amount = $data['payload']['payment']['entity']['amount'];
    
    logTransaction($gatewayParams['name'], array(
        'payment_id' => $paymentId,
        'amount' => $amount,
        'status' => 'captured'
    ), 'Payment Captured');
    
    // Additional processing if needed
    return;
}

/**
 * Refund Processed webhook - MINOR FIX
 * @param array $data
 * @param array $gatewayParams
 */
function refundProcessed(array $data, $gatewayParams)
{
    $refundId = $data['payload']['refund']['entity']['id'];
    $paymentId = $data['payload']['refund']['entity']['payment_id'];
    $amount = $data['payload']['refund']['entity']['amount'];
    $status = $data['payload']['refund']['entity']['status'];
    
    logTransaction($gatewayParams['name'], array(
        'refund_id' => $refundId,
        'payment_id' => $paymentId,
        'amount' => $amount,
        'status' => $status
    ), 'Refund Processed');
    
    // Additional refund processing if needed
    return;
}

/**
 * Enhanced error handling and logging
 * @param string $message
 * @param array $data
 * @param string $level
 */
function logWebhookError($message, $data = array(), $level = 'Error')
{
    global $gatewayParams;
    
    $logData = array(
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level
    );
    
    logTransaction($gatewayParams['name'], $logData, $level);
}

/**
 * Validate webhook data structure
 * @param array $data
 * @return bool
 */
function validateWebhookData($data)
{
    if (!isset($data['event']) || empty($data['event'])) {
        logWebhookError('Missing event type', $data);
        return false;
    }
    
    if (!isset($data['payload']) || !is_array($data['payload'])) {
        logWebhookError('Missing or invalid payload', $data);
        return false;
    }
    
    return true;
}

// Update sync timestamp after successful webhook processing
if (function_exists('updateLastSyncTimestamp')) {
    updateLastSyncTimestamp('razorpay');
}

?>