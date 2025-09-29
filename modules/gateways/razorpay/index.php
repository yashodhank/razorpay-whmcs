<?php
/**
 * Razorpay WHMCS Gateway Module
 * 
 * This file prevents directory browsing for security.
 */

// Redirect to WHMCS admin or show access denied
if (defined('WHMCS')) {
    header('Location: ../../../admin/');
} else {
    http_response_code(403);
    die('Access Denied');
}
