# Razorpay WHMCS Gateway Module - Installation Guide

This guide will walk you through installing the Razorpay payment gateway module in your WHMCS installation.

## 📋 Prerequisites

Before installing the module, ensure you have:

- **WHMCS 6.0+** (tested on 6.3, 7.10, 8.13)
- **PHP 5.6+** (tested on 5.6, 7.0, 7.4, 8.0, 8.1, 8.2)
- **Active Razorpay Account** with API keys
- **SSL Certificate** (required for webhook processing)
- **Admin access** to your WHMCS installation

## 🚀 Quick Installation

### Method 1: Automated Installation (Recommended)

1. **Upload the module files** to your WHMCS root directory
2. **Run the installer**:
   ```bash
   cd /path/to/your/whmcs
   php install.php
   ```
3. **Follow the on-screen prompts**
4. **Configure the gateway** in WHMCS Admin

### Method 2: Manual Installation

1. **Upload main gateway file**:
   ```bash
   cp razorpay.php /path/to/whmcs/modules/gateways/
   ```

2. **Upload callback file**:
   ```bash
   cp callback/razorpay.php /path/to/whmcs/modules/gateways/callback/
   ```

3. **Upload module directory**:
   ```bash
   cp -r razorpay/ /path/to/whmcs/modules/gateways/
   ```

4. **Set proper permissions**:
   ```bash
   chmod 644 /path/to/whmcs/modules/gateways/razorpay.php
   chmod 644 /path/to/whmcs/modules/gateways/callback/razorpay.php
   chmod -R 755 /path/to/whmcs/modules/gateways/razorpay/
   ```

## ⚙️ Configuration

### Step 1: Get Razorpay API Keys

1. **Login to Razorpay Dashboard**: https://dashboard.razorpay.com
2. **Go to Settings → API Keys**
3. **Generate API Keys** (if not already done)
4. **Copy your Key ID and Key Secret**

### Step 2: Configure Gateway in WHMCS

1. **Login to WHMCS Admin**
2. **Go to Setup → Payments → Payment Gateways**
3. **Find "Razorpay" and click "Configure"**
4. **Fill in the required fields**:

   **Required Settings:**
   - **Key ID**: Your Razorpay Key ID
   - **Key Secret**: Your Razorpay Key Secret
   - **Webhook Secret**: (Leave empty for now, we'll set this up next)
   - **Enable Webhook**: Set to **Yes**

   **Optional Settings:**
   - **Gateway Fee Mode**: Choose how to handle Razorpay fees
     - **Merchant Absorbs Fee** (Default): You pay the fee
     - **Client Pays Fee**: Customer pays the fee
   - **Supported Currencies**: Add currencies you want to support
     - Default: `INR,USD,EUR,GBP,AED,SGD`
     - Add more as needed: `INR,USD,EUR,GBP,AED,SGD,JPY,CAD,AUD`

5. **Save Configuration**

### Step 3: Set Up Webhooks (CRITICAL)

⚠️ **Without webhooks, payments will not be recorded in WHMCS!**

1. **In Razorpay Dashboard → Settings → Webhooks**
2. **Click "Add New Webhook"**
3. **Enter Webhook URL**:
   ```
   https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php
   ```
   Replace `yourdomain.com` with your actual domain.

4. **Select these events**:
   - ✅ `payment.captured`
   - ✅ `order.paid`
   - ✅ `refund.created`
   - ✅ `refund.processed`

5. **Click "Create Webhook"**
6. **Copy the Webhook Secret** (you'll need this for WHMCS)

### Step 4: Complete WHMCS Configuration

1. **Go back to WHMCS → Setup → Payments → Payment Gateways**
2. **Find "Razorpay" and click "Configure"**
3. **Paste the Webhook Secret** from Razorpay Dashboard
4. **Save Configuration**

## 🧪 Testing

### Test Mode Setup

1. **Use Razorpay Test API Keys** for initial testing
2. **Create a test invoice** in WHMCS
3. **Process a test payment** using Razorpay test cards
4. **Verify the payment appears** in WHMCS

### Test Cards

Use these test card numbers in Razorpay test mode:

| Card Number | Description |
|-------------|-------------|
| `4111 1111 1111 1111` | Successful payment |
| `4000 0000 0000 0002` | Failed payment |
| `4000 0000 0000 0002` | 3D Secure payment |

### Verification Steps

1. **Check Gateway Logs**: WHMCS Admin → Utilities → Logs → Gateway Log
2. **Look for "Webhook Received" entries**
3. **Verify invoice status** changes to "Paid"
4. **Check payment appears** in WHMCS transactions

## 🔧 Advanced Configuration

### Multi-Currency Support

To support additional currencies:

1. **Add currencies** to the "Supported Currencies" field
2. **Ensure currencies are supported** by Razorpay
3. **Test with each currency** before going live

### Gateway Fee Modes

**Merchant Absorbs Fee (Default):**
- Customer pays invoice amount only
- You absorb the Razorpay processing fee
- Recommended for most businesses

**Client Pays Fee (Surcharge):**
- Customer pays invoice amount + processing fee
- You receive the full invoice amount
- Useful for high-volume merchants

### Timezone Handling

The module automatically uses your WHMCS timezone setting. No additional configuration required.

## 🛠️ Utility Scripts

The module includes several utility scripts for maintenance:

### Payment Synchronization
```bash
php modules/gateways/razorpay/scripts/sync-payments.php --since=2025-01-01 --limit=50
```

### Webhook Diagnostics
```bash
php modules/gateways/razorpay/scripts/webhook-diagnostic.php
```

### Cross-Check Payments
```bash
php modules/gateways/razorpay/scripts/cross-check-tool.php --since=2025-01-01
```

## 🔍 Troubleshooting

### Common Issues

**Payments not being recorded:**
1. Check if webhook is enabled in WHMCS
2. Verify webhook URL in Razorpay Dashboard
3. Check webhook secret matches in both places
4. Review Gateway Logs for errors

**Signature verification errors:**
1. Verify API keys are correct
2. Check webhook secret is properly configured
3. Ensure webhook events are selected in Razorpay

**Currency not supported:**
1. Add currency to supported currencies list
2. Verify currency is supported by Razorpay
3. Check currency code format (e.g., USD not usd)

### Debug Mode

Enable debug logging by adding to your WHMCS configuration:

```php
// In includes/configure.php
define('RAZORPAY_DEBUG', true);
```

### Log Locations

- **Gateway Logs**: WHMCS Admin → Utilities → Logs → Gateway Log
- **Webhook Logs**: Check Gateway Logs for "Webhook Received" entries
- **Error Logs**: Check your server error logs

## 🔒 Security Considerations

- **Use HTTPS** for webhook URL
- **Keep API keys secure** and never share them
- **Regularly rotate** API keys
- **Monitor webhook logs** for suspicious activity
- **Use test mode** for development and testing

## 📞 Support

### Documentation
- [WHMCS Gateway Development](https://developers.whmcs.com/payment-gateways/)
- [Razorpay API Documentation](https://razorpay.com/docs/)

### Community Support
- GitHub Issues: [Report bugs and request features](https://github.com/yourusername/razorpay-whmcs-gateway/issues)
- WHMCS Community: [WHMCS Community Forum](https://whmcs.community/)

### Professional Support
For production environments requiring guaranteed support:
- Email: support@yourcompany.com
- Response time: 24 hours
- Includes: Priority support, custom modifications, installation assistance

## ✅ Post-Installation Checklist

- [ ] Module files uploaded correctly
- [ ] Gateway configured in WHMCS
- [ ] Razorpay API keys entered
- [ ] Webhook configured in Razorpay Dashboard
- [ ] Webhook secret added to WHMCS
- [ ] Test payment processed successfully
- [ ] Payment appears in WHMCS
- [ ] Refund functionality tested
- [ ] Multi-currency support configured (if needed)
- [ ] Gateway fee mode configured
- [ ] Utility scripts tested
- [ ] Documentation reviewed

## 🎉 You're All Set!

Your Razorpay payment gateway is now installed and configured. You can start accepting payments through Razorpay in your WHMCS installation.

For any issues or questions, please refer to the troubleshooting section or contact support.

---

**Need help?** Check out our [comprehensive documentation](README.md) or [contact support](https://github.com/yourusername/razorpay-whmcs-gateway/issues).
