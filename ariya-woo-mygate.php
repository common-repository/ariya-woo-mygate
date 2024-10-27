<?php

    /* 
    Plugin Name: Ariya Woo MyGate
    Plugin URI: http://ariyawebservices.com/plugins/woo-mygate
    Description: A Plugin for integrating MyGate payment gateway for WooCommerce.
    Author: Ariya Web Services
    Version: 1.0
	Author URI: http://ariyawebservices.com
    */

    require_once(ABSPATH . 'wp-includes/pluggable.php');

if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

$page_var = $_GET['page'];
$tab_var = $_GET['tab'];
$section_var = $_GET['section'];

// If the current page is mygate configuration page and calling save event.
if ($page_var == 'wc-settings' && $tab_var == 'checkout' && $section_var == 'mygate' && $_REQUEST['save'] == 'Save changes') {
    // verify this came from the our screen and with proper authorization,
    // because save_post can be triggered at other times
    if (!wp_verify_nonce($_POST['woocommerce_mygate_noncename'], plugin_basename(__FILE__))) {
        // This nonce is not valid.
        die('Security check');
    }

    // Is the user allowed to edit the post or page?
    if (!current_user_can('update_plugins'))
        exit;

    $user_mygate_title = sanitize_text_field($_REQUEST['woocommerce_mygate_title']);
    $user_mygate_enabled = sanitize_text_field($_REQUEST['woocommerce_mygate_enabled']) == 1 ? 'yes' : 'no';
    $user_mygate_testmode = sanitize_text_field($_REQUEST['woocommerce_mygate_testmode']) == 1 ? 'yes' : 'no';
    $user_mygate_description = sanitize_text_field($_REQUEST['woocommerce_mygate_description']);
    $user_mygate_merchantid = sanitize_text_field($_REQUEST['woocommerce_mygate_merchantid']);
    $user_mygate_appid = sanitize_text_field($_REQUEST['woocommerce_mygate_appid']);

    if ($user_mygate_title || $user_mygate_enabled || $user_mygate_testmode || $user_mygate_description || $user_mygate_merchantid || $user_mygate_appid) {
        $user_mygate_settings_array = array(
            'title' => $user_mygate_title,
            'enabled' => $user_mygate_enabled,
            'testmode' => $user_mygate_testmode,
            'description' => $user_mygate_description,
            'merchantid' => $user_mygate_merchantid,
            'appid' => $user_mygate_appid,
        );
        $serialized_user_mygate_settings_array = serialize($user_mygate_settings_array);

        update_option('woocommerce_mygate_settings', $serialized_user_mygate_settings_array);
    }
}

add_action('plugins_loaded', 'woocommerce_mygate_init', 0);

function woocommerce_mygate_init() {

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class mygate extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'mygate';

            $this->method_title = __('MyGate');

            $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/a-mygate-plugin.png';

            $this->has_fields = false;

            $this->init_form_fields();

            $this->init_settings();
            $serialized_mygate_settings = get_option('woocommerce_mygate_settings');
            $this->settings = unserialize($serialized_mygate_settings);

            $this->title = esc_html($this->settings['title']);

            $this->description = esc_html($this->settings['description']);

            $this->merchantid = esc_html($this->settings['merchantid']);

            $this->appid = esc_html($this->settings['appid']);

            $this->testmode = esc_html($this->settings['testmode']);

            $this->liveurl = esc_url("https://www.mygate.co.za/virtual/8x0x0/dsp_ecommercepaymentparent.cfm");

            add_action('init', array(&$this, 'successful_request'));

            add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));

            add_action('woocommerce_receipt_mygate', array(&$this, 'receipt_page'));
        }

        function init_form_fields() {

            $this->form_fields = array(
                'noncename' => array(
                    'type' => 'hidden',
                    'label' => __('Mygatenonce.'),
                    'default' => wp_create_nonce(plugin_basename(__FILE__)),
                    'display_label' => false,),
                'enabled' => array(
                    'title' => __('Enable/Disable'),
                    'type' => 'checkbox',
                    'label' => __('Enable MyGate Payment Module.'),
                    'default' => 'yes'),
                'testmode' => array(
                    'title' => __('Enable MyGate SandBox', 'woothemes'),
                    'type' => 'checkbox',
                    'default' => 'yes'),
                'title' => array(
                    'title' => __('Title'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.'),
                    'default' => __('MyGate')),
                'description' => array(
                    'title' => __('Description'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.'),
                    'default' => __('Pay securely by Credit Card through MyGate Secure Servers.')),
                'merchantid' => array(
                    'title' => __('MyGate Merchant ID'),
                    'type' => 'text',
                    'description' => __('Please enter your MyGate Merchant ID; this is needed in order to take payment!')),
                'appid' => array(
                    'title' => __('MyGate Application ID'),
                    'type' => 'text',
                    'description' => __('Please enter your MyGate Application ID; this is needed in order to take payment!')),
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         * */
        public function admin_options() {
            echo '<h3>' . __('MyGate Payment Gateway') . '</h3>';
            echo '<p>' . __('MyGate works by sending the user to MyGate Secure Site to enter their payment information. Make sure you select store currency supported by MyGate.') . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields for mygate, but we want to show the description if set.
         * */
        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        /**
         * Generate mygate button link
         * */
        public function generate_mygate_form($order_id) {
            global $woocommerce;

            $order = wc_get_order($order_id);

            $mygate_adr = $this->liveurl;

            if ($this->testmode == 'yes') {
                $testmode = '0';
            } else {
                $testmode = '1';
            }

            $mygate_args = array_merge(
                    array(
                        'Mode' => $testmode,
                        'txtMerchantID' => $this->merchantid,
                        'txtApplicationID' => $this->appid,
                        'txtMerchantReference' => get_bloginfo('name') . ' Order No ' . $order->id,
                        'txtRedirectSuccessfulURL' => $this->get_return_url($order),
                        'txtRedirectFailedURL' => $order->get_cancel_order_url(),
                        'txtPrice' => number_format($order->order_total, 0, '', ''),
                        'txtCurrencyCode' => get_option('woocommerce_currency'),
                        'txtDisplayPrice' => number_format($order->order_total, 0, '', ''),
                        'txtDisplayCurrencyCode' => get_option('woocommerce_currency'),
                        'Variable1' => $order->id,
                        'txtRecipient' => $order->billing_first_name . ' ' . $order->billing_last_name,
                        'txtShippingAddress1' => $order->billing_address_1 . ' ' . $order->billing_address_2,
                        'txtShippingAddress2' => $order->billing_city,
                        'txtShippingAddress3' => $order->billing_state,
                        'txtShippingAddress4' => $order->billing_country,
                        'txtShippingAddress5' => $order->billing_postcode,
                    )
            );

            $mygate_args_array = array();

            foreach ($mygate_args as $key => $value) {

                $mygate_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
            if ($this->testmode == 'yes')
                $mygate_adr = 'https://dev-virtual.mygateglobal.com/PaymentPage.cfm';
            else
                $mygate_adr = 'https://virtual.mygateglobal.com/PaymentPage.cfm';

            return '<form action="' . $mygate_adr . '" method="post" id="mygate_payment_form">
					' . implode('', $mygate_args_array) . '
					<input type="submit" class="button-alt" id="submit_mygate_payment_form" value="' . __('Pay via MyGate', 'woothemes') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'woothemes') . '</a>
					
					<script type="text/javascript">
						jQuery(function(){
							jQuery("body").block(
								{ 
									message: "<img src=\"' . esc_url($woocommerce->plugin_url()) . '/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" />' . __('Thank you for your order. We are now redirecting you to MyGate to make payment.', 'woothemes') . '", 
									overlayCSS: 
									{ 
										background: "#fff", 
										opacity: 0.6 
									},
									css: { 
								        padding:        20, 
								        textAlign:      "center", 
								        color:          "#555", 
								        border:         "3px solid #aaa", 
								        backgroundColor:"#fff", 
								        cursor:         "wait" 
								    } 
								});
							jQuery("#submit_mygate_payment_form").click();
						});
					</script>
				</form>';
        }

        /**
         * Process the payment and return the result
         * */
        function process_payment($order_id) {

            $order = wc_get_order($order_id);

            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            );
        }

        /**
         * Receipt Page
         * */
        function receipt_page($order) {

            echo '<p>' . __('Thank you for your order, please click the button below to pay with MyGate.') . '</p>';

            echo $this->generate_mygate_form($order);
        }

        /**
         * Successful Payment!
         * */
        function successful_request() {
            global $woocommerce;

            if (isset($_REQUEST['VARIABLE1'])) {

                $order_id = (int) $_SESSION['order_awaiting_payment'];

                if ($order_id > 0) {

                    $order = wc_get_order($order_id);

                    $provided_order_key = trim(esc_attr($_GET['t']));

                    if ($provided_order_key == $order->order_key) {

                        $cancel_url = $order->get_cancel_order_url();

                        wp_redirect($cancel_url);
                    }
                }
            }

            if (isset($_POST['VARIABLE1']) && is_numeric($_POST['VARIABLE1']) && isset($_POST['_RESULT'])) {

                $_POST = stripslashes_deep($_POST);

                if (!empty($_POST['VARIABLE1'])) {

                    $order = wc_get_order($order_id);

                    if ($order->status !== 'completed') {

                        if ($_POST['_RESULT'] >= 0) {

                            $order->add_order_note(__('MyGate payment completed', 'woothemes'));

                            $order->payment_complete();

                            $woocommerce->cart->empty_cart();
                        } else {

                            $order->update_status('failed', sprintf(__('MyGate payment failed!', 'woothemes')));
                        }
                    }
                }
            }
        }

    }

    /**
     * Add the Gateway to WooCommerce
     * */
    function add_mygate_gateway($methods) {
        $obj = new mygate;
		$title = get_the_title();
        if ((!empty($title) && $obj->settings['enabled'] == 'yes') || empty($title))
            $methods[] = 'mygate';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_mygate_gateway');
}
