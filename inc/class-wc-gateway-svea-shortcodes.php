<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class to handle ajax requests for the Svea plugin
 */
class WC_SveaWebPay_Gateway_Shortcodes {

	public static $using_get_address_shortcode = false;

	public function __construct() {
		add_action( 'wp', [ $this, 'check_for_shortcode' ] );

		add_shortcode( 'svea_get_address', [ $this, 'display_get_address' ] );
	}

	public static function is_using_get_address_shortcode() {
		$using_get_address_shortcode = false;

		if ( isset( self::$using_get_address_shortcode ) ) {
			$using_get_address_shortcode = self::$using_get_address_shortcode;
		}

		return apply_filters( 'woocommerce_sveawebpay_using_get_address_shortcode', $using_get_address_shortcode );
	}

	/**
	 * Check if the current page contains our get address shortcode
	 *
	 * @return  void
	 */
	public function check_for_shortcode() {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );

		// Check if page ID is set in WooCommerce
		if ( ! $checkout_page_id || $checkout_page_id <= 0 ) {
			return;
		}

		$content = get_post_field( 'post_content', $checkout_page_id );

		/**
		 * If the current post contains the shortcode and invoice is enabled
		 * set the variable using_get_address_shortcode to true
		 */
		if ( has_shortcode( $content, 'svea_get_address' )
			&& WC_Gateway_Svea_Invoice::init()->enabled === 'yes' ) {
			self::$using_get_address_shortcode = true;
		}
	}

	public function display_get_address( $atts ) {
		if ( WC_Gateway_Svea_Invoice::init()->enabled !== 'yes'
			|| ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) {
			return;
		}

		// Include get address template
		$get_address_template = locate_template( 'woocommerce-gateway-svea-webpay/shortcodes/get-address.php' );

		if ( $get_address_template === '' ) {
			$get_address_template = WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/partials/shortcodes/get-address.php';
		}

		ob_start();

		include( $get_address_template );

		return ob_get_clean();
	}

}

new WC_SveaWebPay_Gateway_Shortcodes();
