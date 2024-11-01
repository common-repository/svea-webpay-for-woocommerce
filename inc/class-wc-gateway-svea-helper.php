<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Svea\WebPay\WebPay;
use Svea\WebPay\WebPayItem;

/**
 * Static class with helper functions and static values
 *
 */
class WC_Gateway_Svea_Helper {

	/**
	 * List of allowed countries for the async payment methods
	 *
	 * @var array
	 */
	const ALLOWED_COUNTRIES_ASYNC = [ 'SE', 'DK', 'NO', 'FI', 'NL', 'DE' ];

	/**
	 * List of allowed currencies for the async payment methods
	 *
	 * @var array
	 */
	const ALLOWED_CURRENCIES_ASYNC = [ 'DKK', 'EUR', 'NOK', 'SEK' ];

	/**
	 * List of allowed countries for the invoice payment method
	 *
	 * @var array
	 */
	const ALLOWED_COUNTRIES_SYNC = [ 'SE', 'NO', 'FI', 'DK', 'NL', 'DE' ];

	/**
	 * Fetches the gateway currently used.
	 *
	 * @return object the payment gateway currently used
	 */
	public static function get_current_gateway() {
		$payment_gateways = WC_Payment_Gateways::instance();

		if ( is_null( $payment_gateways ) ) {
			return false;
		}

		$available_gateways = $payment_gateways->get_available_payment_gateways();
		$current_gateway = '';

		$default_gateway = get_option( 'woocommerce_default_gateway' );
		if ( count( $available_gateways ) > 0 ) {
			// Chosen Method
			if ( isset( WC()->session->chosen_payment_method ) && isset( $available_gateways[ WC()->session->chosen_payment_method ] ) ) {
				$current_gateway = $available_gateways[ WC()->session->chosen_payment_method ];
			} else if ( isset( $available_gateways[ $default_gateway ] ) ) {
				$current_gateway = $available_gateways[ $default_gateway ];
			} else {
				$current_gateway = current( $available_gateways );
			}
		}

		if ( ! is_null( $current_gateway ) ) {
			return $current_gateway;
		} else {
			return false;
		}
	}

	/**
	 * Clear all admin notices
	 *
	 * @return  void
	 */
	public static function clear_admin_notices() {
		delete_option( 'sveawebpay_deferred_admin_notices' );
	}

	/**
	 * Displays a banner with a message in the top of the admin-ui
	 *
	 * @param   string  $message    the message that should be displayed
	 * @param   string  $type       the type of message you want to display (error|updated)
	 * @return  void
	 */
	public static function add_admin_notice( $message = '', $type = 'updated' ) {
		$notices = get_option( 'sveawebpay_deferred_admin_notices', [] );
		$notices[] = [
			'type'    => $type,
			'message' => $message,
		];

		update_option( 'sveawebpay_deferred_admin_notices', $notices );
	}

	/**
	 * Create svea order from a WooCommerce order
	 *
	 * @param   WC_Order                                    $order      The WooCommerce order being processed
	 * @param   Svea\WebPay\Config\ConfigurationProvider    $config     The Svea configuration used for the order creation
	 * @return  Svea\WebPay\BuildOrder\CreateOrderBuilder               The order object created
	 */
	public static function create_svea_order( $order, $config ) {
		$svea_order = WebPay::createOrder( $config );

		/**
		 * Add all order items to the Svea Order
		 */
		foreach ( $order->get_items( 'line_item' ) as $order_item ) {
			$product = $order_item->get_product();

			if ( $product->exists() && $order_item->get_quantity() ) {
				$id = false;

				if ( $product->get_sku() ) {
					$id = $product->get_sku();
				} else {
					$id = $product->get_id();
				}

				$item_name = $order_item->get_name();

				$item_tax_percentage = 0.00;

				if ( $order->get_total_tax() > 0 && $order->get_line_total( $order_item, false ) > 0 ) {
					$item_tax_percentage = number_format( ( $order->get_line_tax( $order_item ) / $order->get_line_total( $order_item, false ) ) * 100, 2, '.', '' );
				}

				$quantity = max( 1, $order_item->get_quantity() );

				$svea_order->addOrderRow(
					WebPayItem::orderRow()
						->setArticleNumber( (string) $id )
						->setQuantity( floatval( $quantity ) )
						->setAmountIncVat( floatval( $order->get_item_subtotal( $order_item, true ) ) )
						->setName( $item_name )
						->setUnit( 'st' )
						->setVatPercent( intval( round( $item_tax_percentage ) ) )
						->setDiscountPercent( 0 )
				);
			}
		}

		$coupons = $order->get_items( 'coupon' );

		if ( ! empty( $coupons ) ) {
			/**
			 * Add custom Woo cart fixed coupons
			 */
			foreach ( $coupons as $coupon ) {
				$coupon_amount = $coupon->get_discount() + $coupon->get_discount_tax();

				$svea_order->addDiscount(
					WebPayItem::fixedDiscount()
						->setAmountIncVat( floatval( $coupon_amount ) )
						->setDiscountId( $coupon->get_id() )
						->setName( $coupon->get_code() )
				);
			}
		} else if ( $order->get_total_discount( false ) > 0 ) {
			$svea_order->addDiscount(
				WebPayItem::fixedDiscount()
					->setAmountIncVat( floatval( $order->get_total_discount( false ) ) )
					->setName( __( 'Discount', 'svea-webpay-for-woocommerce' ) )
			);
		}

		/**
		 * Add custom Woo cart fees as order items
		 */
		$svea_order = self::add_fee_svea_order( $order, $svea_order );

		/**
		 * Add shipping to the svea order
		 */
		$svea_order = self::add_shipping_svea_order( $order, $svea_order );

		return $svea_order;
	}

	/**
	 * Add WooCommerce fees to Svea order
	 *
	 * @param   WC_Order    $order  the woocommerce order
	 * @param   Svea\WebPay\BuildOrder\CreateOrderBuilder    $svea_order     the svea order builder
	 *
	 * @return  Svea\WebPay\BuildOrder\CreateOrderBuilder    the svea order passed in to the function
	 */
	private static function add_fee_svea_order( $order, $svea_order ) {
		$wc_gateway = wc_get_payment_gateway_by_order( $order );

		foreach ( $order->get_fees() as $fee ) {

			$id = sanitize_title( $fee->get_name() );
			$item_name = $fee->get_name();

			$item_tax_percentage = 0.00;

			$line_tax = floatval( $fee->get_total_tax() );
			$line_total = floatval( $fee->get_total() );

			if ( $line_tax > 0 ) {
				$item_tax_percentage = ( $line_tax / ( $line_total ) ) * 100;
			}

			/**
			 * Add the custom invoice fee with sveas built-in function
			 */
			if ( $wc_gateway instanceof WC_Gateway_Svea_Invoice ) {
				if ( $item_name === $wc_gateway->get_option( 'invoice_fee_label_' . strtolower( $order->get_billing_country() ) ) ) {
					$svea_order->addFee(
						WebPayItem::shippingFee()
							->setName( __( 'Fee for invoice', 'svea-webpay-for-woocommerce' ) )
							->setAmountIncVat( $line_total + $line_tax )
							->setVatPercent( intval( round( $item_tax_percentage ) ) )
							->setUnit( 'st' )
							->setDiscountPercent( 0 )
							->setShippingId( $id )
					);
					continue;
				}
			}

			/**
			 * Add other fees as order rows since svea doesn't support
			 * custom fees.
			 */
			$svea_order->addOrderRow(
				WebPayItem::orderRow()
					->setArticleNumber( (string) $id )
					->setQuantity( 1 )
					->setAmountIncVat( $line_total + $line_tax )
					->setName( $item_name )
					->setUnit( 'st' )
					->setVatPercent( intval( round( $item_tax_percentage ) ) )
					->setDiscountPercent( 0 )
			);
		}

		return $svea_order;
	}

	/**
	 * Add WooCommerce shipping to Svea order
	 *
	 * @param   WC_Order    $order  the woocommerce order
	 * @param   Svea\WebPay\BuildOrder\CreateOrderBuilder    $svea_order     the svea order builder
	 *
	 * @return  Svea\WebPay\BuildOrder\CreateOrderBuilder    the svea order passed in to the function
	 */
	private static function add_shipping_svea_order( $order, $svea_order ) {
		if ( $order->get_shipping_total() <= 0 ) {
			return $svea_order;
		}

		$shipping_tax_percentage = 0;

		if ( $order->get_shipping_tax() > 0 ) {
			$shipping_tax_percentage = number_format( ( $order->get_shipping_tax() / $order->get_shipping_total() ) * 100, 2, '.', '' );
		}

		$svea_order->addFee(
			WebPayItem::shippingFee()
					->setName( (string) __( 'Shipping', 'svea-webpay-for-woocommerce' ) )
					->setAmountIncVat( floatval( $order->get_shipping_total() ) + floatval( $order->get_shipping_tax() ) )
					->setVatPercent( intval( round( $shipping_tax_percentage ) ) )
					->setUnit( 'st' )
					->setShippingId( 'shipping' )
					->setDiscountPercent( 0 )
		);

		return $svea_order;
	}

	/**
	 * Gets the error message from the provided svea error code
	 *
	 * @param   int     $error_code     error code from svea
	 * @return  string  the error message that represents the provided error code
	 */
	public static function get_svea_error_message( $error_code ) {
		switch ( $error_code ) {
			case 107:
				return __( 'Transaction was rejected by the bank. Please try again with another payment method.', 'svea-webpay-for-woocommerce' );
			case 108:
				return __( 'Transaction cancelled. You can choose the same payment method again to process your order by selecting it below and continuing.', 'svea-webpay-for-woocommerce' );
			case 307:
				return __( 'The selected currency is not supported for card payments.', 'svea-webpay-for-woocommerce' );
			case 316:
				return __( 'The card type is not configured for this merchant.', 'svea-webpay-for-woocommerce' );
			case 30000:
			case 30001:
			case 30002:
			case 30003:
				return __( 'Unfortunately your purchase could not be carried out since the credit check was rejected. Please try again with another payment method.', 'svea-webpay-for-woocommerce' );
			case 40000:
			case 40001:
			case 40002:
			case 40003:
			case 40004:
			case 40005:
				return __( 'Unfortunately we could not find your customer information. Please check the entered data and try to make a purchase again.', 'svea-webpay-for-woocommerce' );
			case 40006:
				return __( 'Strong authentication failed.', 'svea-webpay-for-woocommerce' );
			default:
				return __( 'There was a problem with processing your order, please select another payment method and try again.', 'svea-webpay-for-woocommerce' );
		}
	}

	/**
	 * Get the currency for the provided country
	 *
	 * @param   string  $country    country to get currency for
	 * @return  string  currency of the provided country
	 */
	public static function get_country_currency( $country ) {
		$country = strtoupper( $country );

		switch ( $country ) {
			case 'SE':
				return 'SEK';
			case 'DK':
				return 'DKK';
			case 'DE':
			case 'FI':
			case 'NL':
				return 'EUR';
			case 'NO':
				return 'NOK';
		}

		return '';
	}
}
