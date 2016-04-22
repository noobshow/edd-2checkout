<?php

/*
Plugin Name: 2Checkout for Easy Digital Downloads
Plugin URI: http://omnipay.io
Description: Accept Credit Card payments in your Easy Digital Downloads store via 2Checkout
Version: 1.0.1
Author: Agbonghama Collins (W3Guy LLC)
Author URI: http://omnipay.io
Text Domain: edd-2checkout
Domain Path: /languages
*/

namespace OmnipayWP\EDD\TwoCheckout;

use Omnipay\Omnipay;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

include 'vendor/autoload.php';

class TwoCheckout {
	private static $instance;

	public function __construct() {
		// gateway ID is "eddTwocheckout"
		add_action( 'edd_eddTwocheckout_cc_form', array( $this, 'payment_form' ) );
		add_action( 'edd_gateway_eddTwocheckout', array( $this, 'process_payment' ) );
		add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'init', array( $this, 'complete_offsite_payment' ), 20 );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'settings_page' ) );
	}


	public function settings_section( $sections ) {
		$sections['2checkout'] = __( '2Checkout', 'edd-2checkout' );

		return $sections;
	}

	public function settings_page( $settings ) {

		$gateway_settings = array(
			'2checkout' => array(
				array(
					'id'   => 'eddTwocheckout_settings',
					'name' => '<strong>' . __( '2Checkout Settings', 'edd-2checkout' ) . '</strong>
					<div id="message" class="error notice"><p>'
					          . sprintf(
						          __(
							          'On-site checkout style, fraud review support, access to support from WordPress & EDD experts. <strong><a target="_blank" href="%s">Upgrade to PRO Now</a></strong>.',
							          'edd-2checkout'
						          ),
						          'https://omnipay.io/downloads/2checkout-easy-digital-downloads/?utm_source=wp-dashboard&utm_medium=edd-2checkout-lite'
					          ) .
					          '</p></div>',
					'desc' => __( 'Configure 2Checkout payment gateway settings', 'edd-2checkout' ),
					'type' => 'header',
				),
				array(
					'id'   => 'eddTwocheckout_account_number',
					'name' => __( 'Account Number', 'edd-2checkout' ),
					'desc' => __( 'Enter your 2Checkout account number.', 'edd-2checkout' ),
					'type' => 'text',
					'size' => 'regular',
				),
				array(
					'id'   => 'eddTwocheckout_secret_word',
					'name' => __( 'Secret Word', 'edd-2checkout' ),
					'desc' => __( 'Enter your 2checkout secret word.', 'edd-2checkout' ),
					'type' => 'text',
					'size' => 'regular',
				),
			),
		);

		return array_merge( $settings, $gateway_settings );
	}

	/**
	 * This function adds the payment gateway to EDD settings.
	 *
	 * @param array $gateways
	 *
	 * @return array
	 */
	public function register_gateway( $gateways ) {

		$gateways['eddTwocheckout'] = array(
			'admin_label'    => '2Checkout',
			'checkout_label' => apply_filters( 'edd_2checkout_label', __( '2Checkout', 'edd-2checkout' ) ),
		);

		return $gateways;

	}

	/**
	 * When EDD demo is activated, the 2co gateway perform test payment by appending demo=Y
	 * to the payment parameter before redirecting offsite to 2co for payment.
	 *
	 * This filter controls enabling testing via sandbox account.
	 *
	 * @return bool
	 */
	public function is_sandbox_test() {
		return apply_filters( 'edd_2checkout_enable_sandbox_test', false );
	}

	/**
	 * 2Checkout Omnipay gateway instance.
	 *
	 * @return mixed
	 */
	public function offsite_gateway_instance() {
		$gateway = Omnipay::create( 'TwoCheckoutPlus' );
		$gateway->setAccountNumber( $this->getAccountNumber() );
		$gateway->setSecretWord( $this->getSecretWord() );
		$gateway->setTestMode( $this->is_sandbox_test() );
		// activate test mode by passing demo parameter to checkout parameters.
		$gateway->setDemoMode( edd_is_test_mode() );

		return $gateway;
	}

	/**
	 * Account number.
	 *
	 * @return string
	 */
	public function getAccountNumber() {
		return edd_get_option( 'eddTwocheckout_account_number' );
	}

	/**
	 * Account secret word.
	 *
	 * @return string
	 */
	public function getSecretWord() {
		return edd_get_option( 'eddTwocheckout_secret_word' );
	}

	/**
	 * EDD callback function to output payment form.
	 */
	public function payment_form() {
		return;
	}

	/**
	 * Process 2checkout payment.
	 *
	 * @param array $purchase_data
	 */
	public function process_payment( $purchase_data ) {
		if ( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( __( 'Nonce verification has failed', 'omnipaywp_edd' ),
				__( 'Error', 'omnipaywp_edd' ),
				array( 'response' => 403 ) );
		}

		// make sure we don't have any left over errors present
		edd_clear_errors();

		// validate billing address city, country, post code, country

		$payment = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => edd_get_currency(),
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'gateway'      => '2Checkout',
			'status'       => 'pending',
		);

		// record the pending payment
		$payment_id = edd_insert_payment( $payment );

		try {
			$response = $this->process_offsite_payment( $payment_id, $purchase_data );


			if ( isset( $response ) ) {
				if ( $response->isRedirect() ) {
					edd_insert_payment_note( $payment_id, __( 'Order pending fraud review.', 'omnipaywp_edd' ) );
					$response->redirect();
				} else {
					edd_set_error( 'twocheckout_error', $response->getMessage() );
					$fail = true;
				}
			} else {
				edd_set_error( 'twocheckout_error', __( 'Unexpected error. Please try again', 'omnipaywp_edd' ) );
				$fail = true;
			}
		} catch ( \Exception $e ) {
			edd_set_error( 'twocheckout_error', $e->getMessage() );
			$fail = true;
		}

		if ( isset( $fail ) && $fail !== false ) {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Process payments made off-site.
	 *
	 * @param int $payment_id
	 * @param array $purchase_data
	 *
	 * @return mixed
	 */
	public function process_offsite_payment( $payment_id, $purchase_data ) {
		$gateway = $this->offsite_gateway_instance();
		$cart    = array();
		foreach ( $purchase_data['cart_details'] as $item ) {
			$cart[] = array(
				'name'       => $item['name'],
				'quantity'   => $item['quantity'],
				'price'      => $item['item_price'],
				'tangible'   => 'N',
				'product_id' => $item['id'],
			);
		}
		$gateway->setCart( $cart );

		$formData = array(
			'firstName' => $purchase_data['user_info']['first_name'],
			'lastName'  => $purchase_data['user_info']['last_name'],
			'email'     => $purchase_data['user_email'],
		);

		$response = $gateway->purchase(
			array(
				'card'          => $formData,
				'transactionId' => $payment_id,
				'currency'      => edd_get_currency(),
				// add a query parameter to the returnUrl to listen and complete payment
				'returnUrl'     => add_query_arg( 'edd-om-api', '2co', edd_get_success_page_uri() ),
			)
		)->send();

		return $response;
	}

	/**
	 * Complete order made via off-site payment.
	 */
	public function complete_offsite_payment() {

		if ( isset( $_GET['edd-om-api'] ) && $_GET['edd-om-api'] == '2co' ) {
			// prevent the complete offsite payment code from running if this is a 2checkout INS/IPN request.
			// because they both listen to edd-om-api=2co request.
			if ( isset( $_POST['md5_hash'] ) ) {
				return;
			}

			try {
				$gateway  = $this->offsite_gateway_instance();
				$response = $gateway->completePurchase()->send();
				if ( $response->isSuccessful() ) {
					edd_update_payment_status( $response->getTransactionId(), 'complete' );
					edd_send_to_success_page();
				} else {
					edd_set_error( '2co_offsite_error', $response->getMessage() );
					edd_send_back_to_checkout();
				}
			} catch ( \Exception $e ) {
				edd_set_error( '2co_offsite_error', $e->getMessage() );
				edd_send_back_to_checkout();
			}
		}
	}

	/**
	 * Singleton poop.
	 *
	 * @return TwoCheckout
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

add_action( 'plugins_loaded', 'OmnipayWP\EDD\TwoCheckout\load_plugin' );

function load_plugin() {
	TwoCheckout::get_instance();
}

