<?php
/**
 * Plugin Name:       Stroopwafel Oman - Conditional Fees (Delivery + Minimum Order)
 * Description:       Removes Delivery Charges AND Minimum Order Fee when "I’ll pick it up myself" (Option_2) is selected. Updates total correctly on runtime.
 * Version:           1.2.0
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
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_conditional_fees'), 20); // Higher priority
        add_action('woocommerce_after_checkout_form', array($this, 'enqueue_dynamic_scripts'));

        // Ensure the field triggers update on change
        add_filter('woocommerce_checkout_fields', array($this, 'add_update_totals_class'));
    }

    /**
     * Add class to trigger update_totals_on_change
     */
    public function add_update_totals_class($fields)
    {
        if (isset($fields['billing']['billing_delivery'])) {
            $fields['billing']['billing_delivery']['class'][] = 'update_totals_on_change';
        }
        return $fields;
    }

    /**
     * Core logic: Add fees ONLY when needed. No fees for pickup.
     */
    public function apply_conditional_fees($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Get selected delivery option safely
        $delivery_option = WC()->checkout()->get_value('billing_delivery')
            ?? (isset($_POST['billing_delivery']) ? sanitize_text_field($_POST['billing_delivery']) : 'Option_1');

        $is_pickup = ($delivery_option === 'Option_2');

        // === 1. Delivery Charges (ر.ع. 2.00) ===
        if (!$is_pickup) {
            $cart->add_fee(__('Delivery Charges', 'stroopwafel-oman'), 2.00);
        }

        // === 2. Minimum Order Fee (Small Order Fee) ===
        // Only apply when NOT pickup AND subtotal is below minimum
        if (!$is_pickup) {
            $minimum_amount = floatval(get_option('mof_minimum_amount', 25));
            $fee_amount = floatval(get_option('mof_fee_amount', 2));
            $fee_label = get_option('mof_fee_label', 'Small Order Fee');

            $cart_total = floatval($cart->get_cart_contents_total());

            if ($cart_total > 0 && $cart_total < $minimum_amount) {
                $cart->add_fee($fee_label, $fee_amount);
            }
        }

    // When pickup is selected → NO fees are added (both are skipped)
    // This ensures the total automatically becomes: subtotal + shipping (local pickup = 0)
    }

    /**
     * JavaScript for smooth UX - instant hide + force refresh
     */
    public function enqueue_dynamic_scripts()
    {
?>
        <script type="text/javascript">
        jQuery(function($) {
            function toggleFeeRows() {
                const isPickup = $('#billing_delivery').val() === 'Option_2';

                // Hide/Show Delivery Charges row
                const $deliveryRow = $('.shop_table tfoot tr.fee th:contains("Delivery Charges")').closest('tr');
                $deliveryRow.toggle(!isPickup);

                // Hide/Show Small Order Fee row
                const $minFeeRow = $('.shop_table tfoot tr.fee th:contains("Small Order Fee")').closest('tr');
                $minFeeRow.toggle(!isPickup);
            }

            // Initial load
            toggleFeeRows();

            // When user changes the dropdown
            $(document.body).on('change', '#billing_delivery', function() {
                toggleFeeRows();
                // Force full checkout refresh so totals update correctly
                $(document.body).trigger('update_checkout');
            });

            // After WooCommerce updates the order review table
            $(document.body).on('updated_checkout', toggleFeeRows);
        });
        </script>
        <?php
    }
}

new Stroopwafel_Conditional_Fees();