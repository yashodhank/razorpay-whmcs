<?php
/**
 * Razorpay Backups Directory
 * 
 * This file prevents directory browsing for security.
 */

http_response_code(403);
die('Access Denied');
