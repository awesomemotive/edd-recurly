<?php
/*
Plugin Name: Easy Digital Downloads - Recur.ly Checkout
Plugin URL: http://easydigitaldownloads.com/extension/recurly
Description: Adds a payment gateway/checkout for http://recur.ly
Version: 1.2.2
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/

if ( !defined( 'EDDR_PLUGIN_DIR' ) ) {
	define( 'EDDR_PLUGIN_DIR', dirname( __FILE__ ) );
}

if( class_exists( 'EDD_License' ) && is_admin() ) {
	$edd_recurly_license = new EDD_License( __FILE__, 'Recurly Checkout', '1.2.2', 'Pippin Williamson' );
}

// registers the gateway
function eddr_register_gateway( $gateways ) {
	$gateways['recurly'] = array( 'admin_label' => 'Recurly', 'checkout_label' => __( 'Credit Card', 'eddr' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'eddr_register_gateway' );

function eddr_checkout_error_checks( $valid_data, $post_data ) {

	if ( edd_get_cart_total() > 0 && 'recurly' == $valid_data['gateway'] ) {

		$api_key = edd_get_option( 'recurly_api_key' );

		if ( empty( $api_key ) ) {
			edd_set_error( 'missing_api_key', __( 'Please enter your Recurly API key in Settings', 'eddr' ) );
		}

		if ( ! isset( $_POST['card_name'] ) || strlen( trim( $_POST['card_name'] ) ) == 0 ) {
			edd_set_error( 'no_card_name', __( 'Please enter a name for the credit card.', 'eddr' ) );
		}

		if ( ! isset( $_POST['card_number'] ) || strlen( trim( $_POST['card_number'] ) ) == 0 ) {
			edd_set_error( 'no_card_number', __( 'Please enter a credit card number.', 'eddr' ) );
		}

		if ( ! isset( $_POST['card_cvc'] ) || strlen( trim( $_POST['card_cvc'] ) ) == 0 ) {
			edd_set_error( 'no_card_cvc', __( 'Please enter a CVC/CVV for the credit card.', 'eddr' ) );
		}

		if ( ! isset( $_POST['card_exp_month'] ) || strlen( trim( $_POST['card_exp_month'] ) ) == 0 ) {
			edd_set_error( 'no_card_exp_month', __( 'Please enter a expiration month.', 'eddr' ) );
		}

		if ( ! isset( $_POST['card_exp_year'] ) || strlen( trim( $_POST['card_exp_year'] ) ) == 0 ) {
			edd_set_error( 'no_card_exp_year', __( 'Please enter a expiration year.', 'eddr' ) );
		}

	}
}
add_action( 'edd_checkout_error_checks', 'eddr_checkout_error_checks', 10, 2 );

// processes the payment
function eddr_process_recurly_payment( $purchase_data ) {

	global $edd_options;

	require_once EDDR_PLUGIN_DIR . '/recurly/lib/recurly.php';

	$api_key = trim( $edd_options['recurly_api_key'] );

	$purchase_summary = edd_get_purchase_summary( $purchase_data );

	$errors = edd_get_errors();
	if ( ! $errors ) {

		try {

			Recurly_Client::$apiKey = $api_key;

			$transaction = new Recurly_Transaction();
			$transaction->amount_in_cents = $purchase_data['price'] * 100; // amount in cents
			$transaction->currency = edd_get_currency();
			$transaction->description = $purchase_summary;

			$account = new Recurly_Account();
			$account->account_code = $purchase_data['user_info']['first_name'] . '_' . $purchase_data['user_info']['last_name'] . '_' . $purchase_data['user_info']['id'];

			$billing_info = new Recurly_BillingInfo();
			$billing_info->first_name = $purchase_data['user_info']['first_name'];
			$billing_info->last_name = $purchase_data['user_info']['last_name'];
			$billing_info->number = $purchase_data['card_info']['card_number'];
			$billing_info->verification_value = $purchase_data['card_info']['card_cvc'];
			$billing_info->month = $purchase_data['card_info']['card_exp_month'];
			$billing_info->year = $purchase_data['card_info']['card_exp_year'];
			$billing_info->address1 = $purchase_data['card_info']['card_address'];
			$billing_info->address2 = $purchase_data['card_info']['card_address_2'];
			$billing_info->city = $purchase_data['card_info']['card_city'];
			$billing_info->state = $purchase_data['card_info']['card_state'];
			$billing_info->zip = $purchase_data['card_info']['card_zip'];
			$billing_info->country = $purchase_data['card_info']['card_country'];
			$billing_info->ip_address = edd_get_ip();

			$account->billing_info = $billing_info;
			$transaction->account = $account;

			$transaction->create();

			// setup the payment details
			$payment = array(
				'price' => $purchase_data['price'],
				'date' => $purchase_data['date'],
				'user_email' => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => edd_get_currency(),
				'downloads' => $purchase_data['downloads'],
				'cart_details' => $purchase_data['cart_details'],
				'user_info' => $purchase_data['user_info'],
				'status' => 'pending'
			);

			// record the pending payment
			$payment = edd_insert_payment( $payment );

			if ( $payment ) {

				edd_update_payment_status( $payment, 'publish' );

				if( function_exists( 'edd_set_payment_transaction_id' ) ) {
					edd_set_payment_transaction_id( $payment, $transaction->uuid );
				}

				edd_empty_cart();
				edd_send_to_success_page();

			} else {
				// if errors are present, send the user back to the purchase page so they can be corrected
				edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
			}

		} catch ( Exception $e ) {

			// if errors are present, send the user back to the purchase page so they can be corrected
			edd_set_error( 'some_error', sprintf( __( 'Error: %s', 'eddr' ), $e->getMessage() ) );
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	} else {
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_recurly', 'eddr_process_recurly_payment' );

// adds the settings to the Payment Gateways section
function eddr_add_settings( $settings ) {

	$recurly_settings = array(
		array(
			'id' => 'recurly_settings',
			'name' => '<strong>' . __( 'Recur.ly Settings', 'eddr' ) . '</strong>',
			'desc' => __( 'Configure the Recur.ly settings', 'eddr' ),
			'type' => 'header'
		),
		array(
			'id' => 'recurly_api_key',
			'name' => __( 'API Key', 'eddr' ),
			'desc' => __( 'Enter your API key, found in your Recur.ly Account under the API Credentials', 'eddr' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $recurly_settings );
}
add_filter( 'edd_settings_gateways', 'eddr_add_settings' );