<?php
/**
 * Uninstall - Removes WooCommerce SveaWebPay Gateway options
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'delete_transient' ) ) {
	$part_payment_transient_format = 'sveawebpay-part-pay-campaigns-%s';

	/**
	 * List all available countries for Svea and clear cache
	 * for all of them
	 */
	$available_countries = [ 'SE', 'DK', 'NO', 'FI', 'DE', 'NL' ];

	foreach ( $available_countries as $country ) {
		/**
		 * Delete the transient to clear out the cache
		 */
		delete_transient( sprintf( $part_payment_transient_format, $country ) );
	}
}
