<?php
/**
 * Plugin Name: Paymee Payment Gateway for WooCommerce
 * Plugin URI: https://www.paymee.tn
 * Description: Payment Gateway Plugin to accept payments for WooCommerce.
 * Version: 1.8
 * Author: Paymee
 * Author URI: https://paymee.tn
 * Contributors: Paymee
 * Requires at least: 4.0
 * Tested up to: 5.7
 *
 * Text Domain: paymee-payment-gateway
 * Domain Path: /lang/
 *
 * @package Paymee Gateway for WooCommerce
 * @author Paymee
 */
add_action('plugins_loaded', 'init_wc_gateway_paymee', 0);

function init_wc_gateway_paymee()
{
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    load_plugin_textdomain('paymee-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/lang');

    class wc_gateway_paymee extends WC_Payment_Gateway
    {
        public function __construct() {
            global $woocommerce;

            $this->id			= 'wc_gateway_paymee';
            $this->method_title = __('Paymee', 'paymee-payment-gateway');
            $this->icon			= apply_filters('wc_gateway_paymee_icon', 'paymee-logo.png');
            $this->has_fields 	= false;

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title 			= "Paymee";
            $this->description 		= "Payer par cartes bancaire, par carte e-dinar ou avec votre compte Paymee.";
            $this->private_key  	= $this->settings['private_key'];
            $this->mode         	= $this->settings['mode'];
            $this->merchant_id   	= $this->settings['merchant_id'];
            $this->exchange_rate   	= $this->settings['exchange_rate'];

            $this->notify_url   = add_query_arg('wc-api', 'wc_gateway_paymee', home_url('/')).'&';
            $this->cancel_url 	= add_query_arg('wc-api', 'wc_gateway_paymee', home_url('/'));

            // Actions
            add_action('init', array( $this, 'successful_request' ));
            add_action('woocommerce_api_wc_gateway_paymee', array( $this, 'successful_request' ));
            add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ));
            add_action('woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        }

        /**
         * get_icon function.
         *
         * @access public
         * @return string
         */
        public function get_icon() {
            global $woocommerce;

            $icon = '';
            if ($this->icon) {
                $icon = '<img src="' . plugins_url('images/' . $this->icon, __FILE__)  . '" alt="' . $this->title . '" />';
            }

            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options() {
            ?>
	    	<h3><?php _e('Paymee', 'paymee-payment-gateway'); ?></h3>
	    	<table class="form-table">
	    	  <?php $this->generate_settings_html(); ?>
			</table>
			<p>
                <?php _e('<br> <hr> <br>
				<div style="float:right;text-align:right;">
					Made with &hearts; at <a href="https://www.paymee.tn" target="_blank">Paymee</a> | Besoin d\'aide? <a href="contact@paymee.tn" target="_blank">Contactez-nous</a><br><br>
					<a href="https://www.paymee.tn" target="_blank"><img src="' . plugins_url('images/paymee-logo-text.png', __FILE__) . '">
					</a>
				</div>', 'paymee-payment-gateway'); ?>
            </p>
	    	<?php
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Activer/Désactiver', 'paymee-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Activer Paymee', 'paymee-payment-gateway'),
                        'default' => 'yes'
                    ),
                    'mode' => array(
                        'title' => __('Mode', 'paymee-payment-gateway'),
                        'type' => 'select',
                        'options' => array(
                            'test' => 'Test',
                            'live' => 'Production'
                        ),
                        'default' => 'test'
                    ),
                    'merchant_id' => array(
                        'title' => __('Référence du compte Paymee', 'paymee-payment-gateway'),
                        'type' => 'text',
                        'description' => __('Référence du compte Paymee.', 'paymee-payment-gateway'),
                        'default' => ''
                    ),
                    'private_key' => array(
                        'title' => __('Clé API', 'paymee-payment-gateway'),
                        'type' => 'text',
                        'description' => __('Clé API fournie par Paymee.', 'paymee-payment-gateway'),
                        'default' => ''
                    ),
                    'exchange_rate' => array(
                        'title' => __('Taux de change', 'paymee-payment-gateway'),
                        'type' => 'text',
                        'description' => __('Taux de change par rapport au dinar tunisien. Le point (.) est le séparateur des décimaux', 'paymee-payment-gateway'),
                        'default' => '1'
                    )
                );
        }

        /**
         * Not payment fields, but show the description of the payment.
         **/
        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Generate the form with the params
         **/

        public function generate_paymee_form($order_id) {
            session_start();
            global $woocommerce;

            $order = new WC_Order($order_id);

            if ($this->mode == 'test') {
                $gateway_url = 'https://sandbox.paymee.tn/gateway/';
                $service_url = 'https://sandbox.paymee.tn/api/v1/payments/create';
            }
            elseif ($this->mode == 'live') {
                $gateway_url = 'https://app.paymee.tn/gateway/';
                $service_url = 'https://app.paymee.tn/api/v1/payments/create';
            }

            $headers = array('Authorization' => "Token " . $this->private_key);
            $amount_dinars = floatval($order->get_total()) * floatval($this->exchange_rate);
            $body = array(
                'vendor' => $this->merchant_id,
                'amount' => $amount_dinars,
                'note' => "Commande #" . $order_id
            );
            $args = array(
              'body'        => $body,
              'timeout'     => '5',
              'redirection' => '5',
              'httpversion' => '1.0',
              'blocking'    => true,
              'headers'     => $headers,
              'cookies'     => array(),
            );
            $response = json_decode( wp_remote_retrieve_body( wp_remote_post($service_url, $args) ), true );

            $params['url_ok']           = $this->notify_url;
            $params['url_ko']           = $this->cancel_url;
            $params['payment_token']    = $response['data']['token'];
            WC()->session->set('payment_token', $response['data']['token']);

            $paymee_arg_array = array();
            foreach ($params as $key => $value) {
                $paymee_arg_array[] = '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />';
            }

            wc_enqueue_js('
				jQuery("body").block({
						message: "Vous allez être redirigé vers Paymee.",
						overlayCSS: {
							background: "#fff",
							opacity: 0.6
						},
						css: {
					        padding:        20,
					        textAlign:      "center",
					        color:          "#555",
					        border:         "3px solid #aaa",
					        backgroundColor:"#fff",
					        cursor:         "wait",
					        lineHeight:		"32px"
					    }
					});
					jQuery("#submit_paymee_payment_form").click();
			');

            return  '<form action="'.esc_url($gateway_url).'" method="post" id="paymee_payment_form">
					   ' . implode('', $paymee_arg_array) . '
					   <input type="submit" class="button" id="submit_paymee_payment_form" value="'.__('Payer', 'paymee-payment-gateway').'" />
				    </form>';
        }

        /**
         * Process the payment and return the result
         **/
        public function process_payment($order_id) {
          session_start();
            $order = new WC_Order($order_id);
            $_SESSION['paymee_order_id'] = $order_id;
            return array(
                'result' 	=> 'success',
                'redirect'	=> $order->get_checkout_payment_url(true)
            );
        }

        /**
         * receipt_page
         **/
        public function receipt_page($order) {
            echo $this->generate_paymee_form($order);
        }

        /**
         * Successful Payment!
         **/
        public function successful_request() {
            session_start();
            global $woocommerce;

            if ($this->mode == 'test') {
                $service_url = 'https://sandbox.paymee.tn/api/v1/payments/'.WC()->session->get('payment_token').'/check';
            }
            elseif ($this->mode == 'live') {
                $service_url = 'https://app.paymee.tn/api/v1/payments/'.WC()->session->get('payment_token').'/check';
            }
            $headers = array('Authorization' => "Token " . $this->private_key);
            $args = array('headers'     => $headers,);
            $response = json_decode( wp_remote_retrieve_body( wp_remote_get($service_url, $args) ), true );

            if ($response['data']['payment_status']==true) {
                $order = new WC_Order($_SESSION['paymee_order_id']);
                $order->add_order_note(sprintf(__('Payée avec Paymee. Numéro de la transaction %s.', 'paymee-payment-gateway'), $_GET['transaction']));
                $order->payment_complete();

                wp_redirect($this->get_return_url($order));
                exit;
            }
            wc_add_notice(sprintf(__('Erreur de paiement.', 'paymee-payment-gateway')), $notice_type = 'error');
            wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));
            exit;
        }

        private function force_ssl($url) {
            if ('yes' == get_option('woocommerce_force_ssl_checkout')) {
                $url = str_replace('http:', 'https:', $url);
            }

            return $url;
        }
    }

    /**
     * Add the gateway to WooCommerce
     **/
    function add_paymee_gateway($methods) {
        $methods[] = 'wc_gateway_paymee';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_paymee_gateway');
}
