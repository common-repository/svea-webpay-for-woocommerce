<?php
/**
 * @wordpress-plugin
 * Plugin Name: Svea WebPay for WooCommerce
 * Description: Supercharge your WooCommerce Store with powerful features to pay via Svea Ekonomi Creditcard, Direct Bank Payment, Invoice and Part Payment.
 * Version: 3.2.1
 * Author: The Generation
 * Author URI: https://thegeneration.se/
 * Domain Path: /languages
 * Text Domain: svea-webpay-for-woocommerce
 *
 * WC tested up to: 8.2.1
 */

/**
 * Exit if accessed directly
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the plugin base directory
if ( ! defined( 'WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR' ) ) {
	define( 'WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR', __DIR__ );
}

if ( ! class_exists( 'Svea_WebPay_For_WooCommerce' ) ) :

	/**
	 * Main plugin class
	 */
	class Svea_WebPay_For_WooCommerce {

		/**
		 * Plugin version, used for cache-busting of style and script file references.
		 *
		 * @var     string
		 */
		const VERSION = '3.2.1';

		/**
		 * Plugin slug
		 *
		 * @var     string
		 */
		const PLUGIN_SLUG = 'svea-webpay-for-woocommerce';

		/**
		 * Description
		 *
		 * @var string
		 */
		public $plugin_description;

		/**
		 * General class constructor where we'll setup our actions, hooks, and shortcodes.
		 *
		 * @return Svea_WebPay_For_WooCommerce
		 */
		public function __construct() {
			/**
			 * Define the plugin base url
			 */
			if ( ! defined( 'WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL' ) ) {
				define( 'WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL', plugins_url( '/', __FILE__ ) );
			}

			register_activation_hook( __FILE__, [ $this, 'plugin_activation' ] );
			register_deactivation_hook( __FILE__, [ $this, 'plugin_deactivation' ] );

			load_plugin_textdomain( self::PLUGIN_SLUG, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			/**
			 * Check if woocommerce is activated, else display a message and deactivate the plugin
			 */
			if ( ! self::is_woocommerce_installed() ) {
				// phpcs:ignore
				if ( isset( $_GET['action'] ) && ! in_array( $_GET['action'], [ 'activate-plugin', 'upgrade-plugin', 'activate', 'do-plugin-upgrade' ], true ) ) {
					return;
				}

				$notices = get_option( 'sveawebpay_deferred_admin_notices', [] );
				$notices[] = [
					'type'    => 'error',
					'message' => __( 'WooCommerce Svea WebPay Gateway has been deactivated because WooCommerce is not installed. Please install WooCommerce and re-activate.', 'svea-webpay-for-woocommerce' ),
				];

				update_option( 'sveawebpay_deferred_admin_notices', $notices );
				add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
				add_action( 'admin_init', [ $this, 'deactivate_gateway' ] );
				return;
			}

			$this->plugin_description = __( 'Supercharge your WooCommerce Store with powerful features to pay via Svea Ekonomi Creditcard, Direct Bank Payment, Invoice and Part Payment.', 'svea-webpay-for-woocommerce' );

			add_action( 'plugins_loaded', [ $this, 'init' ] );
			add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );

			add_action( 'admin_notices', [ $this, 'check_compatibility' ] );

			add_action( 'woocommerce_attribute_label', [ $this, 'label_order_item_meta' ], 20, 2 );

			// Hide these for now, we'll have to wait for Svea to fix their API
			// add_action( 'woocommerce_order_item_add_action_buttons', array(&$this, 'display_admin_action_buttons') );

			add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'admin_display_svea_order_id' ] );

			add_action( 'add_meta_boxes', [ $this, 'add_admin_functions_meta_box' ] );
			add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_invoice_fee' ], 40 );

			add_action( 'woocommerce_after_checkout_validation', [ $this, 'checkout_validation_handler' ], 10, 2 );

			add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_order_item_meta' ], 10, 1 );

			add_filter( 'woocommerce_get_order_item_totals', [ $this, 'receipt_display_svea_order_id' ], 10, 2 );
			add_filter( 'woocommerce_payment_gateways', [ $this, 'woocommerce_add_gateway_svea_gateway' ] );

			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'checkout_enqueue_scripts' ] );

			add_action( 'admin_init', [ $this, 'check_plugin_updates' ] );

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'settings_link' ] );

			add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', [ $this, 'should_update_payment_method' ], 10, 3 );

			add_action( 'woocommerce_order_status_completed', [ $this, 'sync_delivered_order' ], 10, 1 );
			add_action( 'woocommerce_order_status_cancelled', [ $this, 'sync_cancelled_order' ], 10, 1 );
			add_action( 'woocommerce_order_status_refunded', [ $this, 'sync_refunded_order' ], 10, 1 );

			// Part payment widget
			add_action( 'init', [ $this, 'product_part_payment_widget' ], 11, 1 );
		}

		/**
		 * Initializes and loads essential classes
		 *
		 * @return  void
		 */
		public function init() {
			/**
			 * Load the Svea integration package.
			 */
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/vendor/autoload.php' );

			/**
			 * Load funtionality classes
			 */
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-shortcodes.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-ajax-functions.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-admin-functions.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-cron-functions.php' );

			/**
			 * Load the helper files
			 */
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-helper.php' );

			/**
			 * Load the Svea configuration classes
			 */
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-svea-config-production.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-svea-config-test.php' );

			/**
			 * If WC_Payment Gateway isn't set don't load class files
			 * to avoid error
			 */
			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}

			/**
			 * Load all Svea payment gateway classes
			 */
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-card.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-direct-bank.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-invoice.php' );
			require_once( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/inc/class-wc-gateway-svea-part-pay.php' );
		}

		public function check_plugin_updates() {
			$svea_db_version = get_option( 'sveawebpay_plugin_version', false );

			/**
			 * See if the version has been changed
			 */
			if ( ! $svea_db_version || $svea_db_version !== self::VERSION ) {
				// Run plugin update function here
				update_option( 'sveawebpay_plugin_version', self::VERSION );
			}
		}


		/**
		 * Check if WooCommerce is installed and activated
		 *
		 * @return  boolean     whether or not WooCommerce is installed
		 */
		public static function is_woocommerce_installed() {
			/**
			 * Get a list of active plugins
			 */
			$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );

			$is_woocommerce_installed = false;

			/**
			 * Loop through the active plugins
			 */
			foreach ( $active_plugins as $plugin ) {
				/**
				 * If the plugin name matches WooCommerce
				 * it means that WooCommerce is active
				 */
				if ( preg_match( '/.+\/woocommerce\.php/', $plugin ) ) {
					$is_woocommerce_installed = true;
					break;
				}
			}

			return $is_woocommerce_installed;
		}

		/**
		 * Check compatibility with PHP- and WooCommerce-versions
		 *
		 * @return void
		 */
		public function check_compatibility() {

			/**
			 * Only display message if the current user is administrator
			 */
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			/**
			 * Required modules by the Svea WebPay Integration package
			 */
			if ( ! extension_loaded( 'soap' ) || ! class_exists( 'SoapClient' ) ) {
				printf(
					'<div class="error"><h3>Svea WebPay</h3><p>' .
					esc_html__( 'The PHP Module <strong>Soap</strong> is not enabled. Svea WebPay requires this module to be enabled for it to function properly. Talk to your web host and make sure it is enabled.', 'svea-webpay-for-woocommerce' ) .
					'</p></div>'
				);
			}

			/**
			 * Versions that are tested with this module.
			 * Remember to test this with each new version.
			 */
			$php_version = '5.5.0';
			$woocommerce_version = '3.0.0';

			if ( version_compare( PHP_VERSION, $php_version, '<' ) ) {
				printf(
					'<div class="error"><h3>Svea WebPay</h3><p>' .
					// translators: %1$s is the current PHP version, %2$s is the supported PHP-version
					esc_html__( 'Your PHP version is <strong>%1$s</strong>, lower than the supported version for Svea WebPay for WooCommerce, <strong>%2$s</strong>. The integration might not work as expected.', 'svea-webpay-for-woocommerce' ) .
					'</p></div>',
					esc_html( PHP_VERSION ),
					esc_html( $php_version )
				);
			}

			if ( defined( 'WOOCOMMERCE_VERSION' )
			&& version_compare( WOOCOMMERCE_VERSION, $woocommerce_version, '<' ) ) {
				printf(
					'<div class="error"><h3>Svea WebPay</h3><p>' .
					// translators: %1$s is the current PHP version, %2$s is the supported PHP-version
					esc_html__( 'Your WooCommerce version is <strong>%1$s</strong>, lower than the supported version for Svea WebPay for WooCommerce, <strong>%2$s</strong>. The integration might not work as expected.', 'svea-webpay-for-woocommerce' ) .
					'</p></div>',
					esc_html( WOOCOMMERCE_VERSION ),
					esc_html( $woocommerce_version )
				);

				if ( version_compare( WOOCOMMERCE_VERSION, '4.0.0', '<' ) ) {
					printf(
						'<div class="error"><h3>Svea WebPay</h3><p>' .
						esc_html__( 'Version 4.0.0 of WooCommerce brought breaking changes and any version lower than that will not work with this version of the Svea WebPay module. The module has been deactivated. Please upgrade WooCommerce and activate the gateway again.', 'svea-webpay-for-woocommerce' ) .
						'</p></div>',
						esc_html( WOOCOMMERCE_VERSION ),
						esc_html( $woocommerce_version )
					);

					// Version 4.0.0 brings breaking changes and lower version will not work with this module
					// Deactivate this plugin if version is too low.
					$this->deactivate_gateway();
				}
			}

		}

		/**
		 * Handles plugin activation
		 *
		 * @return void
		 */
		public function plugin_activation() {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			if ( ! self::is_woocommerce_installed() ) {

				$notices = get_option( 'sveawebpay_deferred_admin_notices', [] );
				$notices[] = [
					'type'    => 'error',
					'message' => esc_html__( 'WooCommerce Svea WebPay Gateway has been deactivated because WooCommerce is not installed. Please install WooCommerce and re-activate.' ),
				];

				update_option( 'sveawebpay_deferred_admin_notices', $notices );
				add_action( 'admin_notices', [ &$this, 'display_admin_notices' ] );

				add_action( 'admin_init', [ &$this, 'deactivate_gateway' ] );
				return;
			}

			$notices = get_option( 'sveawebpay_deferred_admin_notices', [] );
			$notices[] = [
				'type'    => 'updated',
				'message' => __( 'WooCommerce SveaWebPay Payment Gateway has now been activated, you can configure the different gateways', 'svea-webpay-for-woocommerce' ) .
				' <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_svea_card' ) . '">' . __( 'on this page', 'svea-webpay-for-woocommerce' ) .
				'</a>. ' . __( 'If you don\'t have a contract with SveaWebPay please contact them', 'svea-webpay-for-woocommerce' ) .
				' <a href="https://www.svea.com/se/sv/foretag/betallosningar/betallosningar-for-e-handel/" target="_BLANK" rel="noopener">' . __( 'here', 'svea-webpay-for-woocommerce' ) . '</a>.',
			];

			update_option( 'sveawebpay_deferred_admin_notices', $notices );

			update_option( 'sveawebpay_plugin_version', self::VERSION );
		}

		/**
		 * Handles plugin de-activation
		 *
		 * @TODO
		 * @return  void
		 */
		public function plugin_deactivation() { }

		/**
		 * Display admin notices saved in the cache.
		 *
		 * @return  void
		 */
		public function display_admin_notices() {
			$notices = get_option( 'sveawebpay_deferred_admin_notices' );

			if ( ! $notices ) {
				return;
			}

			foreach ( $notices as $notice ) {
				echo '<div class="' . esc_attr( $notice['type'] ) . '"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
			}

			delete_option( 'sveawebpay_deferred_admin_notices' );
		}

		/**
		 * Deactivate the WooCommerce Svea WebPay Gateway
		 *
		 * @return  void
		 */
		public function deactivate_gateway() {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				return;
			}

			deactivate_plugins( plugin_basename( __FILE__ ) );
		}

		/**
		 * Add settings link on the plugin page.
		 *
		 * @param   array   $links  associative array of links
		 * @return  array   associative array of links
		 */
		public function settings_link( $links ) {
			$settings_link =
			'<a href="admin.php?page=wc-settings&tab=checkout&section=wc_gateway_svea_card">' .
				__( 'Settings', 'svea-webpay-for-woocommerce' ) .
			'</a>';
			array_unshift( $links, $settings_link );
			return $links;
		}

		/**
		 * Disable Subscriptions default way of changing payment method
		 * in favor to implement our own way
		 *
		 * @param   boolean             $update     whether or not the payment method should be updated
		 * @param   string              $new_payment_method     the payment method that the subscription is changed to
		 * @param   WC_Subscription     $subscription   the subscription object
		 * @return  boolean     whether or not the payment method should be updated
		 */
		public function should_update_payment_method( $update, $new_payment_method, $subscription ) {
			if ( $new_payment_method === WC_Gateway_Svea_Invoice::GATEWAY_ID
			|| $new_payment_method === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$update = false;
			}

			return $update;
		}

		/**
		 * Display buttons for admin actions
		 *
		 * @return void
		 */
		public function display_admin_action_buttons() {
			global $post;

			if ( is_null( $post ) || ! isset( $post->ID ) ) {
				return;
			}

			$order = wc_get_order( $post->ID );

			if ( ! $order ) {
				return;
			}

			$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

			if ( strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			$payment_method = $order->get_payment_method();

			if ( $payment_method === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
				$action_buttons_function = 'WC_Gateway_Svea_Direct_Bank::display_admin_action_buttons';
			} else if ( $payment_method === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$action_buttons_function = 'WC_Gateway_Svea_Invoice::display_admin_action_buttons';
			} else if ( $payment_method === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$action_buttons_function = 'WC_Gateway_Svea_Card::display_admin_action_buttons';
			} else {
				return;
			}

			call_user_func( $action_buttons_function );
		}

		/**
		 * Makes the labels of order item meta for
		 *
		 * @return string
		 */
		public function label_order_item_meta( $label, $meta_key ) {
			if ( $meta_key === 'svea_delivered' ) {
				return __( 'Delivered in Svea', 'svea-webpay-for-woocommerce' );
			} else if ( $meta_key === 'svea_credited' ) {
				return __( 'Credited in Svea', 'svea-webpay-for-woocommerce' );
			}

			return $label;
		}

		/**
		 * Adds an invoice fee to the WooCommerce cart if
		 * it has been set in the invoice gateway
		 *
		 * @return void
		 */
		public function add_invoice_fee() {
			$current_gateway = WC_Gateway_Svea_Helper::get_current_gateway();

			if ( ! $current_gateway || get_class( $current_gateway ) !== 'WC_Gateway_Svea_Invoice' ) {
				return;
			}

			WC_Gateway_Svea_Invoice::init()->add_invoice_fee();
		}

		/**
		 * Register and enqueue stylesheets and javascripts for backend use
		 *
		 * @return void
		 */
		public function admin_enqueue_scripts() {
			/**
			 * Link to the font awesome stylesheet for icons
			 */
			wp_enqueue_style( 'font-awesome-regular', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/fonts/fontawesome/css/regular.min.css', [], '5.14.0' );

			wp_enqueue_style( 'sveawebpay-backend-css', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/css/backend.min.css', [], self::VERSION );
			wp_enqueue_script( 'sveawebpay-backend-js', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/js/backend.min.js', [ 'jquery' ], self::VERSION, true );

			global $post, $woocommerce;

			if ( ! is_null( $post ) ) {
				$svea_data['adminCreditUrl'] = admin_url(
					'admin-post.php?action=svea_webpay_admin_credit_order&order_id='
					. $post->ID
					. '&security='
					. wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::CREDIT_NONCE )
					. '&order_items='
				);

				$svea_data['adminDeliverUrl'] = admin_url(
					'admin-post.php?action=svea_webpay_admin_deliver_order&order_id='
					. $post->ID
					. '&security='
					. wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::DELIVER_NONCE )
					. '&order_items='
				);
			}

			$countries = $woocommerce->countries->get_allowed_countries();

			$only_one_allowed_country = false;

			if ( count( $countries ) <= 1 ) {
				$only_one_allowed_country = array_keys( $countries )[0];
			}

			$svea_data['gaSecurity'] = wp_create_nonce( WC_SveaWebPay_Gateway_Ajax_Functions::GET_ADDRESS_NONCE_NAME );
			$svea_data['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			$svea_data['onlyOneAllowedCountry'] = ( $only_one_allowed_country ? $only_one_allowed_country : false );

			/**
			 * Localize the javascript with Svea data
			 */
			wp_localize_script( 'sveawebpay-backend-js', 'Svea', $svea_data );

			$phrases = [
				// translators: %d is the number of items to be credited
				'confirm_credit_items'           => __( 'Are you sure you want to credit %d items?', 'svea-webpay-for-woocommerce' ),
				// translators: %d is the number of items to be delivered
				'confirm_deliver_items'          => __( 'Are you sure you want to deliver %d items?', 'svea-webpay-for-woocommerce' ),
				'not_selected_any_items'         => __( 'You have not selected any items yet.', 'svea-webpay-for-woocommerce' ),
				'no_payment_plans_country_total' => __( 'There are no available payment plans for this country and order total.', 'svea-webpay-for-woocommerce' ),
				'your_address_was_found'         => __( 'Your address was found.', 'svea-webpay-for-woocommerce' ),
				'part_payment_plans'             => __( 'Part Payment Plans', 'svea-webpay-for-woocommerce' ),
				'company_name'                   => __( 'Company Name', 'svea-webpay-for-woocommerce' ),
				'invoice_fee'                    => __( 'Invoice fee', 'svea-webpay-for-woocommerce' ),
				'includes'                       => __( 'Includes', 'svea-webpay-for-woocommerce' ),
				'vat'                            => __( 'VAT', 'svea-webpay-for-woocommerce' ),
				'could_not_get_address'          => __( 'An error occurred whilst getting your address. Please try again later.', 'svea-webpay-for-woocommerce' ),
			];

			/**
			 * Localize the javascript with translated phrases
			 */
			wp_localize_script( 'sveawebpay-backend-js', 'Phrases', $phrases );
		}

		/**
		 * Register and enqueue stylesheets and javascripts
		 *
		 * @return  void
		 */
		public function checkout_enqueue_scripts() {
			/**
			 * Only enqueue scripts and styles in the checkout page
			 */
			if ( ( ! function_exists( 'is_checkout' ) || ! is_checkout() )
			&& ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() )
			&& ( ! function_exists( 'is_product' ) || ! is_product() ) ) {
				return;
			}

			/**
			 * Link to the font awesome stylesheet for icons
			 */
			wp_enqueue_style( 'font-awesome-regular', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/fonts/fontawesome/css/regular.min.css', [], '5.14.0' );

			/**
			 * Enqueue styles and javascript, cache-bust using versioning
			 */
			wp_enqueue_style( 'sveawebpay-styles', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/css/frontend.min.css', [], self::VERSION );
			wp_enqueue_script( 'sveawebpay-js', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/js/frontend.min.js', [ 'jquery' ], self::VERSION, true );

			global $woocommerce;

			$countries = $woocommerce->countries->get_allowed_countries();

			$only_one_allowed_country = false;

			if ( count( $countries ) <= 1 ) {
				$only_one_allowed_country = array_keys( $countries )[0];
			}

			$svea_data = [];

			$wc_invoice = WC_Gateway_Svea_Invoice::init();
			$wc_part_pay = WC_Gateway_Svea_Part_Pay::init();

			$svea_data['gaSecurity'] = wp_create_nonce( WC_SveaWebPay_Gateway_Ajax_Functions::GET_ADDRESS_NONCE_NAME );
			$svea_data['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			$svea_data['onlyOneAllowedCountry'] = ( $only_one_allowed_country ? $only_one_allowed_country : false );
			$svea_data['sameShippingAsBilling'] = [
				$wc_invoice->id  => $wc_invoice->enabled === 'yes' ? ( $wc_invoice->same_shipping_as_billing ? true : false ) : false,
				$wc_part_pay->id => $wc_part_pay->enabled === 'yes' ? ( $wc_part_pay->same_shipping_as_billing ? true : false ) : false,
			];

			$svea_data['isPayPage'] = is_checkout_pay_page() ? true : false;

			if ( is_checkout_pay_page() ) {
				$svea_data['customerCountry'] = WC()->customer->get_billing_country();
			}

			/**
			 * Localize the javascript with Svea data
			 */
			wp_localize_script( 'sveawebpay-js', 'Svea', $svea_data );

			$phrases = [
				'no_payment_plans_country_total' => __( 'There are no available payment plans for this country and order total.', 'svea-webpay-for-woocommerce' ),
				'your_address_was_found'         => __( 'Your address was found.', 'svea-webpay-for-woocommerce' ),
				'part_payment_plans'             => __( 'Part Payment Plans', 'svea-webpay-for-woocommerce' ),
				'company_name'                   => __( 'Company Name', 'svea-webpay-for-woocommerce' ),
				'invoice_fee'                    => __( 'Invoice fee', 'svea-webpay-for-woocommerce' ),
				'includes'                       => __( 'Includes', 'svea-webpay-for-woocommerce' ),
				'vat'                            => __( 'VAT', 'svea-webpay-for-woocommerce' ),
				'could_not_get_address'          => __( 'An error occurred whilst getting your address. Please try again later.', 'svea-webpay-for-woocommerce' ),
			];

			/**
			 * Localize the javascript with translated phrases
			 */
			wp_localize_script( 'sveawebpay-js', 'Phrases', $phrases );
		}

		/**
		 * Add the payment Gateways to WooCommerce
		 *
		 * @param   array   $methods    associative array with payment gateways
		 *
		 * @return  array   associative array with payment gateways
		 */
		public function woocommerce_add_gateway_svea_gateway( $methods ) {
			$methods[] = 'WC_Gateway_Svea_Card';
			$methods[] = 'WC_Gateway_Svea_Invoice';
			$methods[] = 'WC_Gateway_Svea_Direct_Bank';
			$methods[] = 'WC_Gateway_Svea_Part_Pay';

			return $methods;
		}

		/**
		 * Hook the part payment widget function on the part payment gateway
		 *
		 * @return void
		 */
		public function product_part_payment_widget() {
			$wc_gateway_part_pay = WC_Gateway_Svea_Part_Pay::init();

			$product_widget_position = intval( $wc_gateway_part_pay->get_option( 'product_widget_position' ) );

			if ( $product_widget_position <= 0 ) {
				$product_widget_position = 11;
			}

			add_action( 'woocommerce_single_product_summary', [ $wc_gateway_part_pay, 'product_part_payment_widget' ], $product_widget_position, 1 );
		}

		/**
		 * Parent function that calls checkout validation handlers depending
		 * on payment gateway
		 *
		 * @return  void
		 */
		public function checkout_validation_handler( $fields, $errors ) {
			if ( ! isset( $fields['payment_method'] ) ) {
				return;
			}

			$payment_method = $fields['payment_method'];

			/**
			 * Use the validation handlers in the gateway-classes depending
			 * on the chosen payment method
			 */
			if ( $payment_method === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
				WC_Gateway_Svea_Direct_Bank::init()->checkout_validation_handler( $fields, $errors );
			} else if ( $payment_method === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				WC_Gateway_Svea_Invoice::init()->checkout_validation_handler( $fields, $errors );
			} else if ( $payment_method === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
				WC_Gateway_Svea_Part_Pay::init()->checkout_validation_handler( $fields, $errors );
			}
		}

		/**
		 * Sync refunded orders to Svea
		 *
		 * @param   int     $order_id   id of the order being refunded
		 *
		 * @return  void
		 */
		public function sync_refunded_order( $order_id ) {
			$wc_order = new WC_Order( $order_id );

			$svea_order_id = get_post_meta( $wc_order->get_id(), '_svea_order_id', true );

			if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			$payment_method_id = $wc_order->get_payment_method();

			$wc_gateway = false;

			if ( $payment_method_id === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Card::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Invoice::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Direct_Bank::init();
			}

			if ( $wc_gateway !== false
			&& $wc_gateway->get_option( 'disable_order_sync' ) !== 'yes' ) {
				$wc_gateway->credit_order( $wc_order, $svea_order_id );
			}
		}

		/**
		 * Sync cancelled orders to Svea
		 *
		 * @param   int     $order_id   id of the order being cancelled
		 *
		 * @return  void
		 */
		public function sync_cancelled_order( $order_id ) {
			$wc_order = wc_get_order( $order_id );

			$svea_order_id = get_post_meta( $wc_order->get_id(), '_svea_order_id', true );

			/**
			 * Determine if this order is a Svea order
			 */
			if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			$payment_method_id = $wc_order->get_payment_method();

			$wc_gateway = false;

			/**
			 * Determine if it's a Svea payment method and if which of the payment
			 * method it is
			 */
			if ( $payment_method_id === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Card::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Invoice::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Part_Pay::init();
			}

			/**
			 * If current gateway is a Svea gateway and order sync is enabled
			 * sync the order to Svea
			 */
			if ( $wc_gateway !== false && $wc_gateway->get_option( 'disable_order_sync' ) !== 'yes' ) {
				$wc_gateway->cancel_order( $wc_order, $svea_order_id );
			}
		}

		/**
		 * Sync delivered orders to Svea
		 *
		 * @param   int     $order_id   id of the order being delivered
		 * @return  void
		 */
		public function sync_delivered_order( $order_id ) {
			$wc_order = wc_get_order( $order_id );

			$svea_order_id = get_post_meta( $wc_order->get_id(), '_svea_order_id', true );

			if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			$payment_method_id = $wc_order->get_payment_method();

			$wc_gateway = false;

			if ( $payment_method_id === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Card::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Invoice::init();
			} else if ( $payment_method_id === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Part_Pay::init();
			}

			if ( $wc_gateway !== false && $wc_gateway->get_option( 'disable_order_sync' ) !== 'yes' ) {
				$wc_gateway->deliver_order( $wc_order, $svea_order_id );
			}
		}

		/**
		 * Display the svea order id whilst viewing the receipt
		 *
		 * @param   array       $total_rows     the table rows in receipt view
		 * @param   WC_Order    $order          the order currently being viewed
		 * @return  array       an array of the order rows
		 */
		public function receipt_display_svea_order_id( $total_rows, $order ) {
			$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

			if ( ! $svea_order_id )
			return $total_rows;

			$total_rows['transaction_id'] = [
				'value' => '#' . $svea_order_id,
				'label' => __( 'SveaWebPay transaction id: ', 'svea-webpay-for-woocommerce' ),
			];

			$order_total = $total_rows['order_total'];
			unset( $total_rows['order_total'] );
			$total_rows['order_total'] = $order_total;
			return $total_rows;
		}

		/**
		 * Displays the svea meta box in orders that has a
		 * svea order id
		 *
		 * @return  void
		 */
		public function add_admin_functions_meta_box() {
			global $post;

			if ( is_null( $post ) || ! in_array( $post->post_type, wc_get_order_types( 'order-meta-boxes' ), true ) || ! isset( $post->ID ) ) {
				return;
			}

			$order = wc_get_order( $post->ID );

			if ( ! $order ) {
				return;
			}

			$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

			if ( strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			$metabox_title = __( 'Svea Webpay Actions', 'svea-webpay-for-woocommerce' );
			$metabox_id = 'woocommerce-svea-webpay-admin-functions';

			$payment_method = $order->get_payment_method();

			$wc_gateway = false;

			if ( $payment_method === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Direct_Bank::init();
			} else if ( $payment_method === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Card::init();
			} else if ( $payment_method === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Invoice::init();
			} else if ( $payment_method === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
				$wc_gateway = WC_Gateway_Svea_Part_Pay::init();
			}

			if ( $wc_gateway === false
			|| $wc_gateway->get_option( 'disable_order_sync' ) !== 'yes' ) {
				return;
			}

			$metabox_output_function = false;

			if ( $payment_method === WC_Gateway_Svea_Direct_Bank::GATEWAY_ID ) {
				$metabox_output_function = 'WC_Gateway_Svea_Direct_Bank::admin_functions_meta_box';
			} else if ( $payment_method === WC_Gateway_Svea_Invoice::GATEWAY_ID ) {
				$metabox_output_function = 'WC_Gateway_Svea_Invoice::admin_functions_meta_box';
			} else if ( $payment_method === WC_Gateway_Svea_Part_Pay::GATEWAY_ID ) {
				$metabox_output_function = 'WC_Gateway_Svea_Part_Pay::admin_functions_meta_box';
			} else if ( $payment_method === WC_Gateway_Svea_Card::GATEWAY_ID ) {
				$metabox_output_function = 'WC_Gateway_Svea_Card::admin_functions_meta_box';
			}

			if ( ! $metabox_output_function ) {
				return;
			}

			add_meta_box( $metabox_id, $metabox_title, $metabox_output_function, $post->post_type, 'side', 'default' );
		}

		/**
		 * Hide Svea order meta from visitors
		 *
		 * @param array $hidden_meta
		 *
		 * @return array
		 */
		public function hide_order_item_meta( $hidden_meta ) {
			$hidden_meta[] = 'svea_order_number';
			$hidden_meta[] = 'svea_order_id';
			$hidden_meta[] = 'svea_address_selector';

			$hidden_meta[] = 'svea_iv_billing_ssn';
			$hidden_meta[] = 'svea_iv_billing_customer_type';
			$hidden_meta[] = 'svea_iv_billing_org_number';
			$hidden_meta[] = 'svea_iv_billing_initials';
			$hidden_meta[] = 'svea_iv_billing_vat_number';
			$hidden_meta[] = 'svea_iv_birth_date_year';
			$hidden_meta[] = 'svea_iv_birth_date_month';
			$hidden_meta[] = 'svea_iv_birth_date_day';

			return $hidden_meta;
		}

		/**
		 * Display the svea order id in the backend whilst viewing an order processed
		 * through svea
		 *
		 * @param   WC_Order    $order      the order currently being viewed
		 *
		 * @return  void
		 */
		public function admin_display_svea_order_id( $order ) {
			$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

			/**
			 * Only display the svea order id if this order was processed
			 * through svea
			 */
			if ( ! $svea_order_id || strlen( $svea_order_id ) <= 0 ) {
				return;
			}

			?>
			<div class="order_data_column">
				<div class="address">
					<p>
						<strong><?php esc_html_e( 'Svea Order Id', 'svea-webpay-for-woocommerce' ); ?></strong>
						#<?php echo esc_html( $svea_order_id ); ?>
					</p>
				</div>
			</div>
			<?php
		}
	}

	new Svea_WebPay_For_WooCommerce();

endif;
