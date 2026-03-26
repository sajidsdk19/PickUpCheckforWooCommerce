<?php
/**
 * Plugin Name:       Stroopwafel Oman - Conditional Fees (Delivery + Minimum Order)
 * Description:       Removes BOTH Delivery Charges (2.00) AND Small Order Fee when "I’ll pick it up myself" is selected. Total updates correctly.
 * Version:           1.3.0
 * Author:            Sajid Khan / Grok Assisted
 * Text Domain:       stroopwafel-oman
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class Stroopwafel_Conditional_Fees
{

    public function __construct()
    {
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_conditional_fees'), 20);
        add_action('woocommerce_after_checkout_form', array($this, 'enqueue_dynamic_scripts'));
        add_filter('woocommerce_checkout_fields', array($this, 'add_update_totals_class'));
    }

    public function add_update_totals_class($fields)
    {
        if (isset($fields['billing']['billing_delivery'])) {
            $fields['billing']['billing_delivery']['class'][] = 'update_totals_on_change';
        }
        return $fields;
    }

    /**
     * Main fee logic - now with stronger detection of billing_delivery
     */
    public function apply_conditional_fees($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Strong detection: check POST first (AJAX), then checkout object
        $delivery_option = '';
        if (isset($_POST['billing_delivery'])) {
            $delivery_option = sanitize_text_field($_POST['billing_delivery']);
        }
        elseif (WC()->checkout()) {
            $delivery_option = WC()->checkout()->get_value('billing_delivery');
        }

        $is_pickup = ($delivery_option === 'Option_2');

        // === Delivery Charges (ر.ع. 2.00) ===
        if (!$is_pickup) {
            $cart->add_fee(__('Delivery Charges', 'stroopwafel-oman'), 2.00);
        }

        // === Minimum / Small Order Fee (uses your existing settings) ===
        if (!$is_pickup) {
            $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
            $fee_amount = floatval(get_option('mof_fee_amount', 2));
            $fee_label = get_option('mof_fee_label', 'Small Order Fee');

            $cart_total = floatval($cart->get_cart_contents_total());

            if ($cart_total > 0 && $cart_total < $minimum_amount) {
                $cart->add_fee($fee_label, $fee_amount);
            }
        }
    // When pickup → NO fees are added at all → total becomes 15.00
    }

    /**
     * JS - instant hide + force total refresh
     */
    public function enqueue_dynamic_scripts()
    {
?>
        <script type="text/javascript">
        jQuery(function($) {
            function toggleFeeRows() {
                const isPickup = $('#billing_delivery').val() === 'Option_2';

                const $deliveryRow = $('.shop_table tfoot tr.fee th:contains("Delivery Charges")').closest('tr');
                const $minFeeRow   = $('.shop_table tfoot tr.fee th:contains("Small Order Fee")').closest('tr');

                $deliveryRow.toggle(!isPickup);
                $minFeeRow.toggle(!isPickup);
            }

            toggleFeeRows();

            $(document.body).on('change', '#billing_delivery', function() {
                toggleFeeRows();
                // Small delay + force refresh so total definitely updates
                setTimeout(function() {
                    $(document.body).trigger('update_checkout');
                }, 50);
            });

            $(document.body).on('updated_checkout', toggleFeeRows);
        });
        </script>
        <?php
    }
}

new Stroopwafel_Conditional_Fees();