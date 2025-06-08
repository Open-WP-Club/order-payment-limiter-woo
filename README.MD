# WooCommerce Order Payment Limiter

Automatically cancels WooCommerce orders after failed payment attempts.

## Supported Gateways

- Official WooCommerce Stripe Gateway
- Payment Plugins for Stripe WooCommerce

## Installation

1. Upload plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin
3. Configure at WooCommerce > Payment Limiter

## Settings

- **Max Attempts**: Number of failed payments before auto-cancel (default: 3)
- **Cancel Status**: Order status after cancellation (default: cancelled)
- **Custom Note**: Optional cancellation message
- **Logging**: Enable/disable detailed logs
- **Reset on Success**: Clear attempt counter on successful payment

## How It Works

1. Tracks failed payment attempts per order
2. Adds order notes for each failed attempt
3. Cancels order when max attempts reached
4. Logs all activity to WooCommerce logs

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- One of the supported Stripe plugins

## Hooks

The plugin hooks into:

- `wc_gateway_stripe_process_payment_error` (Official Stripe)
- `wc_stripe_process_charge_failed` (Payment Plugins)
- `woocommerce_payment_failed` (Fallback)

## License

GPL v2 or later
