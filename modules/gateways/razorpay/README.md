# Razorpay WHMCS Gateway Module

A comprehensive Razorpay payment gateway integration for WHMCS v6/7/8 with PHP 5.6+ compatibility.

## Features

- ✅ **WHMCS 6/7/8 Compatibility** - Works across all supported WHMCS versions
- ✅ **PHP 5.6+ Support** - Graceful fallbacks for older PHP versions
- ✅ **Webhook Processing** - Automatic payment confirmation
- ✅ **Refund Support** - Full and partial refunds
- ✅ **Multi-Currency** - Support for all Razorpay currencies
- ✅ **Gateway Fee Handling** - Configurable fee modes
- ✅ **Timezone Support** - Uses WHMCS timezone settings
- ✅ **Security** - PCI compliant, offsite payment processing

## Directory Structure

```
razorpay/
├── README.md                    # This file
├── rzpordermapping.php         # Order mapping utility
├── razorpay-webhook.php        # Webhook handler
├── lib/
│   └── razorpay-sdk/           # Official Razorpay PHP SDK
├── scripts/                    # Utility scripts and tools
│   ├── sync-payments.php       # Payment synchronization tool
│   ├── cross-check-tool.php    # Cross-check payments
│   ├── webhook-diagnostic.php  # Webhook diagnostics
│   └── ...                     # Other utility scripts
├── docs/                       # Documentation
│   └── WEBHOOK_SETUP.md        # Webhook setup guide
└── backups/                    # Backup files
    └── *.bak.*                 # Timestamped backups
```

## Installation

1. Upload the `razorpay` directory to `/modules/gateways/`
2. Upload `razorpay.php` to `/modules/gateways/`
3. Upload `callback/razorpay.php` to `/modules/gateways/callback/`
4. Configure the gateway in WHMCS Admin → Setup → Payments → Payment Gateways

## Configuration

### Required Settings
- **Key ID**: Your Razorpay Key ID
- **Key Secret**: Your Razorpay Key Secret
- **Webhook Secret**: Your Razorpay Webhook Secret

### Optional Settings
- **Gateway Fee Mode**: How to handle Razorpay fees
  - Merchant Absorbs Fee (Default)
  - Client Pays Fee (Surcharge)
- **Enable Webhook**: Enable automatic payment confirmation

## Webhook Setup

1. In Razorpay Dashboard → Settings → Webhooks
2. Add webhook URL: `https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php`
3. Select events: `payment.captured`, `refund.processed`
4. Copy the webhook secret to WHMCS configuration

## Utility Scripts

### Payment Synchronization
```bash
php scripts/sync-payments.php --since=2025-01-01 --limit=50
```

### Cross-Check Payments
```bash
php scripts/cross-check-tool.php --since=2025-01-01
```

### Webhook Diagnostics
```bash
php scripts/webhook-diagnostic.php
```

## Compatibility

- **WHMCS**: 6.0+ (tested on 6.3, 7.10, 8.13)
- **PHP**: 5.6+ (tested on 5.6, 7.0, 7.4, 8.0, 8.1, 8.2)
- **Razorpay SDK**: 2.8.0+

## Security

- All payment processing is handled offsite by Razorpay
- No sensitive card data is stored locally
- Webhook signature verification
- PCI DSS compliant

## Support

For issues and support, please check:
1. WHMCS Gateway Logs
2. Webhook Diagnostic Tool
3. Payment Synchronization Tool

## Changelog

### v2.2.1
- Added WHMCS 6/7/8 compatibility
- Added PHP 5.6+ support
- Improved timezone handling
- Enhanced error handling
- Added utility scripts
- Reorganized directory structure
