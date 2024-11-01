<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Svea\WebPay\Constant\PaymentMethod;
use Svea\WebPay\Response\SveaResponse;
use Svea\WebPay\WebPayItem;
use Svea\WebPay\WebPayAdmin;

/**
 * Class to handle direct bank payments through Svea WebPay
 */
class WC_Gateway_Svea_Direct_Bank extends WC_Payment_Gateway {

	/**
	 * Id of this gateway
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'sveawebpay_direct_bank';

	/**
	 * Static instance of this class
	 *
	 * @var WC_Gateway_Svea_Direct_Bank
	 */
	private static $instance = null;

	/**
	 * Whether or not the log is enabled
	 *
	 * @var boolean
	 */
	private static $log_enabled = false;

	/**
	 * The log object
	 *
	 * @var WC_Logger
	 */
	private static $log = null;

	/**
	 * Base country
	 *
	 * @var string
	 */
	public $base_country;

	/**
	 * Merchant id
	 *
	 * @var string
	 */
	private $merchant_id;

	/**
	 * Secret word
	 *
	 * @var string
	 */
	private $secret_word;

	/**
	 * Testmode
	 *
	 * @var bool
	 */
	private $testmode;

	/**
	 * Language
	 *
	 * @var string
	 */
	public $language;

	/**
	 * Active direct bank gateway
	 *
	 * @var string
	 */
	public $active_direct_bank_gateway;

	/**
	 * Selected currency
	 *
	 * @var string
	 */
	public $selected_currency;

	/**
	 * Payment methods
	 *
	 * @var array
	 */
	public $payment_methods;

	/**
	 * Config
	 *
	 * @var WC_Svea_Config_Production
	 */
	private $config;

	/**
	 * Initialize the gateway
	 *
	 * @return WC_Gateway_Svea_Direct_Bank
	 */
	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new WC_Gateway_Svea_Direct_Bank();
		}

		return self::$instance;
	}

	/**
	 * Constructor for the class and setup of hooks and object variables
	 */
	public function __construct() {
		if ( is_null( self::$instance ) )
			self::$instance = $this;

		$this->supports = [
			'products',
		];

		$this->id = self::GATEWAY_ID;

		$this->method_title = __( 'SveaWebPay Direct Bank Payment', 'svea-webpay-for-woocommerce' );
		$this->icon = apply_filters( 'woocommerce_sveawebpay_direct_bank_icon', WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/bank-icon.png' );
		$this->has_fields = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title = __( $this->get_option( 'title' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_wc_gateway_svea_direct_bank', [ $this, 'handle_callback_request' ] );

		$this->enabled = $this->get_option( 'enabled' );

		$wc_countries = new WC_Countries();
		$this->base_country = $wc_countries->get_base_country();

		$this->merchant_id = $this->get_option( 'merchant_id' );
		$this->secret_word = $this->get_option( 'secret_word' );
		$this->testmode = $this->get_option( 'testmode' ) === 'yes';
		$this->language = $this->get_option( 'language' );
		self::$log_enabled = $this->get_option( 'debug' ) === 'yes';

		$this->active_direct_bank_gateway = $this->get_option( 'active_direct_bank_gateway' );

		$this->selected_currency = get_woocommerce_currency();

		$this->payment_methods = [
			PaymentMethod::BANKAXESS   => [
				'payment_method'    => PaymentMethod::BANKAXESS,
				'allowed_countries' => [ 'NO' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_bankaxess.gif',
				'label'             => __( 'Direct bank payments, Norway', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::NORDEA_SE   => [
				'payment_method'    => PaymentMethod::NORDEA_SE,
				'allowed_countries' => [ 'SE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_nordea.gif',
				'label'             => __( 'Direct bank payment, Nordea, Sweden', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::SEB_SE      => [
				'payment_method'    => PaymentMethod::SEB_SE,
				'allowed_countries' => [ 'SE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/DBSEBSE.png',
				'label'             => __( 'Direct bank payment, private, SEB, Sweden', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::SEBFTG_SE   => [
				'payment_method'    => PaymentMethod::SEBFTG_SE,
				'allowed_countries' => [ 'SE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/DBSEBFTGSE.png',
				'label'             => __( 'Direct bank payment, company, SEB, Sweden', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::SHB_SE      => [
				'payment_method'    => PaymentMethod::SHB_SE,
				'allowed_countries' => [ 'SE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_handelsbanken.gif',
				'label'             => __( 'Direct bank payment, Handelsbanken, Sweden', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::SWEDBANK_SE => [
				'payment_method'    => PaymentMethod::SWEDBANK_SE,
				'allowed_countries' => [ 'SE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_swedbank.gif',
				'label'             => __( 'Direct bank payment, Swedbank, Sweden', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::PAYPAL      => [
				'payment_method'    => PaymentMethod::PAYPAL,
				'allowed_countries' => [ 'SE', 'DK', 'NO', 'FI', 'NL', 'DE' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_paypal.png',
				'label'             => __( 'PayPal', 'svea-webpay-for-woocommerce' ),
			],

			PaymentMethod::SKRILL      => [
				'payment_method'    => PaymentMethod::SKRILL,
				'allowed_countries' => [ 'DK' ],
				'logo'              => WC_SVEAWEBPAY_GATEWAY_PLUGIN_URL . 'assets/img/logo_skrill.gif',
				'label'             => __( 'Card payment with Dankort, Skrill', 'svea-webpay-for-woocommerce' ),
			],
		];

		$config_class = $this->testmode ? 'WC_Svea_Config_Test' : 'WC_Svea_Config_Production';

		$this->config = new $config_class( $this->merchant_id, $this->secret_word, false, false, false );

		$this->description = __( $this->get_option( 'description' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore
	}

	/**
	 * Logging method.
	 * @param string $message
	 */
	public static function log( $message ) {
		if ( self::$log_enabled ) {
			if ( is_null( self::$log ) ) {
				// Failsafe fatal errors, logging is not critical
				if ( ! class_exists( 'WC_Logger' ) ) {
					return;
				}

				self::$log = new WC_Logger();
			}

			self::$log->add( self::GATEWAY_ID, $message );
		}
	}

	/**
	 * Display payment fields at checkout
	 *
	 * @return void
	 */
	public function payment_fields() {
		echo wp_kses_post( $this->description );

		$post_data = [];

		if ( isset( $_POST['post_data'] ) ) {
			parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $post_data );
		}

		$post_data = array_map( 'sanitize_text_field', $post_data );

		$payment_methods = $this->get_available_payment_methods();

		if ( count( $payment_methods ) <= 0 ) {
			return;
		}

		$options = [];

		foreach ( $payment_methods as $payment_method ) {
			$options[ $payment_method['payment_method'] ] = $payment_method['label'];
		}

		?>
		<div class="direct-bank-payment-method">
			<h3><?php esc_html_e( 'Payment Method', 'svea-webpay-for-woocommerce' ); ?></h3>
			<?php
				woocommerce_form_field(
					'direct_bank_payment_method',
					[
						'type'     => 'radio',
						'required' => false,
						'class'    => [ 'form-row-wide' ],
						'options'  => $options,
					],
					isset( $post_data['direct_bank_payment_method'] ) ? $post_data['direct_bank_payment_method'] : null
				);
			?>
		</div>
		<?php
	}

	/**
	 * Display admin action buttons
	 *
	 * @return void
	 */
	public static function display_admin_action_buttons() {
		?>
		<button type="button" class="button svea-credit-items"><?php esc_html_e( 'Credit via svea', 'svea-webpay-for-woocommerce' ); ?></button>
		<?php
	}

	/**
	 * Render function for the admin functions meta box
	 *
	 * @return void
	 */
	public static function admin_functions_meta_box() {
		$credit_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::CREDIT_NONCE );

		?>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_credit_order&order_id=' . get_the_ID() . '&security=' . $credit_nonce ) ); ?>">
			<?php esc_html_e( 'Credit', 'svea-webpay-for-woocommerce' ); ?>
		</a>
		<?php
	}

	/**
	 * Check whether or not this payment gateway is available
	 *
	 * @return  boolean
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! is_admin() ) {
			if ( ! $this->check_customer_country() ) {
				return false;
			} else if ( ! $this->check_customer_currency() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current country is supported and enabled
	 *
	 * @return  boolean
	 */
	public function check_customer_country() {
		if ( isset( WC()->customer ) ) {
			$customer_country = strtoupper( WC()->customer->get_billing_country() );

			if ( ! in_array( $customer_country, WC_Gateway_Svea_Helper::ALLOWED_COUNTRIES_ASYNC, true )
				|| count( $this->get_available_payment_methods( $customer_country ) ) <= 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current currency is supported
	 *
	 * @return  boolean
	 */
	public function check_customer_currency() {
		if ( isset( WC()->customer ) ) {
			$country_currency = WC_Gateway_Svea_Helper::get_country_currency( WC()->customer->get_billing_country() );

			if ( ! isset( $country_currency[0] )
				|| $country_currency !== $this->selected_currency ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the plugin gateway can be used with the specified country/currency.
	 *
	 * @return array
	 */
	public function direct_bank_is_active_and_set() {
		$wc_countries = new WC_Countries();

		if ( ! in_array( get_woocommerce_currency(), WC_Gateway_Svea_Helper::ALLOWED_CURRENCIES_ASYNC, true )
			|| ( ! in_array( $wc_countries->get_base_country(), WC_Gateway_Svea_Helper::ALLOWED_COUNTRIES_ASYNC, true ) ) ) {
			return [
				'error'    => true,
				'errormsg' => 'Invalid country code/currency',
				'svea-webpay-for-woocommerce',
			];
		} else if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
			return [
				'error'    => true,
				'errormsg' => 'Invalid woocommerce version',
				'svea-webpay-for-woocommerce',
			];
		}

		return [ 'error' => false ];
	}

	/**
	 * If the payment gateway is properly configured, generate the settings
	 *
	 * @return void
	 */
	public function admin_options() {
		?>
		<h3><?php esc_html_e( 'SveaWebPay Direct Bank Payment', 'svea-webpay-for-woocommerce' ); ?></h3>
		<p><?php esc_html_e( 'Process direct bank payments through SveaWebPay.', 'svea-webpay-for-woocommerce' ); ?></p>
		<?php $result = $this->direct_bank_is_active_and_set(); ?>
		<?php if ( ! $result['error'] ) : ?>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php else : ?>
		<div class="inline error">
			<p><?php esc_html_e( $result['errormsg'], 'svea-webpay-for-woocommerce' ); // phpcs:ignore ?></p>
		</div>
			<?php
		endif;
	}

	/**
	 * Initialize the form fields for the payment gateway
	 *
	 * @return     void
	 */
	function init_form_fields() {
		$this->form_fields = [
			'enabled'                    => [
				'title'   => __( 'Enable/disable', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SveaWebPay Direct Bank Payments', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'title'                      => [
				'title'       => __( 'Title', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Direct Bank Payment', 'svea-webpay-for-woocommerce' ),
			],
			'description'                => [
				'title'       => __( 'Description', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Pay with direct bank payments through Svea Ekonomi', 'svea-webpay-for-woocommerce' ),
			],
			'merchant_id'                => [
				'title'       => __( 'Merchant id', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your SveaWebPay merchant id', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'secret_word'                => [
				'title'       => __( 'Secret word', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your SveaWebPay secret word', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'testmode'                   => [
				'title'       => __( 'Test mode', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable test mode for SveaWebPay Direct Bank Payment', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'active_direct_bank_gateway' => [
				'title'       => __( 'Enabled direct bank transfer methods', 'svea-webpay-for-woocommerce' ),
				'type'        => 'multiselect',
				'description' => 'Choose the direct bank transfer methods that you want to enable',
				'options'     => [
					PaymentMethod::BANKAXESS   => __( 'Direct bank payments, Norway', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::NORDEA_SE   => __( 'Direct bank payment, Nordea, Sweden', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::SEB_SE      => __( 'Direct bank payment, private, SEB, Sweden', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::SEBFTG_SE   => __( 'Direct bank payment, company, SEB, Sweden', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::SHB_SE      => __( 'Direct bank payment, Handelsbanken, Sweden', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::SWEDBANK_SE => __( 'Direct bank payment, Swedbank, Sweden', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::PAYPAL      => __( 'Paypal', 'svea-webpay-for-woocommerce' ),
					PaymentMethod::SKRILL      => __( 'Card payment with Dankort, Skrill', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => '',
			],
			'debug'                      => [
				'title'       => __( 'Debug log', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
				// translators: %s is the log file path
				'description' => sprintf( __( 'Log Svea events, such as payment requests, inside <code>%s</code>', 'svea-webpay-for-woocommerce' ), wc_get_log_file_path( self::GATEWAY_ID ) ),
			],
			'disable_order_sync'         => [
				'title'       => __( 'Disable automatic order sync', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __(
					"Disable automatic syncing of orders in WooCommerce to Svea. <br />
                        If you enable this option, your refunded orders will not be refunded in Svea. <br />
                        Your delivered orders will not be delivered in Svea and your cancelled orders will not be cancelled in Svea. <br />
                        <strong>Don't touch this if you don't know what you're doing</strong>.",
					'svea-webpay-for-woocommerce'
				),
				'default'     => 'no',
			],
		];
	}

	/**
	 * Get an instance of the configuration
	 *
	 * @return WC_Svea_Config_Production|WC_Svea_Config_Test   the configuration object
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Validates posted fields
	 *
	 * @param array $fields
	 * @param object $errors
	 *
	 * @return void
	 */
	public function checkout_validation_handler( $fields, $errors ) {
		$customer_country = $fields['billing_country'];

		if ( empty( $_POST['direct_bank_payment_method'] ) ) {
			$errors->add( 'validation', __( 'Please enter direct bank payment method.', 'svea-webpay-for-woocommerce' ) );
			return;
		}

		$direct_bank_method = sanitize_text_field( wp_unslash( $_POST['direct_bank_payment_method'] ) );

		if ( ! $this->is_valid_payment_method( $direct_bank_method, $customer_country ) ) {
			$errors->add( 'validation', __( 'That payment method is not supported in your country.', 'svea-webpay-for-woocommerce' ) );
			return;
		}
	}

	/**
	 * Check whether or not the payment method is valid for the provided country
	 *
	 * @param   string      $payment_method     the payment method
	 * @param   string      $customer_country   the customer's country
	 * @return  boolean     whether or not the payment method is valid
	 */
	public function is_valid_payment_method( $payment_method, $customer_country ) {
		if ( ! isset( $this->payment_methods[ $payment_method ] ) )
			return false;

		if ( ! in_array( $customer_country, $this->payment_methods[ $payment_method ]['allowed_countries'], true ) )
			return false;

		return true;
	}

	/**
	 * Get the available payment methods, depending on the country.
	 *
	 * @param      string   $customer_country   country of the customer
	 * @return     array    an array containing available payment methods
	 */
	public function get_available_payment_methods( $customer_country = null ) {
		$payment_methods = [];

		if ( $this->active_direct_bank_gateway === false ||
			! is_array( $this->active_direct_bank_gateway ) ) {
			return [];
		}

		if ( is_null( $customer_country ) ) {
			$customer_country = strtoupper( WC()->customer->get_billing_country() );
		}

		foreach ( $this->active_direct_bank_gateway as $value ) {
			if ( ! $this->is_valid_payment_method( $value, $customer_country ) )
				continue;

			$arr = $this->payment_methods[ $value ];

			$payment_methods[ $arr['label'] ] = [
				'payment_method' => $arr['payment_method'],
				'logo'           => $arr['logo'],
				'label'          => $arr['label'],
			];
		}

		return $payment_methods;
	}

	/**
	 * Accepted request inteprits the returned object and response code from Sveawebpay.
	 *
	 * @return void
	 */
	public function handle_callback_request() {
		$request = $_REQUEST;

		self::log( sprintf( 'Callback request for payment initiated by client %s', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) );

		/**
		 * Fetch response string directly from query string
		 * if parameter max length prevents it from being read.
		 */
		if ( ( ! isset( $request['response'] ) || strlen( $request['response'] ) <= 0 )
			&& isset( $_SERVER['QUERY_STRING'] )
			&& strlen( sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) > 0 ) {

			$params_array = explode( '&', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) );

			$params = [];

			foreach ( $params_array as $pair ) {
				list( $key, $value ) = explode( '=', $pair );
				$params[ urldecode( $key ) ] = urldecode( $value );
			}

			if ( isset( $params['response'] ) ) {
				$request['response'] = $params['response'];
			}
		}

		/**
		 * If the response-parameter is not set, do not produce a PHP-error
		 */
		if ( ! isset( $request['response'] ) ) {
			status_header( 400 );
			self::log( 'Response parameter in request is not set' );
			exit;
		}

		$config = $this->get_config();

		/**
		 * Wrap the response in Sveas response-parser
		 */
		$svea_response = new SveaResponse( $request, null, $config );
		$response = $svea_response->getResponse();

		$payment_method = $response->paymentMethod;

		if ( is_null( $payment_method ) ) {
			status_header( 400 );
			self::log( 'Payment method is null' );
			exit;
		}

		if ( ! isset( $request['order-id'] ) || ! isset( $request['key'] ) ) {
			status_header( 400 );
			self::log( 'Order ID-parameter in request is not set' );
			exit;
		}

		$order_id = intval( $request['order-id'] );
		$order_key = sanitize_text_field( $request['key'] );

		$wc_order = wc_get_order( $order_id );

		if ( ! $wc_order ) {
			self::log( 'No order was found by ID' );
			exit;
		}

		if ( ! $wc_order->key_is_valid( $order_key ) ) {
			self::log( 'Order key was not valid for order' );
			exit;
		}

		/**
		 * Check if the callback was initiated by Svea Callback Service
		 * and save in variable for future use.
		 */
		$is_svea_callback = isset( $request['svea-callback'] ) && $request['svea-callback'];

		/**
		 * If the order is already paid, redirect to the order received page
		 */
		if ( $wc_order->is_paid() ) {

			/**
			 * Check if request was initiated by Svea Callback Service
			 */
			if ( $is_svea_callback ) {
				self::log( sprintf( 'Order %s was already paid, request was initiated by Svea Callback Service, exiting.', $order_id ) );
				status_header( 200 );
			} else {
				self::log( sprintf( 'Order %s was already paid, redirecting to order received page', $order_id ) );

				wp_safe_redirect( $wc_order->get_checkout_order_received_url() );
			}

			exit;
		}

		$cancel_url = esc_url_raw( wc_get_checkout_url() );

		$redirect_url = $cancel_url;
		$complete_url = $wc_order->get_checkout_order_received_url();

		if ( isset( $response->accepted ) && $response->accepted ) {

			// translators: %s is the client IP address
			$wc_order->add_order_note( sprintf( __( 'Order was completed by client on IP: %s', 'svea-webpay-for-woocommerce' ), isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) );
			$wc_order->payment_complete( $response->transactionId );

			// Remove cart
			WC()->cart->empty_cart();

			$redirect_url = $complete_url;
			update_post_meta( $order_id, '_svea_order_id', $response->transactionId );
			self::log( 'Payment successful' );

			/**
			 * If Svea initiated the callback, prevent unnecessary requests
			 * by returning 200 status code instead of redirecting.
			 */
			if ( $is_svea_callback ) {
				self::log( 'Request was initiated by Svea Callback Service, exiting instead of redirect.' );
				status_header( 200 );
				exit;
			}
		} else {
			wc_add_notice( WC_Gateway_Svea_Helper::get_svea_error_message( (int) $response->resultcode ), 'error' );
			self::log( 'Payment failed, response: ' . $response->errormessage );

			$redirect_url = $cancel_url;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process refunds
	 *
	 * @param string $order_id id of the order being refunded
	 * @param float $amount amount being refunded
	 * @param string $reason reason of the refund
	 *
	 * @return boolean
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = new WC_Order( $order_id );

		$svea_order_id = get_post_meta( $order->get_id(), '_svea_order_id', true );

		if ( strlen( (string) $svea_order_id ) <= 0 ) {
			return false;
		}

		$credit_name = __( 'Refund', 'svea-webpay-for-woocommerce' );

		if ( strlen( $reason ) > 0 )
			$credit_name .= ': ' . $reason;

		$response = WebPayAdmin::creditOrderRows( $this->config )
			->setOrderId( $svea_order_id )
			->setCountryCode( $order->get_billing_country() )
			->addCreditOrderRow(
				WebPayItem::orderRow()
					->setAmountExVat( (float) $amount )
					->setVatPercent( 0 )
					->setDiscountPercent( 0 )
					->setName( $credit_name )
					->setQuantity( 1 )
			)
			->creditDirectBankOrderRows()
			->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return new WP_Error( 'error', $response->errormessage );
		}

		return true;
	}

	/**
	 * If the payment was sucessful, redirect to the payment page
	 *
	 * @param string $order_id the order id being processed
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		self::log( 'Processing direct bank payment' );

		if ( ! isset( $_POST['direct_bank_payment_method'] ) ) {
			wc_add_notice( __( 'Direct bank payment method must be selected.', 'svea-webpay-for-woocommerce' ), 'error' );
			self::log( "Direct bank payment method wasn't selected" );
			return [
				'result' => 'failure',
			];
		}

		$wc_order = new WC_Order( $order_id );

		$order_number = get_post_meta( $order_id, '_svea_order_number', true );

		if ( $order_number === false || empty( $order_number ) ) {
			$order_number = 1;
			update_post_meta( $order_id, '_svea_order_number', $order_number );
		} else {
			$order_number = (int) $order_number;
			$order_number += 1;
			update_post_meta( $order_id, '_svea_order_number', $order_number );
		}

		$config = $this->get_config();

		$customer_first_name = $wc_order->get_billing_first_name();
		$customer_last_name = $wc_order->get_billing_last_name();
		$customer_address_1 = $wc_order->get_billing_address_1();
		$customer_address_2 = $wc_order->get_billing_address_2();
		$customer_zip_code = $wc_order->get_billing_postcode();
		$customer_city = $wc_order->get_billing_city();
		$customer_country = $wc_order->get_billing_country();
		$customer_email = $wc_order->get_billing_email();
		$customer_phone = $wc_order->get_billing_phone();

		$svea_order = WC_Gateway_Svea_Helper::create_svea_order( $wc_order, $config );

		$customer_information = WebPayItem::individualCustomer()
			->setName( $customer_first_name, $customer_last_name )
			->setStreetAddress( $customer_address_1 )
			->setZipCode( $customer_zip_code )
			->setLocality( $customer_city )
			->setIpAddress( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' )
			->setEmail( $customer_email )
			->setPhoneNumber( $customer_phone )
			->setCoAddress( $customer_address_2 );

		$wc_return_url = esc_url_raw(
			add_query_arg(
				[
					'wc-api'   => 'wc_gateway_svea_direct_bank',
					'order-id' => $wc_order->get_id(),
					'key'      => $wc_order->get_order_key(),
				],
				$this->get_return_url( $wc_order )
			)
		);

		$wc_callback_url = esc_url_raw(
			add_query_arg(
				[
					'wc-api'   => 'wc_gateway_svea_direct_bank',
					'order-id' => $wc_order->get_id(),
					'key'      => $wc_order->get_order_key(),
				],
				$this->get_return_url( $wc_order )
			)
		);

		/**
		 * If the payment was issued in the checkout page,
		 * send the user back if they cancel the order in Svea.
		 */
		if ( is_checkout() ) {
			$wc_cancel_url = esc_url_raw( wc_get_checkout_url() );
		} else {
			$wc_cancel_url = esc_url_raw( $wc_order->get_cancel_order_url() );
		}

		try {
			$response = $svea_order->addCustomerDetails( $customer_information )
				->setClientOrderNumber( apply_filters( 'woocommerce_sveawebpay_direct_bank_client_order_number', $wc_order->get_order_number() ) . '_' . $order_number )
				->setCurrency( $wc_order->get_currency() )
				->setCountryCode( $customer_country )
				->usePaymentMethod( sanitize_text_field( wp_unslash( $_POST['direct_bank_payment_method'] ) ) )
				->setReturnUrl( $wc_return_url )
				->setCallbackUrl( $wc_callback_url )
				->setCancelUrl( $wc_cancel_url )
				->setCardPageLanguage( $this->language )
				->getPaymentUrl();
		} catch ( Exception $e ) {
			self::log( 'Error: ' . $e->getMessage() );
			wc_add_notice( __( 'An error occurred whilst connecting to Svea. Please contact the store owner and display this message.', 'svea-webpay-for-woocommerce' ), 'error' );
			return [
				'result' => 'failure',
			];
		}

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			if ( isset( $response->errormessage ) ) {
				wc_add_notice( $response->errormessage, 'error' );
				self::log( 'Error: ' . $response->errormessage );
			} else {
				wc_add_notice( __( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' ), 'error' );
				self::log( 'Response error message was empty' );
			}

			return [
				'result' => 'failure',
			];
		}

		$payment_url = $this->testmode ? $response->testurl : $response->url;

		if ( ! is_string( $payment_url ) || ! strlen( $payment_url ) > 0 ) {
			wc_add_notice( __( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' ), 'error' );
			self::log( 'Payment URL was empty' );
			return [
				'result' => 'failure',
			];
		}

		self::log( 'User redirected to payment URL' );

		return [
			'result'   => 'success',
			'redirect' => $payment_url,
		];
	}

	/**
	 * Credits the order in svea
	 *
	 * @param   WC_Order    $order  the order being credited
	 * @param   string      $svea_order_id  id of the svea order
	 * @param   array       $order_item_ids     an optional array of order item ids
	 * @return  array       an array containing result and message
	 */
	public function credit_order( $order, $svea_order_id, $order_item_ids = [] ) {
		$config = $this->get_config();

		$credit_order_request = WebPayAdmin::creditOrderRows( $config )
				->setCountryCode( $order->get_billing_country() )
				->setOrderId( $svea_order_id );

		$order_tax_percentage = 0;

		if ( $order->get_total_tax() > 0 ) {
			$order_tax_percentage = ( $order->get_total_tax() / ( $order->get_total() - $order->get_total_tax() ) ) * 100;
		}

		$credit_order_request->addCreditOrderRow(
			WebPayItem::orderRow()
			->setAmountExVat( $order->get_total() - $order->get_total_tax() )
				->setVatPercent( $order_tax_percentage )
				->setQuantity( 1 )
				->setDescription(
					// translators: %s is the Svea order id
					sprintf( __( 'Credited order #%s' ), $svea_order_id )
				)
		);

		$response = $credit_order_request->creditDirectBankOrderRows()
			->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return [
				'success' => false,
				'message' => $response->errormessage,
			];
		}

		foreach ( array_keys( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) ) as $order_item_id ) {
			if ( wc_get_order_item_meta( $order_item_id, 'svea_credited' ) )
				continue;
			wc_update_order_item_meta( $order_item_id, 'svea_credited', date( 'Y-m-d H:i:s' ) );
		}

		/**
		 * The request was successful
		 */
		$order->update_status( 'refunded' );

		$order->add_order_note(
			__( 'All items have been credited in Svea.', 'svea-webpay-for-woocommerce' )
		);

		return [
			'success' => true,
			'message' => __( 'All items have been credited in Svea.', 'svea-webpay-for-woocommerce' ),
		];
	}
}
