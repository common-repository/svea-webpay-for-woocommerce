<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class to handle ajax requests for the Svea plugin
 */
class WC_SveaWebPay_Gateway_Admin_Functions {

	/**
	 * Nonce for the cancel action, used to authorize
	 * requests
	 *
	 * @var     string
	 */
	const CANCEL_NONCE = 'svea_cancel_sec';

	/**
	 * Nonce for the credit action, used to authorize
	 * requests
	 *
	 * @var     string
	 */
	const CREDIT_NONCE = 'svea_credit_sec';

	/**
	 * Nonce for the deliver action, used to authorize
	 * requests
	 *
	 * @var     string
	 */
	const DELIVER_NONCE = 'svea_deliver_sec';

	public function __construct() {
		add_action( 'admin_post_svea_webpay_admin_deliver_order', [ $this, 'deliver_order' ] );
		add_action( 'admin_post_svea_webpay_admin_credit_order', [ $this, 'credit_order' ] );
		add_action( 'admin_post_svea_webpay_admin_cancel_order', [ $this, 'cancel_order' ] );
	}

	/**
	 * Cancel the order with the provided order id
	 *
	 * @return  void
	 */
	public function cancel_order() {
		if ( ! isset( $_GET['security'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['security'] ) ), self::CANCEL_NONCE ) ) {
			return;
		}

		if ( ! isset( $_GET['order_id'] ) ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$order = wc_get_order( intval( $_GET['order_id'] ) );

		if ( ! $order ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

		if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		if ( $order->get_payment_method() === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
			$wc_invoice = WC_Gateway_Svea_Invoice::init();

			$result = $wc_invoice->cancel_order( $order, $svea_order_id );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Card::GATEWAY_ID ) {
			$wc_card = WC_Gateway_Svea_Card::init();

			$result = $wc_card->cancel_order( $order, $svea_order_id );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
			$wc_part_pay = WC_Gateway_Svea_Part_Pay::init();

			$result = $wc_part_pay->cancel_order( $order, $svea_order_id );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else {
			wp_safe_redirect( admin_url() );
		}
	}

	/**
	 * Credit the order with the provided order id
	 * and/or specified order items
	 *
	 * @return  void
	 */
	public function credit_order() {
		if ( ! isset( $_GET['security'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['security'] ) ), self::CREDIT_NONCE ) ) {
			return;
		}

		if ( ! isset( $_GET['order_id'] ) ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$order = wc_get_order( intval( $_GET['order_id'] ) );

		if ( ! $order ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

		if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		if ( $order->get_payment_method() === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
			$wc_invoice = WC_Gateway_Svea_Invoice::init();

			$order_item_ids = isset( $_GET['order_items'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['order_items'] ) ) ) : [];
			$order_item_ids = array_map( 'intval', $order_item_ids );

			$result = $wc_invoice->credit_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Card::GATEWAY_ID ) {
			$wc_card = WC_Gateway_Svea_Card::init();

			$order_item_ids = isset( $_GET['order_items'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['order_items'] ) ) ) : [];
			$order_item_ids = array_map( 'intval', $order_item_ids );

			$result = $wc_card->credit_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
			$wc_direct_bank = WC_Gateway_Svea_Direct_Bank::init();

			$order_item_ids = isset( $_GET['order_items'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['order_items'] ) ) ) : [];
			$order_item_ids = array_map( 'intval', $order_item_ids );

			$result = $wc_direct_bank->credit_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else {
			wp_safe_redirect( admin_url() );
		}
	}

	/**
	 * Deliver the order with the provided order id
	 * and/or specified order items
	 *
	 * @return  void
	 */
	public function deliver_order() {
		if ( ! isset( $_GET['security'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['security'] ) ), self::DELIVER_NONCE ) ) {
			return;
		}

		if ( ! isset( $_GET['order_id'] ) ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$order = wc_get_order( intval( $_GET['order_id'] ) );

		if ( ! $order ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

		if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
			wp_safe_redirect( admin_url() );
			return;
		}

		if ( $order->get_payment_method() === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
			$wc_invoice = WC_Gateway_Svea_Invoice::init();

			$order_item_ids = isset( $_GET['order_items'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['order_items'] ) ) ) : [];
			$order_item_ids = array_map( 'intval', $order_item_ids );

			$result = $wc_invoice->deliver_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
			$wc_part_pay = WC_Gateway_Svea_Part_Pay::init();

			$order_item_ids = [];

			$result = $wc_part_pay->deliver_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else if ( $order->get_payment_method() === WC_Gateway_Svea_Card::GATEWAY_ID ) {
			$wc_card = WC_Gateway_Svea_Card::init();

			$order_item_ids = [];

			$result = $wc_card->deliver_order( $order, $svea_order_id, $order_item_ids );

			if ( $result['success'] ) {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'updated'
				);
			} else {
				WC_Gateway_Svea_Helper::add_admin_notice(
					$result['message'],
					'error'
				);
			}

			wp_safe_redirect( admin_url( sprintf( 'post.php?post=%s&action=edit', $order->get_id() ) ) );
		} else {
			wp_safe_redirect( admin_url() );
		}
	}
}

/**
 * Instantiate this class when loaded
 */
new WC_SveaWebPay_Gateway_Admin_Functions();
