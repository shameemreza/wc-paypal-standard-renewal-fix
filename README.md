# WooCommerce PayPal Standard Renewal Fix

This mini plugin fixes issues with WooCommerce Subscriptions renewal orders when using PayPal Standard as the payment gateway.

## Problem

In some cases, when a subscription's payment method has been changed, PayPal Standard processes renewal payments successfully, but WooCommerce doesn't create corresponding renewal orders. This typically occurs when:

1. A payment method change occurs (shown by "IPN subscription failing payment method changed" message in logs)
2. PayPal sends a payment notification ("IPN subscription payment completed")
3. No renewal order is created in WooCommerce

## Solution

This plugin adds additional hooks to ensure renewal orders are properly created when PayPal Standard processes subscription payments, even if the payment method has been changed previously.

Key features:

- Intercepts PayPal IPN notifications.
- Checks if a renewal order should exist but doesn't.
- Creates missing renewal orders for successful payments.
- Ensures orders have the correct payment method.
- Provides detailed logging to troubleshoot issues.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/wc-paypal-standard-renewal-fix` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. No configuration is required - the plugin works automatically.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- WooCommerce Subscriptions 4.0.0+
- PHP 7.3+

## Logs

The plugin logs its actions in the WooCommerce logs section with the source name `paypal-renewal-fix`.
You can view these logs in WooCommerce → Status → Logs.

## Support

This is a custom plugin created to solve a specific issue. If you need support, please hire a developer.
