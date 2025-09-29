# Changelog

All notable changes to the Razorpay WHMCS Gateway Module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.1] - 2025-01-27

### Added
- WHMCS 6/7/8 compatibility with feature detection
- PHP 5.6+ support with graceful fallbacks
- Comprehensive utility scripts for maintenance
- Enhanced webhook processing with better error handling
- Gateway fee handling modes (merchant absorbs vs client pays)
- Multi-currency support with validation
- Timezone handling using WHMCS settings
- Production-ready error logging and debugging
- Cross-check tools for payment reconciliation
- Late fee handling utilities
- Payment synchronization tools
- Webhook diagnostic tools
- Enhanced security with signature verification
- Comprehensive documentation and setup guides

### Changed
- Reorganized directory structure for better maintainability
- Improved error handling throughout the module
- Enhanced webhook processing logic
- Better database query handling with fallbacks
- Improved payment recording with proper date handling
- Enhanced refund processing
- Better currency validation

### Fixed
- Fixed deprecated MySQL functions for WHMCS 8 compatibility
- Fixed timezone handling for payment timestamps
- Fixed webhook signature verification
- Fixed payment amount validation
- Fixed order mapping issues
- Fixed callback processing errors
- Fixed refund processing bugs

### Security
- Enhanced webhook signature verification
- Added constant-time string comparison
- Improved input validation
- Added proper error handling to prevent information disclosure

### Performance
- Optimized database queries
- Added batch processing for large datasets
- Improved memory usage
- Enhanced error logging efficiency

## [2.1.0] - 2024-12-15

### Added
- Initial release with basic Razorpay integration
- WHMCS 7+ support
- Basic webhook processing
- Simple payment recording
- Basic refund support

### Known Issues
- Limited to WHMCS 7+ only
- No PHP 5.6 support
- Basic error handling
- Limited utility tools

## [2.0.0] - 2024-11-01

### Added
- Initial development version
- Basic Razorpay SDK integration
- Simple payment processing

---

## Upgrade Notes

### Upgrading from v2.1.0 to v2.2.1

1. **Backup your current installation**
2. **Update files**:
   - Replace `modules/gateways/razorpay.php`
   - Replace `modules/gateways/callback/razorpay.php`
   - Replace the entire `modules/gateways/razorpay/` directory
3. **Update configuration**:
   - No configuration changes required
   - Existing settings will be preserved
4. **Test thoroughly**:
   - Test payment processing
   - Verify webhook functionality
   - Check refund processing

### Upgrading from v2.0.0 to v2.2.1

1. **Backup your current installation**
2. **Update files** (same as above)
3. **Update configuration**:
   - Reconfigure webhook settings
   - Update API keys if needed
   - Set gateway fee mode preference
4. **Test thoroughly** (same as above)

## Breaking Changes

### v2.2.1
- None (backward compatible)

### v2.1.0
- Removed support for WHMCS 6 (temporarily)
- Changed webhook processing logic

## Deprecations

### v2.2.1
- None

### v2.1.0
- Deprecated legacy MySQL functions (replaced with Capsule)

## Migration Guide

### From Custom Razorpay Integration

If you have a custom Razorpay integration and want to migrate to this module:

1. **Install the module** following the installation guide
2. **Configure API keys** in WHMCS admin
3. **Set up webhooks** in Razorpay dashboard
4. **Test payment processing** with test transactions
5. **Migrate existing data** using the sync tools if needed
6. **Update your templates** to use the new gateway

### From Other Payment Gateways

If you're migrating from another payment gateway:

1. **Install Razorpay module** alongside existing gateway
2. **Configure Razorpay** with test mode first
3. **Test thoroughly** with test transactions
4. **Update client templates** to show Razorpay option
5. **Monitor both gateways** during transition period
6. **Disable old gateway** once confident in Razorpay

## Support

For upgrade assistance or issues:
- GitHub Issues: [Report problems](https://github.com/yourusername/razorpay-whmcs-gateway/issues)
- Documentation: [Full documentation](https://github.com/yourusername/razorpay-whmcs-gateway/wiki)
- Community: [WHMCS Community Forum](https://whmcs.community/)
