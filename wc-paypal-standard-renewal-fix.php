<?php
/**
 * Plugin Name: WooCommerce PayPal Standard Renewal Fix
 * Plugin URI: https://woocommerce.com/
 * Description: Fixes issues with PayPal Standard subscription renewals when payment method has been changed.
 * Version: 1.0.0
 * Author: Shameem Reza
 * Author URI: https://woocommerce.com/
 * Text Domain: wc-paypal-standard-renewal-fix
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.3
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) || exit;

class WC_PayPal_Standard_Renewal_Fix {

    /**
     * Constructor.
     */
    public function __construct() {
        // Add a debug logger
        add_action( 'init', array( $this, 'setup_logger' ) );
        
        // Hook into the PayPal IPN processing
        add_action( 'valid-paypal-standard-ipn-request', array( $this, 'handle_paypal_ipn_request' ), 9 );
        
        // Hook into the completed payment to ensure renewal orders are created
        add_filter( 'wcs_renewal_order_created', array( $this, 'ensure_valid_renewal_order' ), 10, 2 );
        
        // Add a custom IPN handler to catch subscription payments
        add_action( 'woocommerce_api_wc_gateway_paypal', array( $this, 'maybe_handle_custom_ipn' ), 5 );
    }

    /**
     * Set up a logger instance.
     */
    public function setup_logger() {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }
        $this->logger = wc_get_logger();
    }

    /**
     * Log debug messages.
     *
     * @param string $message
     */
    public function log( $message ) {
        if ( isset( $this->logger ) ) {
            $this->logger->debug( $message, array( 'source' => 'paypal-renewal-fix' ) );
        }
    }

    /**
     * Handle custom IPN requests to catch subscription payments that might be missed.
     */
    public function maybe_handle_custom_ipn() {
        if ( ! isset( $_POST['txn_type'] ) || ! in_array( $_POST['txn_type'], array( 'subscr_payment', 'recurring_payment' ) ) ) {
            return;
        }

        $this->log( 'Detected subscription payment IPN: ' . print_r( $_POST, true ) );
    }

    /**
     * Handle PayPal IPN requests for subscription payments.
     *
     * @param array $posted The IPN request data
     */
    public function handle_paypal_ipn_request( $posted ) {
        if ( ! isset( $posted['txn_type'] ) || ! in_array( $posted['txn_type'], array( 'subscr_payment', 'recurring_payment' ) ) ) {
            return;
        }

        $this->log( 'Processing subscription payment IPN: ' . $posted['txn_type'] );

        // Only proceed if this is a completed payment
        if ( isset( $posted['payment_status'] ) && 'completed' !== strtolower( $posted['payment_status'] ) ) {
            return;
        }

        // Check if we have a subscription ID
        if ( empty( $posted['custom'] ) ) {
            return;
        }

        $custom = json_decode( wp_unslash( $posted['custom'] ) );
        
        // No subscription data found
        if ( ! is_object( $custom ) || ! isset( $custom->subscription_id ) ) {
            return;
        }

        $subscription_id = $custom->subscription_id;
        $subscription = wcs_get_subscription( $subscription_id );

        if ( ! $subscription ) {
            $this->log( 'Could not find subscription #' . $subscription_id );
            return;
        }

        $this->log( 'Found subscription #' . $subscription_id );

        // Check if a renewal order for this transaction already exists
        $transaction_id = isset( $posted['txn_id'] ) ? $posted['txn_id'] : '';
        
        if ( empty( $transaction_id ) ) {
            $this->log( 'No transaction ID found in IPN request' );
            return;
        }

        // Get all renewal orders for this subscription
        $renewal_orders = $subscription->get_related_orders( 'ids', 'renewal' );
        $transaction_recorded = false;

        foreach ( $renewal_orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && $order->get_transaction_id() === $transaction_id ) {
                $transaction_recorded = true;
                break;
            }
        }

        // If no renewal order exists for this transaction, create one
        if ( ! $transaction_recorded && 'active' === $subscription->get_status() ) {
            $this->log( 'Creating missing renewal order for subscription #' . $subscription_id );
            
            try {
                // Create the renewal order
                $renewal_order = wcs_create_renewal_order( $subscription );
                
                // Set PayPal as the payment method
                if ( $renewal_order && ! is_wp_error( $renewal_order ) ) {
                    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
                    
                    if ( isset( $available_gateways['paypal'] ) ) {
                        $renewal_order->set_payment_method( $available_gateways['paypal'] );
                    }
                    
                    // Set the transaction ID and mark the order as paid
                    $renewal_order->set_transaction_id( $transaction_id );
                    $renewal_order->payment_complete( $transaction_id );
                    $renewal_order->add_order_note( 
                        sprintf( __( 'PayPal IPN payment completed via %s. Transaction ID: %s', 'wc-paypal-standard-renewal-fix' ), 
                            __( 'PayPal Standard Renewal Fix', 'wc-paypal-standard-renewal-fix' ),
                            $transaction_id
                        )
                    );
                    
                    $renewal_order->save();
                    
                    $this->log( 'Created and completed renewal order #' . $renewal_order->get_id() );
                }
            } catch ( Exception $e ) {
                $this->log( 'Error creating renewal order: ' . $e->getMessage() );
            }
        } else {
            $this->log( 'Transaction already recorded or subscription not active' );
        }
    }

    /**
     * Ensure the renewal order is valid and has the correct payment method.
     *
     * @param WC_Order $renewal_order
     * @param WC_Subscription $subscription
     * @return WC_Order
     */
    public function ensure_valid_renewal_order( $renewal_order, $subscription ) {
        if ( 'paypal' === $subscription->get_payment_method() && ! is_wp_error( $renewal_order ) ) {
            // Make sure the renewal order has PayPal as the payment method
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            
            if ( isset( $available_gateways['paypal'] ) && $renewal_order->get_payment_method() !== 'paypal' ) {
                $renewal_order->set_payment_method( $available_gateways['paypal'] );
                $renewal_order->save();
                
                $this->log( 'Updated payment method to PayPal for renewal order #' . $renewal_order->get_id() );
            }
        }
        
        return $renewal_order;
    }
}

// Initialize the plugin
new WC_PayPal_Standard_Renewal_Fix(); 
