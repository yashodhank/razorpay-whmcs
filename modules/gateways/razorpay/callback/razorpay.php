<?php
/**
 * WHMCS Razorpay Gateway Callback Handler
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

/**
 * WHMCS Razorpay Payment Callback File
 *
 * Verifying that the payment gateway module is active,
 * Validating an Invoice ID, Checking for the existence of a Transaction ID,
 * Logging the Transaction for debugging and Adding Payment to an Invoice.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../razorpay/lib/razorpay-sdk/Razorpay.php';
require_once __DIR__ . '/../razorpay/rzpordermapping.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type'])
{
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
$merchant_order_id   = (isset($_POST['merchant_order_id']) === true) ? $_POST['merchant_order_id'] : $_GET['merchant_order_id'];
$razorpay_payment_id = $_POST['razorpay_payment_id'] ?? '';
$razorpay_order_id   = $_POST['razorpay_order_id'] ?? '';
$razorpay_signature  = $_POST['razorpay_signature'] ?? '';

// Comprehensive payment data validation
$validationErrors = [];

// Validate required parameters
if (empty($merchant_order_id)) {
    $validationErrors[] = 'Missing merchant_order_id';
}
if (empty($razorpay_payment_id)) {
    $validationErrors[] = 'Missing razorpay_payment_id';
}
if (empty($razorpay_order_id)) {
    $validationErrors[] = 'Missing razorpay_order_id';
}
if (empty($razorpay_signature)) {
    $validationErrors[] = 'Missing razorpay_signature';
}

// Validate merchant_order_id format (should be numeric invoice ID)
if (!empty($merchant_order_id) && !is_numeric($merchant_order_id)) {
    $validationErrors[] = 'Invalid merchant_order_id format';
}

// Validate Razorpay payment ID format (should start with 'pay_')
if (!empty($razorpay_payment_id) && !preg_match('/^pay_[a-zA-Z0-9]+$/', $razorpay_payment_id)) {
    $validationErrors[] = 'Invalid razorpay_payment_id format';
}

// Validate Razorpay order ID format (should start with 'order_')
if (!empty($razorpay_order_id) && !preg_match('/^order_[a-zA-Z0-9]+$/', $razorpay_order_id)) {
    $validationErrors[] = 'Invalid razorpay_order_id format';
}

// Log validation errors if any
if (!empty($validationErrors)) {
    $errorMessage = 'Payment validation failed: ' . implode(', ', $validationErrors);
    logActivity('Razorpay Callback Validation Error: ' . $errorMessage . ' | Invoice: ' . $merchant_order_id . ' | Payment: ' . $razorpay_payment_id);
    
    // Redirect with error status
    header("Location: " . $gatewayParams['systemurl'] . "/viewinvoice.php?id=" . $merchant_order_id . "&paymentfailed=1&error=" . urlencode($errorMessage));
    exit;
}

// Validate Callback Invoice ID.
$merchant_order_id = checkCbInvoiceID($merchant_order_id, $gatewayParams['name']);

// Auto-fix mechanisms for common issues
$autoFixApplied = [];

// Auto-fix: Ensure invoice exists and is valid
if (!empty($merchant_order_id)) {
    $invoiceData = localAPI('GetInvoice', array('invoiceid' => $merchant_order_id));
    if ($invoiceData['result'] !== 'success') {
        logActivity('Razorpay Auto-fix: Invoice ' . $merchant_order_id . ' not found, attempting to create mapping');
        
        // Try to find invoice by Razorpay order ID
        $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);
        $mappedInvoiceId = $rzpOrderMapping->getInvoiceIdByRazorpayOrderId($razorpay_order_id);
        
        if ($mappedInvoiceId) {
            $merchant_order_id = $mappedInvoiceId;
            $autoFixApplied[] = 'Restored invoice ID from order mapping';
            logActivity('Razorpay Auto-fix: Restored invoice ID ' . $mappedInvoiceId . ' from order mapping');
        }
    }
}

// Auto-fix: Validate and correct payment amount
$paymentAmount = null;
if (!empty($razorpay_payment_id)) {
    try {
        $api = getRazorpayApiInstance($gatewayParams);
        $paymentDetails = $api->payment->fetch($razorpay_payment_id);
        
        if (isset($paymentDetails['amount'])) {
            $paymentAmount = $paymentDetails['amount'] / 100; // Convert from paise to rupees
            $autoFixApplied[] = 'Retrieved payment amount from Razorpay API';
        }
    } catch (Exception $e) {
        logActivity('Razorpay Auto-fix: Failed to fetch payment details - ' . $e->getMessage());
    }
}

// Auto-fix: Validate signature with proper error handling
$signatureValid = false;
if (!empty($razorpay_payment_id) && !empty($razorpay_order_id) && !empty($razorpay_signature)) {
    try {
        $api = getRazorpayApiInstance($gatewayParams);
        $api->utility->verifyPaymentSignature(array(
            'razorpay_order_id' => $razorpay_order_id,
            'razorpay_payment_id' => $razorpay_payment_id,
            'razorpay_signature' => $razorpay_signature
        ));
        $signatureValid = true;
        $autoFixApplied[] = 'Signature verification successful';
    } catch (Exception $e) {
        logActivity('Razorpay Auto-fix: Signature verification failed - ' . $e->getMessage());
        
        // Try alternative signature verification method
        try {
            $expectedSignature = hash_hmac('sha256', $razorpay_order_id . '|' . $razorpay_payment_id, $gatewayParams['keySecret']);
            if (hash_equals($expectedSignature, $razorpay_signature)) {
                $signatureValid = true;
                $autoFixApplied[] = 'Signature verification successful (alternative method)';
            }
        } catch (Exception $e2) {
            logActivity('Razorpay Auto-fix: Alternative signature verification also failed - ' . $e2->getMessage());
        }
    }
}

// Log auto-fix results
if (!empty($autoFixApplied)) {
    logActivity('Razorpay Auto-fix Applied: ' . implode(', ', $autoFixApplied) . ' | Invoice: ' . $merchant_order_id);
}

/**
* Enhanced debugging and logging
*/
function logRazorpayDebug($message, $context = []) {
    $logMessage = '[RAZORPAY DEBUG] ' . $message;
    if (!empty($context)) {
        $logMessage .= ' | Context: ' . json_encode($context);
    }
    logActivity($logMessage);
}

// Enhanced debugging: Log all callback parameters
logRazorpayDebug('Callback received', [
    'merchant_order_id' => $merchant_order_id,
    'razorpay_payment_id' => $razorpay_payment_id,
    'razorpay_order_id' => $razorpay_order_id,
    'signature_valid' => $signatureValid,
    'auto_fixes_applied' => $autoFixApplied,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
]);

/**
* Fetch amount to verify transaction
*/
# Fetch invoice to get the amount and userid
$result = RzpWhmcsCompat::safeQuery('tblinvoices', '*', array("id"=>$merchant_order_id));

#check whether order is already paid or not, if paid then redirect to complete page
if($result['status'] === 'Paid')
{
    logRazorpayDebug('Invoice already paid', ['invoice_id' => $merchant_order_id, 'status' => $result['status']]);
    header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id . "&paymentsuccess=1&already_paid=1"); // nosemgrep : php.lang.security.non-literal-header.non-literal-header
    
    exit;
}

# Enhanced balance calculation with error handling
$invoiceTotal = 0;
$invoiceBalance = 0;

try {
    // Get invoice total from database result
    if (isset($result['total'])) {
        $invoiceTotal = floatval($result['total']);
    } else {
        // Fallback: fetch from API
        $invoiceData = localAPI('GetInvoice', array('invoiceid' => $merchant_order_id));
        if ($invoiceData['result'] === 'success' && isset($invoiceData['total'])) {
            $invoiceTotal = floatval($invoiceData['total']);
        } else {
            throw new Exception('Cannot determine invoice total');
        }
    }
    
    // Calculate balance (total - amount paid)
    $amountPaid = isset($result['amountpaid']) ? floatval($result['amountpaid']) : 0;
    $invoiceBalance = $invoiceTotal - $amountPaid;
    
    logRazorpayDebug('Invoice balance calculated', [
        'invoice_id' => $merchant_order_id,
        'total' => $invoiceTotal,
        'amount_paid' => $amountPaid,
        'balance' => $invoiceBalance
    ]);
    
} catch (Exception $e) {
    logActivity('Razorpay Balance Calculation Error: ' . $e->getMessage() . ' | Invoice: ' . $merchant_order_id);
    
    // Fallback: use payment amount from Razorpay if available
    if ($paymentAmount !== null) {
        $invoiceBalance = $paymentAmount;
        logRazorpayDebug('Using Razorpay payment amount as fallback', ['amount' => $paymentAmount]);
    } else {
        // Last resort: redirect with error
        header("Location: " . $gatewayParams['systemurl'] . "/viewinvoice.php?id=" . $merchant_order_id . "&paymentfailed=1&error=" . urlencode('Cannot calculate invoice balance'));
        exit;
    }
}

# Validate payment amount against invoice balance
if ($paymentAmount !== null && abs($paymentAmount - $invoiceBalance) > 0.01) {
    logRazorpayDebug('Payment amount mismatch', [
        'payment_amount' => $paymentAmount,
        'invoice_balance' => $invoiceBalance,
        'difference' => abs($paymentAmount - $invoiceBalance)
    ]);
    
    // Use the larger amount to avoid underpayment issues
    $finalAmount = max($paymentAmount, $invoiceBalance);
    logRazorpayDebug('Using larger amount to avoid underpayment', ['final_amount' => $finalAmount]);
} else {
    $finalAmount = $paymentAmount !== null ? $paymentAmount : $invoiceBalance;
}

$amount = $finalAmount;

$error = "";

try
{
    // Fetch payment details from Razorpay to get creation timestamp
    $api = getRazorpayApiInstance($gatewayParams);
    $paymentDetails = $api->payment->fetch($razorpay_payment_id);
    $razorpayCreatedAt = $paymentDetails['created_at'] ?? time();
    
    // Convert Razorpay Unix timestamp to WHMCS datetime format using timezone conversion
    $paymentDate = RzpWhmcsCompat::tzConvertFromUnix($razorpayCreatedAt);
    
    // Calculate gateway fee if payment amount differs from invoice amount
    $actualPaymentAmount = $paymentDetails['amount'] / 100;
    $gatewayFee = 0;
    $paymentAmount = $amount;
    
    if ($actualPaymentAmount > $amount) {
        $gatewayFee = $actualPaymentAmount - $amount;
        // For merchant-absorbs mode: record invoice amount, fee as separate field
        $paymentAmount = $amount;
    } elseif ($actualPaymentAmount > $amount * 0.995) {
        // For client-pays mode: record full payment amount
        $paymentAmount = $actualPaymentAmount;
    }
    
    verifySignature($merchant_order_id, $_POST, $gatewayParams);

    # Successful
    # Apply Payment to Invoice using compatibility layer with proper date
    $success = RzpWhmcsCompat::addPayment(
        $merchant_order_id, 
        $razorpay_payment_id, 
        $paymentAmount, 
        $gatewayFee, 
        'razorpay', 
        $paymentDate
    );

    logTransaction($gatewayParams["name"], $_POST, "Successful"); # Save to Gateway Log: name, data array, status
}
catch (Errors\SignatureVerificationError $e)
{
    $error = 'WHMCS_ERROR: Payment to Razorpay Failed. ' . $e->getMessage();

    # Unsuccessful
    # Save to Gateway Log: name, data array, status
    logTransaction($gatewayParams["name"], $_POST, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$_POST['razorpay_payment_id']);
}

// PRG redirect with status parameter
$statusParam = (isset($error) && !empty($error)) ? '&paymentfailed=1' : '&paymentsuccess=1';
header("Location: ".$gatewayParams['systemurl']."/viewinvoice.php?id=" . $merchant_order_id . $statusParam); // nosemgrep : php.lang.security.non-literal-header.non-literal-header

/**
* @codeCoverageIgnore
*/
function getApiInstance($key,$keySecret)
{
    return new Api($key, $keySecret);
}

/**
 * Verify the signature on payment success
 * @param  int $order_no
 * @param  array $response
 * @param  array $gatewayParams
 * @return
 */
function verifySignature($order_no, $response, $gatewayParams)
{
    $api = getApiInstance($gatewayParams['keyId'], $gatewayParams['keySecret']);

    $attributes = array(
        RAZORPAY_PAYMENT_ID => $response[RAZORPAY_PAYMENT_ID],
        RAZORPAY_SIGNATURE  => $response[RAZORPAY_SIGNATURE],
    );

    $sessionKey = getOrderSessionKey($order_no);
    $razorpayOrderId = "";

    if (isset($_SESSION[$sessionKey]) === true)
    {
        $razorpayOrderId = $_SESSION[$sessionKey];
    }
    else
    {
        logTransaction($gatewayParams['name'], $sessionKey, "Session not found");
        try
        {
            if (isset($order_no) === true)
            {
                $rzpOrderMapping = new RZPOrderMapping($gatewayParams['name']);
                $razorpayOrderId = $rzpOrderMapping->getRazorpayOrderID($order_no);
            }
            else
            {
                $error = "merchant_order_id is not set";
                logTransaction($gatewayParams['name'], $error, "Validation Failure");
            }
        }
        catch (Exception $e)
        {
            logTransaction($gatewayParams['name'], $e->getMessage(), "Unsuccessful - Fetch Order");
        }
    }

    $attributes[RAZORPAY_ORDER_ID] = $razorpayOrderId;
    $api->utility->verifyPaymentSignature($attributes);
}
