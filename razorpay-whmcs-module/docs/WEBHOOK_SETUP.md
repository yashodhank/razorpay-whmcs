# Razorpay Webhook Setup Guide

## 🚨 CRITICAL: Webhook Must Be Configured for Payments to Work

Without proper webhook configuration, **payments will not be recorded** in WHMCS, causing invoices to remain unpaid even after successful payment.

## Step 1: Enable Webhook in WHMCS

1. Go to **Setup → Payments → Payment Gateways**
2. Find **Razorpay** and click **Configure**
3. Set **Enable Webhook** to **Yes**
4. Note the **Webhook URL** displayed in the description
5. **Save Configuration**

## Step 2: Configure Webhook in Razorpay Dashboard

1. Login to [Razorpay Dashboard](https://dashboard.razorpay.com)
2. Go to **Settings → Webhooks**
3. Click **Add New Webhook**
4. Enter the **Webhook URL** from WHMCS:
   ```
   https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php
   ```
5. Select these events:
   - ✅ `payment.captured`
   - ✅ `order.paid`
   - ✅ `refund.created`
   - ✅ `refund.processed`
6. Click **Create Webhook**
7. **Copy the Webhook Secret** (you'll need this for WHMCS)

## Step 3: Configure Webhook Secret in WHMCS

1. Go back to **WHMCS → Setup → Payments → Payment Gateways**
2. Find **Razorpay** and click **Configure**
3. Paste the **Webhook Secret** from Razorpay Dashboard
4. **Save Configuration**

## Step 4: Test Webhook

1. Create a test invoice
2. Process a test payment
3. Check **Utilities → Logs → Gateway Log** for webhook activity
4. Verify the invoice status changes to **Paid**

## Troubleshooting

### Webhook Not Working?

1. **Check Gateway Logs**:
   - Go to **Utilities → Logs → Gateway Log**
   - Look for "Webhook Received" entries
   - Check for error messages

2. **Common Issues**:
   - ❌ Webhook disabled in WHMCS
   - ❌ Wrong webhook URL in Razorpay
   - ❌ Missing or incorrect webhook secret
   - ❌ Webhook events not selected in Razorpay
   - ❌ Server firewall blocking webhook requests

3. **Test Webhook Manually**:
   - Use the sync tool: `/modules/gateways/razorpay/sync-payments.php`
   - Use the diagnostic tool: `/modules/gateways/razorpay/webhook-diagnostic.php`
   - Use the late fee handler: `/modules/gateways/razorpay/late-fee-handler.php`
   - These tools will check for missed payments and configuration issues

### Fix Existing Unpaid Invoices

If you have existing unpaid invoices that should be marked as paid:

1. **Run the sync tool**:
   ```
   https://my.securiace.com/modules/gateways/razorpay/sync-payments.php?password=change_me_this_at_earliest_if_using
   ```

2. **Or manually check**:
   - Go to Razorpay Dashboard → Payments
   - Find the payment for the invoice
   - Note the Payment ID
   - Go to WHMCS → Billing → Invoices
   - Find the invoice and add payment manually

### Handle Late Fee Invoices

If you have invoices with late fees that may have been paid before the late fee was added:

1. **Use the Late Fee Handler**:
   ```
   https://my.securiace.com/modules/gateways/razorpay/late-fee-handler.php?password=change_me_this_at_earliest_if_using
   ```

2. **This tool will**:
   - Identify invoices with late fees
   - Search for payments matching the original amount (before late fee)
   - Provide options to handle the payment
   - Allow you to mark invoices as paid for the original amount

3. **Common scenarios**:
   - Customer paid original amount before late fee was added
   - Late fee was added after payment was processed
   - Payment amount doesn't match total due to late fee

4. **Database Requirements**:
   - Late fee handling requires the `latefee` column in `tblinvoices` table
   - This column is available in WHMCS v7.0+ by default
   - For older versions, use: `add-latefee-column.php?password=change_me_this_at_earliest_if_using`

## Security Notes

- **Remove sync tool** after use in production
- **Use HTTPS** for webhook URL
- **Keep webhook secret** secure
- **Monitor webhook logs** regularly

## Support

If you continue to have issues:
1. Check WHMCS Gateway Logs
2. Check Razorpay Dashboard for webhook delivery status
3. Contact support with specific error messages
