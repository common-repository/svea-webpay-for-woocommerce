<?php

use Svea\WebPay\Config\ConfigurationService;
use Svea\WebPay\WebPayAdmin;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class to handle cron functions for the Svea plugin
 */
class WC_SveaWebPay_Gateway_Cron_Functions {

	/**
	 * Action for checking strong auth on an order
	 */
	const CHECK_AUTH_ACTION = 'webpay_strong_auth_check';

	/**
	 * Construct for WC_SveaWebPay_Gateway_Cron_Functions
	 */
	public function __construct() {
		add_action( self::CHECK_AUTH_ACTION, [ $this, 'check_authentication_results' ], 10, 3 );
	}

	/**
	 * Get the gateway class
	 *
	 * @return object
	 */
	public function get_gateway( string $payment_method ) {
		switch ( $payment_method ) {
			case 'invoice':
				return WC_Gateway_Svea_Invoice::init();

				break;

			case 'part_pay':
				return WC_Gateway_Svea_Part_Pay::init();

				break;

			default:
				break; // Not supported payment method
		}
	}

	/**
	 * Check if current order is authenticated and handle the results
	 *
	 * @return void
	 */
	public function check_authentication_results( int $order_id, int $svea_order_id, string $payment_method ) {
		$config = $this->get_config( $payment_method ); 

		$request = WebPayAdmin::queryOrder( $config )
			->setOrderId( (string) $svea_order_id )
			->setCountryCode( 'SE' );

		switch ( $payment_method ) {
			case 'invoice':
				$response = $request->queryInvoiceOrder()->doRequest();

				break;

			case 'part_pay':
				$response = $request->queryPaymentPlanOrder()->doRequest();

				break;

			default:
				break; // Not supported payment method
		}

		if ( empty( $response ) ) {
			return; // No response was created in switch statement
		}

		if ( $this->order_is_confirmed( $response ) ) {
			$this->finish_order( $order_id );
		} else {
			$this->cancel_order( $order_id );
		}
	}

	/**
	 * Get the config class for the gateway
	 *
	 * @param string $payment_method The payment method to get config for.
	 * @return object
	 */
	public function get_config( $payment_method ) {
		$gateway = $this->get_gateway( $payment_method );

		if ( ! $gateway ) {
			return;
		}

		$config_class = $gateway->get_testmode() ? 'WC_Svea_Config_Test' : 'WC_Svea_Config_Production';

		if ( $gateway->enabled !== 'yes' ) {
			return;
		}
		
		$merchant_id = $gateway->get_option( 'merchant_id' );
		$secret_word = $gateway->get_option( 'secret_word' );

		if ( empty( $merchant_id ) || empty( $secret_word ) ) {
			return;
		}

		return new $config_class( $merchant_id, $secret_word, false, false, false );
	}

	/**
	 * Check if an order is confirmed based on the response from Svea API.
	 *
	 * @param object $response The response object from Svea API.
	 * @return bool Returns true if the order is confirmed, false otherwise.
	 */
	public function order_is_confirmed( $response ) {
		return $response->orderStatus === 'Active' && $response->pendingReasons === '';
	}

	/**
	 * Finish the order by marking it as completed.
	 *
	 * @param int $order_id The ID of the order to finish.
	 * @return void
	 */

	public function finish_order( $order_id ) {
		$wc_order = wc_get_order( $order_id );

		if ( $wc_order->get_status() === 'completed' ) {
			return; // Order is already completed
		}

		$wc_order->payment_complete( $order_id );
	}

	/**
	 * Cancels an order by updating its status to 'cancelled'.
	 *
	 * @param int $order_id The ID of the order to cancel.
	 * @return void
	 */
	public function cancel_order( $order_id ) {
		$wc_order = wc_get_order( $order_id );

		if ( $wc_order->get_status() === 'cancelled' ) {
			return; // Order is already cancelled
		}

		$wc_order->update_status( 'cancelled', 'Order cancelled by the system' );
	}

	/**
	 * Setup strong auth check cron
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function setup_strong_auth_check_cron( int $order_id, int $svea_order_id, string $payment_method ) {
		wp_schedule_single_event(
			strtotime( 'now' ) + 600, // now + 10 minutes
			WC_SveaWebPay_Gateway_Cron_Functions::CHECK_AUTH_ACTION,
			[ $order_id, $svea_order_id, $payment_method ]
		);
	}
}

/**
 * Instantiate this class when loaded
 */
new WC_SveaWebPay_Gateway_Cron_Functions();
