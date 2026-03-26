<?php
/**
 * Plugin Name:       Stroopwafel Oman - Conditional Fees (Delivery + Minimum Order)
 * Description:       Removes Delivery Charges and Minimum Order Fee when "I’ll pick it up myself" is selected. Works dynamically on checkout.
 * Version:           1.1.0
 * Author:            Sajid Khan / Grok Assisted
 * Text Domain:       stroopwafel-oman
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class Stroopwafel_Conditional_Fees
{

    public function __construct()
    {
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_conditional_fees'));
        add_action('woocommerce_after_checkout_form', array($this, 'enqueue_dynamic_scripts'));

        // Force update checkout when billing_delivery changes
        add_filter('woocommerce_checkout_fields', array($this, 'add_update_totals_class'));
    }

    /**
     * Add 'update_totals_on_change' class to billing_delivery field
     */
    public function add_update_totals_class($fields)
    {
        if (isset($fields['billing']['billing_delivery'])) {
            $fields['billing']['billing_delivery']['class'][] = 'update_totals_on_change';
        }
        return $fields;
    }

    /**
     * Main logic: Add / Remove fees based on delivery option
     */
    public function apply_conditional_fees()
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $delivery_option = WC()->checkout()->get_value('billing_delivery')
            ?? (isset($_POST['billing_delivery']) ? sanitize_text_field($_POST['billing_delivery']) : 'Option_1');

        $is_pickup = ($delivery_option === 'Option_2');

        // 1. Delivery Charges (ر.ع. 2.00) - Only for "Deliver to address"
        if (!$is_pickup) {
            WC()->cart->add_fee(__('Delivery Charges', 'stroopwafel-oman'), 2.00);
        }

        // 2. Minimum Order Fee - Only when "Deliver to address" AND cart total is below minimum
        if (!$is_pickup) {
            $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
            $fee_amount = floatval(get_option('mof_fee_amount', 2));
            $fee_label = get_option('mof_fee_label', 'Small Order Fee');

            $cart_total = floatval(WC()->cart->get_cart_contents_total());

            if ($cart_total > 0 && $cart_total < $minimum_amount) {
                WC()->cart->add_fee($fee_label, $fee_amount);
            }
        }
    }

    /**
     * JavaScript for instant show/hide of fee rows + better UX
     */
    public function enqueue_dynamic_scripts()
    {
?>
        <script type="text/javascript">
        jQuery(function($) {
            function toggleFeeRows() {
                const isPickup = $('#billing_delivery').val() === 'Option_2';

                // Delivery Charges row
                const $deliveryRow = $('.shop_table tfoot tr.fee th:contains("Delivery Charges")').closest('tr');
                if (isPickup) {
                    $deliveryRow.hide();
                } else {
                    $deliveryRow.show();
                }

                // Minimum Order / Small Order Fee row
                const $minFeeRow = $('.shop_table tfoot tr.fee th:contains("Small Order Fee")').closest('tr');
                if (isPickup) {
                    $minFeeRow.hide();
                } else {
                    $minFeeRow.show();
                }
            }

            // Initial run
            toggleFeeRows();

            // On field change
            $(document.body).on('change', '#billing_delivery', function() {
                toggleFeeRows();
                $(document.body).trigger('update_checkout'); // Ensure totals refresh
            });

            // After WooCommerce refreshes the review table
            $(document.body).on('updated_checkout', toggleFeeRows);
        });
        </script>
        <?php
    }
}

// Initialize the plugin
new Stroopwafel_Conditional_Fees();