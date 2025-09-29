<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../includes/invoicefunctions.php';
require_once __DIR__.'/razorpay/lib/razorpay-sdk/Razorpay.php';
require_once __DIR__.'/razorpay/rzpordermapping.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

const RAZORPAY_WHMCS_VERSION= '2.2.1';
const RAZORPAY_PAYMENT_ID   = 'razorpay_payment_id';
const RAZORPAY_ORDER_ID     = 'razorpay_order_id';
const RAZORPAY_SIGNATURE    = 'razorpay_signature';

const CAPTURE            = 'capture';
const AUTHORIZE          = 'authorize';
const WHMCS_ORDER_ID     = 'whmcs_order_id';

/**
 * WHMCS Razorpay Payment Gateway Module
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 * @return array
 */
function razorpay_MetaData()
{
    return array(
        'DisplayName' => 'Razorpay',
        'APIVersion' => '1.1',
        'NoLocalCreditCardInput' => true,
        'SupportsRefunds' => true,
        'SupportsPartialRefunds' => true,
        'SupportsWebhooks' => true,
        'SupportsMultiCurrency' => true,
    );
}

/**
 * Update gateway metadata for WHMCS v8+ compatibility
 * @return array
 */
function updateGatewayMetaData($vars)
{
    return array(
        'DisplayName' => 'Razorpay',
        'APIVersion' => '1.1',
        'NoLocalCreditCardInput' => true,
        'SupportsRefunds' => true,
        'SupportsPartialRefunds' => true,
        'SupportsWebhooks' => true,
        'SupportsMultiCurrency' => true,
    );
}
/**
 * Define gateway configuration options.
 * @return array
 */
function razorpay_config()
{
    global $CONFIG;

    $webhookUrl = $CONFIG['SystemURL'].'/modules/gateways/razorpay/razorpay-webhook.php';
    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);

    try
    {
        $rzpOrderMapping->createTable();
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Create Table");
    }

    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'environment' => array(
            'FriendlyName' => 'Environment',
            'Type' => 'dropdown',
            'Default' => 'auto',
            'Options' => array(
                'auto' => 'Auto-detect (Test/Live)',
                'test' => 'Test Mode',
                'live' => 'Live Mode',
            ),
            'Description' => 'Payment environment mode',
        ),
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Razorpay',
        ),
        'signUp' => array(
            'FriendlyName' => '',
            'Type' => 'comment',
            'Size' => '50',
            'Description' => 'First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=whmcs" target="_blank">Signup</a> for a Razorpay account OR <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=whmcs" target="_blank">Login</a> if you have an existing account.',
        ),
        'keyId' => array(
            'FriendlyName' => 'Key Id',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Razorpay "Key Id". Available <a href="https://dashboard.razorpay.com/#/app/keys" target="_blank">HERE</a>',
        ),
        'keySecret' => array(
            'FriendlyName' => 'Key Secret',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Razorpay "Key Secret" shared during activation API Key',
        ),
        'paymentAction' => array(
            'FriendlyName' => 'Payment Action',
            'Type' => 'dropdown',
            'Default' => 'Authorize and Capture',
            'Options' => array(
                CAPTURE   => 'Authorize and Capture',
                AUTHORIZE => 'Authorize',
            ),
            'Description' => 'Payment action on order compelete.',
        ),
        'supportedCurrencies' => array(
            'FriendlyName' => 'Supported Currencies',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'INR,USD,EUR,GBP,AED,SGD',
            'Description' => 'Comma-separated list of supported currencies (e.g., INR,USD,EUR)',
        ),
        'enableWebhook' => array(
            'FriendlyName' => 'Enable Webhook',
            'Type' => 'yesno',
            'Default' => true,
            'Description' => 'Enable Razorpay Webhook <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a> with the URL listed below. <br/><br><span>'.$webhookUrl.'</span><br/><strong>CRITICAL:</strong> This must be enabled for payments to be recorded!',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'text',
            'Size' => '50',
            'Description' => '<br/> Webhook secret is used for webhook signature verification. This has to match the one added <a href="https://dashboard.razorpay.com/#/app/webhooks">here</a>',
        ),
        'feeMode' => array(
            'FriendlyName' => 'Gateway Fee Mode',
            'Type' => 'dropdown',
            'Default' => 'merchant_absorbs',
            'Options' => array(
                'merchant_absorbs' => 'Merchant Absorbs Fee (Default)',
                'client_pays' => 'Client Pays Fee (Surcharge)',
            ),
            'Description' => 'How to handle Razorpay processing fees:<br/><strong>Merchant Absorbs:</strong> Client pays invoice amount, you absorb the fee<br/><strong>Client Pays:</strong> Client pays invoice + fee, you receive full amount',
        ),
        'last_synced_at' => array(
            'FriendlyName' => 'Last Sync Timestamp',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Internal field for tracking last sync checkpoint. Do not modify manually.',
        )
    );
}

/**
* @codeCoverageIgnore
*/
function getRazorpayApiInstance($params)
{
    $key    = $params['keyId'];
    $secret = $params['keySecret'];

    return new Api($key, $secret);
}

/**
 * Create the session key name
 * @param  int $order_no
 * @return
 */
function getOrderSessionKey($order_no)
{
    return RAZORPAY_ORDER_ID . $order_no;
}

/**
 * Create razorpay order id
 * @param  array  $params
 * @return string
 */
function createRazorpayOrderId(array $params)
{
    $api = getRazorpayApiInstance($params);

    // Enhanced input validation
    if (!isset($params['invoiceid']) || !is_numeric($params['invoiceid'])) {
        logTransaction(razorpay_MetaData()['DisplayName'], 'Invalid invoice ID', 'Validation Error');
        return false;
    }
    
    if (!isset($params['amount']) || $params['amount'] <= 0) {
        logTransaction(razorpay_MetaData()['DisplayName'], 'Invalid amount', 'Validation Error');
        return false;
    }
    
    if (!isset($params['currency']) || empty($params['currency'])) {
        logTransaction(razorpay_MetaData()['DisplayName'], 'Invalid currency', 'Validation Error');
        return false;
    }

    $data = array(
        'receipt'         => $params['invoiceid'],
        'amount'          => (int) round($params['amount'] * 100),
        'currency'        => $params['currency'],
        'payment_capture' => ($params['paymentAction'] === AUTHORIZE) ? 0 : 1,
        'notes'           => array(
            WHMCS_ORDER_ID  => (string) $params['invoiceid'],
            'whmcs_version' => $params['whmcsVersion'] ?? 'unknown',
            'environment'   => $params['environment'] ?? 'auto',
        ),
    );

    try
    {
        $razorpayOrder = $api->order->create($data);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), 'API Error - Order Creation Failed');
        return $e;
    }

    $razorpayOrderId = $razorpayOrder['id'];

    $sessionKey = getOrderSessionKey($params['invoiceid']);

    $_SESSION[$sessionKey] = $razorpayOrderId;

    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);

    if ((isset($params['invoiceid']) === false) or
        (isset($razorpayOrderId) === false))
    {
        $error = [
            "invoice_id" => $params['invoiceid'],
            "razorpay_order_id" => $razorpayOrderId
        ];
        logTransaction(razorpay_MetaData()['DisplayName'], $error, "Validation Failure");
        return false;
    }

    try
    {
        $rzpOrderMapping->insertOrder($params['invoiceid'], $razorpayOrderId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Insert Order");
    }

    return $razorpayOrderId;
}

function getExistingOrderDetails($params, $razorpayOrderId)
{
    try
    {
        $api = getRazorpayApiInstance($params);
        return $api->order->fetch($razorpayOrderId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), 'Unsuccessful - Fetch existing order failed');
        return false;
    }

}
/**
 * Payment link.
 * Required by third party payment gateway modules only.
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function razorpay_link($params)
{
    // Gateway Configuration Parameters
    $keyId = $params['keyId'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'] * 100; // Required to be converted to Paisa.
    $currencyCode = $params['currency'];
    
    // Enhanced currency validation
    $supportedCurrencies = explode(',', $params['supportedCurrencies'] ?? 'INR');
    $supportedCurrencies = array_map('trim', $supportedCurrencies);
    
    if (!in_array($currencyCode, $supportedCurrencies)) {
        logTransaction(razorpay_MetaData()['DisplayName'], "Unsupported currency: $currencyCode", 'Currency Error');
        return '<div class="alert alert-danger">Currency ' . $currencyCode . ' is not supported. Please contact support.</div>';
    }

    // Client Parameters
    $name = $params['clientdetails']['firstname'].' '.$params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $contact = $params['clientdetails']['phonenumber'];

    // System Parameters
    $whmcsVersion = $params['whmcsVersion'];
    $razorpayWHMCSVersion = RAZORPAY_WHMCS_VERSION;
    $checkoutUrl = 'https://checkout.razorpay.com/v1/checkout.js';
    $callbackUrl = (substr($params['systemurl'], -1) === '/') ? $params['systemurl'] . 'modules/gateways/callback/razorpay.php?merchant_order_id=' . $invoiceId : $params['systemurl'] . '/modules/gateways/callback/razorpay.php?merchant_order_id=' . $invoiceId;

    $rzpOrderMapping = new RZPOrderMapping(razorpay_MetaData()['DisplayName']);
    $existingRazorpayOrderId = null;

    try
    {
        $existingRazorpayOrderId = $rzpOrderMapping->getRazorpayOrderID($invoiceId);
    }
    catch (Exception $e)
    {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), "Unsuccessful - Fetch Order");
    }

    if (isset($existingRazorpayOrderId) === false)
    {
        $razorpayOrderId = createRazorpayOrderId($params);
    }
    else
    {
        $existingOrder = getExistingOrderDetails($params, $existingRazorpayOrderId);

        if (isset($existingOrder) === true and
            ((int)$existingOrder['amount']) !== ((int)$amount))
        {
            $razorpayOrderId = createRazorpayOrderId($params);
        }
        else
        {
            $razorpayOrderId = $existingRazorpayOrderId;
        }
    }

    // Handle error cases from createRazorpayOrderId()
    if ($razorpayOrderId === false || $razorpayOrderId === null || $razorpayOrderId instanceof Exception) {
        logTransaction(razorpay_MetaData()['DisplayName'], 'Failed to create Razorpay order', 'Order Creation Error');
        return '<div class="alert alert-danger">Unable to process payment. Please try again or contact support.</div>';
    }

    return <<<EOT
<form name="razorpay-form" id="razorpay-form" action="$callbackUrl" method="POST">
    <input type="hidden" name="merchant_order_id" id="merchant_order_id" value="$invoiceId"/>
    <script src="$checkoutUrl"
        data-key            = "$keyId"
        data-amount         = "$amount"
        data-currency       = "$currencyCode"
        data-order_id       = "$razorpayOrderId"
        data-description    = "Inv#$invoiceId"

        data-prefill.name   = "$name"
        data-prefill.email  = "$email"
        data-prefill.contact= "$contact"

        data-notes.whmcs_order_id = "$invoiceId"
        data-notes.whmcs_version  = "$whmcsVersion"

        data-_.integration                = "whmcs"
        data-_.integration_version        = "$razorpayWHMCSVersion"
        data-_.integration_parent_version = "$whmcsVersion"
        data-_.integration_type           = "plugin"
    ></script>
</form>
EOT;
}

/**
 * Refund function - CRITICAL FIX
 * @param array $params Refund parameters
 * @return array
 */
function razorpay_refund($params)
{
    try {
        $api = getRazorpayApiInstance($params);
        
        // Validate required parameters
        if (empty($params['transid'])) {
            return array(
                'status' => 'error',
                'rawdata' => 'Transaction ID is required for refund',
                'declinereason' => 'Missing transaction ID'
            );
        }
        
        if (empty($params['amount']) || $params['amount'] <= 0) {
            return array(
                'status' => 'error',
                'rawdata' => 'Valid refund amount is required',
                'declinereason' => 'Invalid refund amount'
            );
        }
        
        // Prepare refund data
        $refundData = array(
            'amount' => (int) round($params['amount'] * 100), // Convert to paisa
            'notes' => array(
                'whmcs_invoice_id' => $params['invoiceid'],
                'whmcs_refund_reason' => $params['reason'] ?? 'Refund via WHMCS',
                'refunded_by' => 'WHMCS Admin'
            )
        );
        
        // Create refund
        $refund = $api->payment->refund($params['transid'], $refundData);
        
        if ($refund && isset($refund['id'])) {
            logTransaction(razorpay_MetaData()['DisplayName'], array(
                'refund_id' => $refund['id'],
                'payment_id' => $params['transid'],
                'amount' => $params['amount'],
                'status' => $refund['status']
            ), 'Refund Successful');
            
            return array(
                'status' => 'success',
                'rawdata' => $refund,
                'transid' => $refund['id'],
                'fees' => 0
            );
        } else {
            return array(
                'status' => 'error',
                'rawdata' => 'Refund creation failed',
                'declinereason' => 'API Error'
            );
        }
        
    } catch (Exception $e) {
        logTransaction(razorpay_MetaData()['DisplayName'], $e->getMessage(), 'Refund Error');
        
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
            'declinereason' => 'Refund Failed: ' . $e->getMessage()
        );
    }
}

/**
 * Callback handler for payment completion - CRITICAL FIX
 * @param array $params Callback parameters
 * @return void
 */
function razorpay_callback($params)
{
    // This function handles the callback from Razorpay checkout
    // The actual payment processing is handled by webhook
    // This is a placeholder for future callback handling if needed
    
    logTransaction(razorpay_MetaData()['DisplayName'], 'Callback received', 'Info');
    
    // Redirect to invoice page or success page
    $redirectUrl = $params['systemurl'] . 'viewinvoice.php?id=' . $params['invoiceid'];
    header('Location: ' . $redirectUrl);
    exit;
}
