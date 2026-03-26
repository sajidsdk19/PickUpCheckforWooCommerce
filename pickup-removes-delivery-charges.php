<?php
/**
 * Plugin Name:       Pickup Removes Delivery Charges for WooCommerce
 * Plugin URI:        https://sajidkhan.me/
 * Description:       Adds a "Select how you would like to receive your order" dropdown on checkout. When "I'll pick it up myself" is selected, all shipping rates and custom delivery fees are zeroed out dynamically via AJAX.
 * Version:           1.2.0
 * Author:            Sajid Khan
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prdc-woo
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// 1. ADD CUSTOM DROPDOWN FIELD TO CHECKOUT
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'woocommerce_checkout_fields', 'prdc_add_delivery_method_field' );

function prdc_add_delivery_method_field( $fields ) {
    $saved = WC()->session ? WC()->session->get( 'prdc_delivery_method', '' ) : '';

    $fields['billing']['prdc_delivery_method'] = array(
        'type'     => 'select',
        'label'    => __( 'Select how you would like to receive your order', 'prdc-woo' ),
        'required' => true,
        'priority' => 5,
        'class'    => array( 'form-row-wide' ),
        'clear'    => true,
        'options'  => array(
            ''         => __( '-- Please select --', 'prdc-woo' ),
            'delivery' => __( 'Deliver to address', 'prdc-woo' ),
            'pickup'   => __( "I'll pick it up myself", 'prdc-woo' ),
        ),
        'default'  => $saved,
    );

    return $fields;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. VALIDATE THE FIELD
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_process', 'prdc_validate_delivery_method' );

function prdc_validate_delivery_method() {
    if ( empty( $_POST['prdc_delivery_method'] ) ) {
        wc_add_notice( __( 'Please select how you would like to receive your order.', 'prdc-woo' ), 'error' );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. SAVE TO SESSION + FORCE SHIPPING RECALCULATION
//    FIX: Only unset the valid shipping session key (not a non-existent _rates key)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_update_order_review', 'prdc_save_method_to_session' );

function prdc_save_method_to_session( $posted_data ) {
    parse_str( $posted_data, $fields );

    $method = '';

    // Priority 1: THWCFE billing_delivery field (Option_1 = delivery, Option_2 = pickup)
    if ( isset( $fields['billing_delivery'] ) ) {
        $raw = sanitize_text_field( $fields['billing_delivery'] );
        if ( 'Option_2' === $raw ) {
            $method = 'pickup';
        } elseif ( 'Option_1' === $raw ) {
            $method = 'delivery';
        }
    }

    // Priority 2: plugin's own dropdown (fallback)
    if ( '' === $method && isset( $fields['prdc_delivery_method'] ) ) {
        $raw_method = sanitize_key( $fields['prdc_delivery_method'] );
        if ( in_array( $raw_method, array( 'pickup', 'delivery' ), true ) ) {
            $method = $raw_method;
        }
    }

    if ( WC()->session ) {
        WC()->session->set( 'prdc_delivery_method', $method );
    }

    // FIX: Only unset the real WC shipping cache key (no bogus _rates suffix).
    if ( 'pickup' === $method && WC()->session ) {
        $packages = WC()->cart->get_shipping_packages();
        foreach ( array_keys( $packages ) as $package_key ) {
            WC()->session->__unset( 'shipping_for_package_' . $package_key );
        }
    }
    // WooCommerce recalculates shipping & totals automatically after this hook.
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. ZERO OUT ALL SHIPPING RATES WHEN PICKUP IS SELECTED
//    FIX: Removed invalid ->description property (WC_Shipping_Rate has no such
//         property — setting it creates a dynamic property and throws a PHP 8.2
//         deprecation warning / fatal in future PHP versions).
//         Use ->label instead if you need to annotate the rate label.
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'woocommerce_package_rates', 'prdc_zero_shipping_for_pickup', 20, 2 );

function prdc_zero_shipping_for_pickup( $rates, $package ) {
    $method = WC()->session ? WC()->session->get( 'prdc_delivery_method', '' ) : '';

    if ( 'pickup' === $method ) {
        foreach ( $rates as $rate_id => $rate ) {
            $rates[ $rate_id ]->cost  = 0;
            $rates[ $rate_id ]->taxes = array();
            // FIX: WC_Shipping_Rate exposes ->label (not ->description).
            // Append pickup note to the label safely.
            $rates[ $rate_id ]->label = trim( $rates[ $rate_id ]->label )
                . ' (' . esc_html__( 'Pickup — Free', 'prdc-woo' ) . ')';
        }
    }

    return $rates;
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. ZERO CUSTOM DELIVERY FEES WHEN PICKUP IS SELECTED
//    FIX: Removed the dangerous direct manipulation of fees_api()->fees (an
//         internal WC object property). Fees are recalculated from scratch on
//         every AJAX call, so we simply skip adding them when delivery is
//         selected — no removal step is needed.
//    FIX: Added an inner static guard so the offset fee can never compound
//         across multiple runs in the same request.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_cart_calculate_fees', 'prdc_maybe_zero_delivery_fee', 99 );

function prdc_maybe_zero_delivery_fee( $cart ) {
    // Skip on admin non-AJAX calls.
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    $method = WC()->session ? WC()->session->get( 'prdc_delivery_method', '' ) : '';

    // Only act when pickup is selected; for delivery do nothing —
    // the offset fee is never added so it can never appear.
    if ( 'pickup' !== $method ) {
        return;
    }

    // Keywords that identify a delivery fee added by other plugins/themes.
    $delivery_keywords = array( 'delivery', 'shipping', 'courier', 'freight', 'charge' );
    $offset            = 0.0;

    foreach ( $cart->get_fees() as $fee ) {
        $fee_name_lower = strtolower( $fee->name );
        $fee_slug       = sanitize_title( $fee->name );

        // Guard: skip our own offset fee to prevent compounding across
        // multiple calculate_fees calls in the same request.
        if ( false !== strpos( $fee_slug, 'pickup-discount' )
            || false !== strpos( $fee_slug, 'pickup_discount' )
        ) {
            continue;
        }

        foreach ( $delivery_keywords as $keyword ) {
            if ( false !== strpos( $fee_name_lower, $keyword ) ) {
                $offset -= (float) $fee->amount;
                break;
            }
        }
    }

    if ( $offset < 0 ) {
        $cart->add_fee(
            __( 'Pickup Discount (Delivery Waived)', 'prdc-woo' ),
            $offset,
            false, // not taxable
            ''
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. SAVE TO ORDER META
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_create_order', 'prdc_save_method_to_order_meta', 10, 2 );

function prdc_save_method_to_order_meta( $order, $data ) {
    $method = isset( $_POST['prdc_delivery_method'] )
        ? sanitize_key( $_POST['prdc_delivery_method'] )
        : '';

    if ( in_array( $method, array( 'delivery', 'pickup' ), true ) ) {
        $labels = array(
            'delivery' => __( 'Delivery', 'prdc-woo' ),
            'pickup'   => __( "I'll pick it up myself", 'prdc-woo' ),
        );

        $order->update_meta_data( '_prdc_delivery_method', $method );
        $order->update_meta_data( '_prdc_delivery_method_label', $labels[ $method ] );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. DISPLAY IN ADMIN ORDER PAGE
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_billing_address', 'prdc_display_method_in_admin', 10, 1 );

function prdc_display_method_in_admin( $order ) {
    $label = $order->get_meta( '_prdc_delivery_method_label' );

    if ( $label ) {
        echo '<p><strong>' . esc_html__( 'Delivery / Pickup:', 'prdc-woo' ) . '</strong> ' . esc_html( $label ) . '</p>';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. ENQUEUE JAVASCRIPT
//    FIX: Added explicit 'after' position argument to wp_add_inline_script
//         to guarantee the code runs after jQuery is defined.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'prdc_enqueue_checkout_script' );

function prdc_enqueue_checkout_script() {
    if ( ! is_checkout() ) {
        return;
    }

    wp_add_inline_script(
        'jquery',
        '
        jQuery( function( $ ) {
            "use strict";

            function prdc_trigger_update() {
                $( document.body ).trigger( "update_checkout" );
            }

            // THWCFE billing_delivery field changes → sync plugin field, then update totals.
            $( document ).on( "change", "#billing_delivery", function() {
                var val    = $( this ).val();
                var mapped = ( val === "Option_2" ) ? "pickup"
                           : ( val === "Option_1" ) ? "delivery" : "";
                $( "#prdc_delivery_method" ).val( mapped ); // no .trigger() — avoids loop
                prdc_trigger_update();
            } );

            // Plugin field changes → sync THWCFE field in the same way.
            $( document ).on( "change", "#prdc_delivery_method", function() {
                var val    = $( this ).val();
                var mapped = ( val === "pickup" )   ? "Option_2"
                           : ( val === "delivery" ) ? "Option_1" : "";
                if ( mapped ) { $( "#billing_delivery" ).val( mapped ); } // no .trigger()
                prdc_trigger_update();
            } );
        } );
        ',
        'after'  // FIX: explicit position — code executes after jQuery is loaded
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 9. CLEANUP SESSION ON ORDER COMPLETE / CART EMPTY
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_thankyou', 'prdc_clear_session_method' );
add_action( 'woocommerce_cart_emptied', 'prdc_clear_session_method' );

function prdc_clear_session_method( $order_id = null ) {
    if ( WC()->session ) {
        WC()->session->__unset( 'prdc_delivery_method' );
    }
}