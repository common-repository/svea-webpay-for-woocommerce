<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Svea\WebPay\WebPay;
use Svea\WebPay\WebPayItem;
use Svea\WebPay\WebPayAdmin;
use Svea\WebPay\Constant\DistributionType;

/**
 * Class to handle part payments through Svea WebPay
 */
class WC_Gateway_Svea_Part_Pay extends WC_Payment_Gateway {

	/**
	 * Format of the transition for part payment campaigns
	 *
	 * @var string
	 */
	const PART_PAYMENT_TRANSIENT_FORMAT = 'sveawebpay-part-pay-campaigns-%s';

	/**
	 * Id of this gateway
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'sveawebpay_part_pay';

	/**
	 * Static instance of this class
	 *
	 * @var WC_Gateway_Svea_Part_Pay
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
	 * Svea part pay currencies
	 *
	 * @var array
	 */
	public $svea_part_pay_currencies;

	/**
	 * Bbase country
	 *
	 * @var string
	 */
	public $base_country;

	/**
	 * Enabled countries
	 *
	 * @var string
	 */
	public $enabled_countries;

	/**
	 * Selected currency
	 *
	 * @var string
	 */
	public $selected_currency;

	/**
	 * Same shipping as billing
	 *
	 * @var bool
	 */
	public $same_shipping_as_billing;

	/**
	 * Testmode
	 *
	 * @var bool
	 */
	private $testmode;

	/**
	 * Username
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Password
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Client nr
	 *
	 * @var string
	 */
	private $client_nr;

	/**
	 * Display product widget
	 *
	 * @var string
	 */
	public $display_product_widget;

	/**
	 * Configuration file
	 *
	 * @var WC_Svea_Config_Production
	 */
	private $config;

	/**
	 * If we should use strong auth or not
	 *
	 * @var bool
	 */
	public $strong_auth = false;

	/**
	 * Initialize the gateway
	 *
	 * @return WC_Gateway_Svea_Part_Pay
	 */
	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new WC_Gateway_Svea_Part_Pay();
		}

		return self::$instance;
	}

	/**
	 * Constructor for the class and setup of hooks and object variables
	 */
	public function __construct() {
		if ( is_null( self::$instance ) ) {
			self::$instance = $this;
		}

		$this->id = self::GATEWAY_ID;

		$this->method_title = __( 'SveaWebPay Part Payment', 'svea-webpay-for-woocommerce' );
		$this->icon = apply_filters( 'woocommerce_sveawebpay_part_pay_icon', 'https://cdn.svea.com/sveaekonomi/rgb_ekonomi_large.png' );
		$this->has_fields = true;

		$this->svea_part_pay_currencies = [
			'DKK' => 'DKK', // Danish Kroner
			'EUR' => 'EUR', // Euro
			'NOK' => 'NOK', // Norwegian Kroner
			'SEK' => 'SEK',  // Swedish Kronor
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->title = __( $this->get_option( 'title' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'clear_part_payment_plans_cache' ] );
		add_action( 'woocommerce_api_wc_gateway_svea_part_pay_strong_auth_confirmed', [ $this, 'handle_callback_request_confirmed' ] );
		add_action( 'woocommerce_api_wc_gateway_svea_part_pay_strong_auth_rejected', [ $this, 'handle_callback_request_rejected' ] );

		if ( ! isset( WC()->customer ) ) {
			return;
		}

		$customer_country = strtolower( WC()->customer->get_billing_country() );

		$this->enabled = $this->get_option( 'enabled' );
		$this->strong_auth = $this->uses_strong_auth( $customer_country );
		// Set logo by customer country
		$this->icon = $this->get_svea_part_pay_logo_by_country( $customer_country );

		$wc_countries = new WC_Countries();

		$this->base_country = $wc_countries->get_base_country();

		$this->enabled_countries = is_array( $this->get_option( 'enabled_countries' ) ) ? $this->get_option( 'enabled_countries' ) : [];

		$this->selected_currency = get_woocommerce_currency();

		$this->same_shipping_as_billing = $this->get_option( 'same_shipping_as_billing' ) === 'yes';

		$this->testmode = $this->get_option( 'testmode_' . $customer_country ) === 'yes';
		$this->username = $this->get_option( 'username_' . $customer_country );
		$this->password = $this->get_option( 'password_' . $customer_country );
		$this->client_nr = $this->get_option( 'client_nr_' . $customer_country );
		$this->display_product_widget = $this->get_option( 'display_product_widget' ) === 'yes';
		self::$log_enabled = $this->get_option( 'debug' ) === 'yes';

		$config_class = $this->testmode ? 'WC_Svea_Config_Test' : 'WC_Svea_Config_Production';

		$this->config = new $config_class( false, false, $this->client_nr, $this->password, $this->username );

		$this->description = __( $this->get_option( 'description' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore
	}

	/**
	 * Part payment widget used on the product page if activated
	 *
	 * @return void
	 */
	public function product_part_payment_widget() {
		if ( ! $this->display_product_widget ) {
			return;
		}

		global $product;

		$product_types = apply_filters( 'woocommerce_sveawebpay_part_pay_widget_product_types', [ 'simple', 'variable' ] );

		if ( ! $product->is_type( $product_types ) ) {
			return;
		}

		$price = $product->get_price();

		if ( ! empty( WC()->customer->get_billing_country() ) ) {
			$customer_country = strtoupper( WC()->customer->get_billing_country() );
		} else {
			$wc_countries = new WC_Countries();

			$customer_country = $wc_countries->get_base_country();
		}

		$country_currency = WC_Gateway_Svea_Helper::get_country_currency( $customer_country );

		if ( ! isset( $country_currency[0] )
			|| $country_currency !== $this->selected_currency ) {
			return;
		}

		$campaigns = $this->get_payment_plans( $customer_country );

		if ( empty( $campaigns ) ) {
			return;
		}

		// Filter out amortizationfree campaigns
		$campaigns = array_values(
			array_filter(
				$campaigns,
				function( $campaign ) {
					return isset( $campaign->paymentPlanType ) && strtoupper( $campaign->paymentPlanType ) !== 'INTERESTANDAMORTIZATIONFREE'; // phpcs:ignore
				}
			)
		);

		$payment_plan_prices = Svea\WebPay\Helper\PaymentPlanHelper\PaymentPlanCalculator::getMonthlyAmountToPayFromCampaigns( $price, $campaigns );

		$lowest_price_per_month = false;

		foreach ( $payment_plan_prices as $price_per_month ) {
			if ( ! isset( $price_per_month['monthlyAmountToPay'] ) ) {
				continue;
			}

			if ( $lowest_price_per_month === false || $price_per_month['monthlyAmountToPay'] < $lowest_price_per_month ) {
				$lowest_price_per_month = $price_per_month['monthlyAmountToPay'];
			}
		}

		if ( $lowest_price_per_month === false || $lowest_price_per_month <= 0 ) {
			return;
		}

		$svea_icon = $this->get_svea_part_pay_logo_by_country( $customer_country );

		// translators: %s is the amount to pay per month ?>
		<p class="svea-part-payment-widget"><img src="<?php echo esc_url( $svea_icon ); ?>" /><?php printf( esc_html__( 'Part pay from %s/month', 'svea-webpay-for-woocommerce' ), esc_html( wp_strip_all_tags( wc_price( round( $lowest_price_per_month ) ) ) ) ); ?></p>
		<?php
	}


	/**
	 * Check if we should use strong auth in this payment
	 *
	 * @param string $country
	 * @return bool
	 */
	public function uses_strong_auth( string $country ) {
		return strtoupper( $country ) === 'SE' && $this->get_option( 'strong_auth_se' ) === 'yes';
	}


	/**
	 * Get Svea Part Pay logo depending on country
	 *
	 * @param string $country
	 *
	 * @return string URL of the part pay logo
	 */
	public function get_svea_part_pay_logo_by_country( $country = '' ) {
		$default_logo = apply_filters( 'woocommerce_sveawebpay_part_pay_icon', 'https://cdn.svea.com/webpay/Svea_Primary_RGB_medium.png' );

		$country = strtoupper( $country );

		$logos = [
			'SE' => 'https://cdn.svea.com/webpay/Svea_Primary_RGB_medium.png',
			'NO' => 'https://cdn.svea.com/webpay/Svea_Primary_RGB_medium.png',
			'FI' => 'https://cdn.svea.com/webpay/Svea_Primary_RGB_medium.png',
			'DE' => 'https://cdn.svea.com/webpay/Svea_Primary_RGB_medium.png',
		];

		$logo = $default_logo;

		if ( isset( $logos[ $country ] ) ) {
			$logo = $logos[ $country ];
		}

		return apply_filters( 'woocommerce_sveawebpay_part_pay_icon', $logo, $country );
	}

	/**
	 * Logging method.
	 *
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
	 * Get payment plans by country
	 *
	 * @param string $country The country to get campaigns from
	 *
	 * @return array List of payment plan campaigns
	 */
	public function get_payment_plans( $country ) {
		$country = strtoupper( $country );

		/**
		 * Get campaigns from cache to save bandwidth
		 * and loading time
		 */
		$campaigns = get_transient( sprintf( self::PART_PAYMENT_TRANSIENT_FORMAT, $country ) );

		if ( $campaigns === false ) {
			self::log( 'No Payment Plans in cache, fetching Payment Plans from Svea.' );

			try {
				$campaigns_request = WebPay::getPaymentPlanParams( $this->get_config() );
				$campaigns_request->setCountryCode( $country );

				$campaigns_response = $campaigns_request->doRequest();
				$campaigns_response->country = $country;

				if ( isset( $campaigns_response->campaignCodes ) ) { // phpcs:ignore
					$campaigns = $campaigns_response->campaignCodes; // phpcs:ignore
				} else {
					$campaigns = [];
				}

				if ( $campaigns_response->accepted ) {
					/**
					 * Cache the campaigns from the response for 1 hour
					 */
					set_transient( sprintf( self::PART_PAYMENT_TRANSIENT_FORMAT, $country ), $campaigns, 60 * 60 );
					self::log( 'Successfully fetched payment plans.' );
				} else if ( isset( $campaigns_response->errormessage[0] ) ) {
					self::log( 'Error when fetching payment plans: ' . $campaigns_response->errormessage );
					$campaigns = [];
				}
			} catch ( Exception $e ) {
				self::log( 'Received error: ' . $e->getMessage() );

				$campaigns = [];
			}
		}

		return $campaigns;
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

		$country = strtoupper( WC()->customer->get_billing_country() );

		$part_pay_checkout_template = locate_template( 'woocommerce-gateway-svea-webpay/part-pay/checkout.php' );

		if ( $part_pay_checkout_template === '' ) {
			$part_pay_checkout_template = WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/partials/part-pay/checkout.php';
		}

		include( $part_pay_checkout_template );

		$campaigns = $this->get_payment_plans( $country );

		$total = WC()->cart->total;

		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			if ( isset( $_GET['key'] ) ) {
				$wc_order = new WC_Order( wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) );

				$total = $wc_order->get_total();
			}
		}

		if ( count( $campaigns ) > 0 ) :
			$options = [];

			uasort(
				$campaigns,
				function( $ca, $cb ) {
					if ( $ca->paymentPlanType === 'InterestAndAmortizationFree' && $cb->paymentPlanType !== 'InterestAndAmortizationFree' ) {
						return -1;
					} else if ( $ca->paymentPlanType !== 'InterestAndAmortizationFree' && $cb->paymentPlanType === 'InterestAndAmortizationFree' ) {
						return 1;
					}

					if ( $ca->contractLengthInMonths === $cb->contractLengthInMonths ) {
						return 0;
					}

					return ( $ca->contractLengthInMonths < $cb->contractLengthInMonths ) ? -1 : 1;
				}
			);

			$priced_campaigns = Svea\WebPay\Helper\PaymentPlanHelper\PaymentPlanCalculator::getMonthlyAmountToPayFromCampaigns( $total, $campaigns );

			foreach ( $priced_campaigns as $campaign ) {
				if ( $campaign['paymentPlanType'] === 'InterestAndAmortizationFree' ) {
					$payment_date = strtotime( '+ ' . $campaign['contractLengthInMonths'] . ' months' );

					$campaign_description = sprintf(
						// translators: %1$s is the amount to pay, %2$s is the amount of months
						__( 'Buy now, pay %1$s in %2$s', 'svea-webpay-for-woocommerce' ),
						wp_strip_all_tags( wc_price( $campaign['monthlyAmountToPay'] ) ),
						date_i18n( 'F Y', $payment_date )
					);

					if ( isset( $campaign['initialFee'] ) && $campaign['initialFee'] > 0 ) {
						$campaign_description .= sprintf(
							' + %s %s',
							wp_strip_all_tags( wc_price( $campaign['initialFee'] ) ),
							__( 'initial fee', 'svea-webpay-for-woocommerce' )
						);
					}
				} else {
					$campaign_description = sprintf(
						'%s. %s / %s',
						$campaign['description'],
						wp_strip_all_tags( wc_price( $campaign['monthlyAmountToPay'] ) ),
						__( 'month', 'svea-webpay-for-woocommerce' )
					);

					if ( $campaign['initialFee'] > 0 ) {
						$campaign_description .= sprintf(
							' + %s %s',
							wp_strip_all_tags( wc_price( $campaign['initialFee'] ) ),
							__( 'initial fee', 'svea-webpay-for-woocommerce' )
						);
					}
				}

				$options[ $campaign['campaignCode'] ] = $campaign_description;

			}

			if ( count( $options ) > 0 ) :
				?>
			<div class="part-payment-plans">
				<h3><?php esc_html_e( 'Part Payment Plans', 'svea-webpay-for-woocommerce' ); ?></h3>
				<?php
					woocommerce_form_field(
						'part_payment_plan',
						[
							'type'     => 'radio',
							'required' => false,
							'class'    => [ 'form-row-wide' ],
							'options'  => $options,
						],
						isset( $post_data['part_payment_plan'] ) ? $post_data['part_payment_plan'] : false
					);
				?>
			</div>
			<?php else : ?>
			<div class="part-payment-plans">
				<p><?php esc_html_e( 'There are no payment plans available for your order total.', 'svea-webpay-for-woocommerce' ); ?></p>
			</div>
				<?php
			endif;

		endif;
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
		$deliver_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::DELIVER_NONCE );
		$cancel_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::CANCEL_NONCE );

		?>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_deliver_order&order_id=' . get_the_ID() . '&security=' . $deliver_nonce ) ); ?>">
			<?php esc_html_e( 'Deliver order', 'svea-webpay-for-woocommerce' ); ?>
		</a><br>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_cancel_order&order_id=' . get_the_ID() . '&security=' . $cancel_nonce ) ); ?>">
			<?php esc_html_e( 'Cancel order', 'svea-webpay-for-woocommerce' ); ?>
		</a>
		<?php
	}

	/**
	 * Check whether or not this payment gateway is available
	 *
	 * @return boolean
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
			} else if ( ! $this->check_payment_plans() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if there are any available payment plans for the customer
	 *
	 * @return boolean
	 */
	public function check_payment_plans() {
		if ( isset( WC()->customer ) ) {
			$customer_country = WC()->customer->get_billing_country();

			$total = WC()->cart->subtotal + WC()->cart->shipping_total + WC()->cart->shipping_tax_total - ( WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total() );

			if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
				if ( isset( $_GET['key'] ) ) {
					$wc_order = wc_get_order( wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) );

					$total = $wc_order->get_total();
				}
			}

			$campaigns = $this->get_payment_plans( $customer_country );

			foreach ( $campaigns as $campaign ) {
				if ( floatval( $campaign->fromAmount ) <= $total
					&& floatval( $campaign->toAmount ) >= $total ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if the current currency is the same as the currency for the customer
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
	 * Check if the current country is enabled
	 *
	 * @return  boolean
	 */
	public function check_customer_country() {
		if ( isset( WC()->customer ) ) {
			$customer_country = strtoupper( WC()->customer->get_billing_country() );
			$this->enabled_countries = is_array( $this->get_option( 'enabled_countries' ) ) ? $this->get_option( 'enabled_countries' ) : [];

			if ( ! in_array( $customer_country, $this->enabled_countries, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current customer type is valid for this
	 * payment method
	 *
	 * @return  boolean
	 */
	public function check_customer_type() {
		if ( ! isset( $_POST['post_data'] ) || ! WC_SveaWebPay_Gateway_Shortcodes::is_using_get_address_shortcode() ) {
			return true;
		}

		$post_data = [];

		parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $post_data );

		$post_data = array_map( 'sanitize_text_field', $post_data );

		if ( ! isset( $post_data['iv_billing_customer_type'] ) ) {
			return true;
		}

		if ( $post_data['iv_billing_customer_type'] !== 'company' ) {
			return true;
		}

		return false;
	}

	/**
	 * Clears transients containing part payment plans
	 *
	 * @return  void
	 */
	public function clear_part_payment_plans_cache() {
		/**
		 * List all available countries for Svea and clear cache
		 * for all of them
		 */
		$available_countries = [ 'SE', 'DK', 'NO', 'FI', 'DE', 'NL' ];

		foreach ( $available_countries as $country ) {
			/**
			 * Delete the transient to clear out the cache
			 */
			delete_transient( sprintf( self::PART_PAYMENT_TRANSIENT_FORMAT, $country ) );
		}
	}

	/**
	 * Initialize form fields for this payment gateway
	 *
	 * @return  void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'                  => [
				'title'   => __( 'Enable/disable', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SveaWebPay Part Payments', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'title'                    => [
				'title'       => __( 'Title', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Part payment', 'svea-webpay-for-woocommerce' ),
			],
			'description'              => [
				'title'       => __( 'Description', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Pay with part payments through Svea Ekonomi', 'svea-webpay-for-woocommerce' ),
			],
			'enabled_countries'        => [
				'title'       => __( 'Enabled countries', 'svea-webpay-for-woocommerce' ),
				'type'        => 'multiselect',
				'description' => __( 'Choose the countries you want SveaWebPay Part Payment to be enabled in', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					'DK' => __( 'Denmark', 'svea-webpay-for-woocommerce' ),
					'DE' => __( 'Germany', 'svea-webpay-for-woocommerce' ),
					'FI' => __( 'Finland', 'svea-webpay-for-woocommerce' ),
					'NL' => __( 'Netherlands', 'svea-webpay-for-woocommerce' ),
					'NO' => __( 'Norway', 'svea-webpay-for-woocommerce' ),
					'SE' => __( 'Sweden', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => '',
			],
			'display_product_widget'   => [
				'title'       => __( 'Display product part payment widget', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Display a widget on the product page which suggests a part payment plan for the customer to use to buy the product.', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'product_widget_position'  => [
				'title'       => __( 'Product part payment widget position', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'The position of the part payment widget on the product page. Is only displayed if the widget is activated.', 'svea-webpay-for-woocommerce' ),
				'default'     => 15,
				'options'     => [
					'15' => __( 'Between price and excerpt', 'svea-webpay-for-woocommerce' ),
					'25' => __( 'Between excerpt and add to cart', 'svea-webpay-for-woocommerce' ),
					'35' => __( 'Between add to cart and product meta', 'svea-webpay-for-woocommerce' ),
				],
			],
			'same_shipping_as_billing' => [
				'title'       => __( 'Same shipping address as billing address', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If checked, billing address will override the shipping address', 'svea-webpay-for-woocommerce' ),
				'default'     => 'yes',
			],
			//Denmark
			'testmode_dk'              => [
				'title'   => __( 'Test mode Denmark', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Denmark', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_dk'              => [
				'title'       => __( 'Username Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_dk'              => [
				'title'       => __( 'Password Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_dk'             => [
				'title'       => __( 'Client number Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Germany
			'testmode_de'              => [
				'title'   => __( 'Test mode Germany', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Germany', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_de'              => [
				'title'       => __( 'Username Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_de'              => [
				'title'       => __( 'Password Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_de'             => [
				'title'       => __( 'Client number Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Finland
			'testmode_fi'              => [
				'title'   => __( 'Test mode Finland', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Finland', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_fi'              => [
				'title'       => __( 'Username Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_fi'              => [
				'title'       => __( 'Password Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_fi'             => [
				'title'       => __( 'Client number Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Netherlands
			'testmode_nl'              => [
				'title'   => __( 'Test mode Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_nl'              => [
				'title'       => __( 'Username Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_nl'              => [
				'title'       => __( 'Password Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_nl'             => [
				'title'       => __( 'Client number Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Norway
			'testmode_no'              => [
				'title'   => __( 'Test mode Norway', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Norway', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_no'              => [
				'title'       => __( 'Username Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_no'              => [
				'title'       => __( 'Password Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_no'             => [
				'title'       => __( 'Client number Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Sweden
			'testmode_se'              => [
				'title'   => __( 'Test mode Sweden', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable/disable test mode in Sweden', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'username_se'              => [
				'title'       => __( 'Username Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for part payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_se'              => [
				'title'       => __( 'Password Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for part payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_se'             => [
				'title'       => __( 'Client number Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for part payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'strong_auth_se'  => [
				'title'       => __( 'Strong authentication', 'svea-webpay-for-woocommerce' ),
				'label'       => __( 'Enable strong authentication for part pay orders in Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __(
					'For this to work, the setting must also be toggled on in your Svea account.',
					'svea-webpay-for-woocommerce'
				) . ' <br />',
				'default'     => '',
			],
			'debug'                    => [
				'title'       => __( 'Debug log', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
				// translators: %s is the log file path
				'description' => sprintf( __( 'Log Svea events, such as payment requests, inside <code>%s</code>', 'svea-webpay-for-woocommerce' ), wc_get_log_file_path( self::GATEWAY_ID ) ),
			],
			'disable_order_sync'       => [
				'title'       => __( 'Disable automatic order sync', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __(
					'Disable automatic syncing of orders in WooCommerce to Svea. <br />
					If you enable this option, your refunded orders will not be refunded in Svea. <br />
					Your delivered orders will not be delivered in Svea and your cancelled orders will not be cancelled in Svea. <br />
					<strong>Don\'t touch this if you don\'t know what you\'re doing</strong>.',
					'svea-webpay-for-woocommerce'
				),
				'default'     => 'no',
			],
		];
	}

	/**
	 * See if we can ship to a different address
	 *
	 * @return  boolean
	 */
	public function can_ship_to_different_address() {
		if ( ! isset( $this->same_shipping_as_billing ) ) {
			return false;
		}

		return $this->same_shipping_as_billing === false;
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
		$customer_country = strtoupper( $fields['billing_country'] );

		if ( ! isset( $_POST['part_payment_plan'] ) ) {
			$errors->add( 'validation', __( '<strong>Part Payment Plan</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
			return;
		}

		switch ( strtoupper( $customer_country ) ) {
			case 'SE':
			case 'DK':
				$request = WebPay::getAddresses( $this->get_config( $customer_country ) );

				if ( empty( $_POST['pp_billing_ssn'] ) ) {
					$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				$response = $request->setCountryCode( $customer_country )
					->setCustomerIdentifier( sanitize_text_field( wp_unslash( $_POST['pp_billing_ssn'] ) ) )
					->getIndividualAddresses()
					->doRequest();

				$result_code = $response->resultcode;

				if ( $result_code === 'Accepted' ) {
					$customer_identity = $response->customerIdentity[0];

					// Update the order with the correct data after creation
					$update_order_data = function ( $wc_order ) use ( $customer_identity ) {
						if ( ! empty( $customer_identity->firstName ) ) {
							$wc_order->set_billing_first_name( $customer_identity->firstName );
						}

						if ( ! empty( $customer_identity->lastName ) ) {
							$wc_order->set_billing_last_name( $customer_identity->lastName );
						}

						$wc_order->set_billing_address_1( $customer_identity->street );
						$wc_order->set_billing_address_2( $customer_identity->coAddress );
						$wc_order->set_billing_postcode( $customer_identity->zipCode );
						$wc_order->set_billing_city( $customer_identity->locality );
					};

					add_action( 'woocommerce_checkout_create_order', $update_order_data, 10, 1 );
				} else if ( $result_code === 'Error' || $result_code === 'NoSuchEntity' ) {
					$errors->add( 'validation', __( 'The <strong>Personal number</strong> is not valid.', 'svea-webpay-for-woocommerce' ) );
					return;
				} else {
					if ( isset( $_POST['pp_billing_customer_type'] ) && $_POST['pp_billing_customer_type'] === 'company' ) {
						$errors->add( 'validation', __( 'An unknown error occurred while trying to lookup your Organisational number.', 'svea-webpay-for-woocommerce' ) );
					} else if ( isset( $_POST['pp_billing_customer_type'] ) && $_POST['pp_billing_customer_type'] === 'individual' ) {
						$errors->add( 'validation', __( 'An unknown error occurred while trying to lookup your Personal number.', 'svea-webpay-for-woocommerce' ) );
					} else {
						$errors->add( 'validation', __( 'An unknown error occurred.', 'svea-webpay-for-woocommerce' ) );
					}

					return;
				}
				break;
			case 'NO':
				if ( ! isset( $_POST['pp_billing_ssn'] ) || $_POST['pp_billing_ssn'] === '' ) {
					$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				} else if ( ! is_numeric( $_POST['pp_billing_ssn'] ) ) {
					$errors->add( 'validation', __( 'A <strong>Personal number</strong> can only contain digits.', 'svea-webpay-for-woocommerce' ) );
					return;
				} else if ( strlen( sanitize_text_field( wp_unslash( $_POST['pp_billing_ssn'] ) ) ) !== 11 ) {
					$errors->add( 'validation', __( 'The <strong>Personal number</strong> entered is not correct.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				break;
			case 'FI':
				if ( ! isset( $_POST['pp_billing_ssn'] ) || $_POST['pp_billing_ssn'] === '' ) {
					$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				} else if ( strlen( sanitize_text_field( wp_unslash( $_POST['pp_billing_ssn'] ) ) ) < 10 ) {
					$errors->add( 'validation', __( 'The <strong>Personal number</strong> entered is not correct.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				break;
			case 'NL':
				if ( ! isset( $_POST['pp_billing_initials'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['pp_billing_initials'] ) ) ) < 2 ) {
					$errors->add( 'validation', __( '<strong>Initials</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				if ( ! isset( $_POST['pp_birth_date_year'] ) || ! isset( $_POST['pp_birth_date_month'] ) || ! isset( $_POST['pp_birth_date_day'] ) ) {
					$errors->add( 'validation', __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				break;
			case 'DE':
				if ( ! isset( $_POST['pp_birth_date_year'] ) || ! isset( $_POST['pp_birth_date_month'] ) || ! isset( $_POST['pp_birth_date_day'] ) ) {
					$errors->add( 'validation', __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				break;
		}

		// If we can only ship to the billing address, prevent shipping address
		if ( ! $this->can_ship_to_different_address() ) {
			// Transfer all order functions
			$update_order_data = function ( $wc_order ) {
				$props = [
					'first_name',
					'last_name',
					'country',
					'state',
					'postcode',
					'city',
					'address_1',
					'address_2',
				];

				foreach ( $props as $prop ) {
					$wc_order->{'set_shipping_' . $prop}( '' );
				}
			};

			add_action( 'woocommerce_checkout_create_order', $update_order_data, 10, 1 );
		}
	}

	/**
	 * Check if part payment is active
	 *
	 * @return boolean
	 */
	public function part_pay_is_active_and_set() {
		if ( ! array_key_exists( get_woocommerce_currency(), $this->svea_part_pay_currencies ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Rendering of admin options
	 *
	 * @return void
	 */
	public function admin_options() {
		?>
		<h3><?php esc_html_e( 'SveaWebPay Part Payment', 'svea-webpay-for-woocommerce' ); ?></h3>
		<p><?php esc_html_e( 'Process part payments through SveaWebPay.', 'svea-webpay-for-woocommerce' ); ?></p>
		<?php if ( $this->part_pay_is_active_and_set() ) : ?>
		<table class="form-table">
			<?php
			// Generate the HTML For the settings form.
			$this->generate_settings_html();
			?>
		</table>
		<?php else : ?>
		<div class="inline error"><p><?php esc_html_e( 'SveaWebPay Part Payment does not support your currency and/or country.', 'svea-webpay-for-woocommerce' ); ?></p></div>
			<?php
		endif;
	}

	/**
	 * Get config for the specified country
	 *
	 * @param string $country
	 *
	 * @return void
	 */
	public function get_config( $country = null ) {
		if ( is_null( $country ) ) {
			return $this->config;
		}

		$country = strtolower( $country );

		$testmode = $this->get_option( 'testmode_' . $country ) === 'yes';
		$username = $this->get_option( 'username_' . $country );
		$password = $this->get_option( 'password_' . $country );
		$client_nr = $this->get_option( 'client_nr_' . $country );

		$config_class = $testmode ? 'WC_Svea_Config_Test' : 'WC_Svea_Config_Production';

		return new $config_class( false, false, $client_nr, $password, $username );
	}

	/**
	 * Process payment through the part payments gateway
	 *
	 * @param string $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		self::log( 'Payment processing started' );

		if ( ! isset( $_POST['part_payment_plan'] ) ) {
			wc_add_notice( __( 'Part payment plan must be selected.', 'svea-webpay-for-woocommerce' ), 'error' );
			self::log( "Payment plan wasn't selected" );

			return [
				'result' => 'failure',
			];
		}

		$wc_order = wc_get_order( $order_id );

		$customer_first_name = $wc_order->get_billing_first_name();
		$customer_last_name = $wc_order->get_billing_last_name();
		$customer_address_1 = $wc_order->get_billing_address_1();
		$customer_address_2 = $wc_order->get_billing_address_2();
		$customer_zip_code = $wc_order->get_billing_postcode();
		$customer_city = $wc_order->get_billing_city();
		$customer_country = $wc_order->get_billing_country();
		$customer_email = $wc_order->get_billing_email();
		$customer_phone = $wc_order->get_billing_phone();

		$config = $this->get_config();

		$svea_order = WC_Gateway_Svea_Helper::create_svea_order( $wc_order, $config );

		$svea_order
			->setCountryCode( $customer_country )
			->setClientOrderNumber( apply_filters( 'woocommerce_sveawebpay_part_pay_client_order_number', $wc_order->get_order_number() ) )
			->setOrderDate( date( 'c' ) );

		$customer_information = WebPayItem::individualCustomer();

		switch ( strtoupper( $customer_country ) ) {
			case 'SE':
			case 'DK':
			case 'NO':
			case 'FI':
				if ( ! isset( $_POST['pp_billing_ssn'][0] ) ) {
					wc_add_notice( __( 'Personal number is required.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				$customer_information->setNationalIdNumber( sanitize_text_field( wp_unslash( $_POST['pp_billing_ssn'] ) ) );
				$customer_information->setStreetAddress( $customer_address_1 );
				break;
			case 'NL':
				if ( ! isset( $_POST['pp_billing_initials'][0] ) ) {
					wc_add_notice( __( 'Initials is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				if ( ! isset( $_POST['pp_birth_date_year'][0] ) ) {
					wc_add_notice( __( 'Birth date year is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}
				if ( ! isset( $_POST['pp_birth_date_month'][0] ) ) {
					wc_add_notice( __( 'Birth date month is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				if ( ! isset( $_POST['pp_birth_date_day'][0] ) ) {
					wc_add_notice( __( 'Birth date day is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				$exploded_zip_code = str_split( $customer_zip_code );
				$customer_zip_code = '';
				$lastChar = false;
				foreach ( $exploded_zip_code as $char ) {
					if (is_numeric( $lastChar ) && ! is_numeric( $char ))
						$customer_zip_code .= ' ' . $char;
					else $customer_zip_code .= $char;
					$lastChar = $char;
				}

				$customer_information->setInitials( sanitize_text_field( wp_unslash( $_POST['pp_billing_initials'] ) ) )
					->setBirthDate(
						intval( $_POST['pp_birth_date_year'] ),
						intval( $_POST['pp_birth_date_month'] ),
						intval( $_POST['pp_birth_date_day'] )
					);

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );
				break;
			case 'DE':
				if ( ! isset( $_POST['pp_birth_date_year'][0] ) ) {
					wc_add_notice( __( 'Birth date year is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}
				if ( ! isset( $_POST['pp_birth_date_month'][0] ) ) {
					wc_add_notice( __( 'Birth date month is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				if ( ! isset( $_POST['pp_birth_date_day'][0] ) ) {
					wc_add_notice( __( 'Birth date day is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				$customer_information->setBirthDate(
					intval( $_POST['pp_birth_date_year'] ),
					intval( $_POST['pp_birth_date_month'] ),
					intval( $_POST['pp_birth_date_day'] )
				);

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );
				break;
		}

		$customer_information
			->setZipCode( $customer_zip_code )
			->setLocality( $customer_city )
			->setName( $customer_first_name, $customer_last_name )
			->setIpAddress( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' )
			->setEmail( $customer_email )
			->setPhoneNumber( $customer_phone )
			->setCoAddress( $customer_address_2 );

		$svea_order->addCustomerDetails( $customer_information );

		$return_url = $wc_order->get_checkout_order_received_url();

		self::log( 'Issuing payment for order: #' . $wc_order->get_id() );

		$request = $svea_order->usePaymentPlanPayment( sanitize_text_field( wp_unslash( $_POST['part_payment_plan'] ) ) );

		if ( $this->strong_auth ) {
			$url = $this->get_return_url( $wc_order );

			$confirmation_url = esc_url_raw(
				add_query_arg(
					[
						'wc-api'   => 'wc_gateway_svea_part_pay_strong_auth_confirmed',
						'order-id' => $wc_order->get_id(),
						'key'      => $wc_order->get_order_key(),
					],
					$url
				)
			);

			$rejection_url = esc_url_raw(
				add_query_arg(
					[
						'wc-api'   => 'wc_gateway_svea_part_pay_strong_auth_rejected',
						'order-id' => $wc_order->get_id(),
						'key'      => $wc_order->get_order_key(),
					],
					$url
				)
			);

			$svea_order->setIdentificationConfirmationUrl( $confirmation_url );
			$svea_order->setIdentificationRejectionUrl( $rejection_url );
		}

		try {
			$response = $request->doRequest();
		} catch ( Exception $e ) {
			wc_add_notice( __( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' ), 'error' );
			// translators: %s is the error message for the payment
			$wc_order->add_order_note( sprintf( __( 'Error occurred whilst processing payment: %s', 'svea-webpay-for-woocommerce' ), $e->getMessage() ) );
			self::log( 'Error: ' . $e->getMessage() );

			return [
				'result' => 'failure',
			];
		}

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			if ( isset( $response->resultcode ) ) {
				wc_add_notice( WC_Gateway_Svea_Helper::get_svea_error_message( $response->resultcode ), 'error' );
				$wc_order->add_order_note(
					sprintf(
						// translators: %s is the error message the customer received
						__( 'Customer received error: %s', 'svea-webpay-for-woocommerce' ),
						WC_Gateway_Svea_Helper::get_svea_error_message( $response->resultcode )
					)
				);

				self::log( 'Payment failed' );

			} else {
				wc_add_notice( __( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' ), 'error' );
			}

			return [
				'result' => 'failure',
			];
		}

		$svea_order_id = $response->sveaOrderId; // phpcs:ignore

		update_post_meta( $order_id, '_svea_order_id', $svea_order_id );

		if ( $this->strong_auth && ! empty( $response->redirectUrl ) ) {
			self::log( 'Redirecting to identification url for order #' . $wc_order->get_id() );

			WC_SveaWebPay_Gateway_Cron_Functions::setup_strong_auth_check_cron( $order_id, $svea_order_id, 'part_pay' );

			return [
				'result'   => 'success',
				'redirect' => $response->redirectUrl,
			];
		}

		self::log( 'Payment complete for order #' . $wc_order->get_id() . ', ' . $response->errormessage );

		$wc_order->payment_complete( $svea_order_id );

		// Remove cart
		WC()->cart->empty_cart();

		self::log( 'Payment successful' );

		return [
			'result'   => 'success',
			'redirect' => $return_url,
		];
	}

	/**
	 * Cancels the order in svea
	 *
	 * @param   WC_Order    $order  the order being cancelled
	 * @param   string      $svea_order_id  id of the svea order
	 * @return  array       an array containing result and message
	 */
	public function cancel_order( $order, $svea_order_id ) {
		$config = $this->get_config( $order->get_billing_country() );

		$response = WebPayAdmin::cancelOrder( $config )
					->setCountryCode( $order->get_billing_country() )
					->setOrderId( $svea_order_id )
					->cancelPaymentPlanOrder()
					->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return [
				'success' => false,
				'message' => $response->errormessage,
			];
		}

		/**
		 * The request was successful
		 */
		$order->add_order_note(
			__( 'The order has been cancelled in Svea.', 'svea-webpay-for-woocommerce' )
		);

		$order->update_status( 'cancelled' );

		return [
			'success' => true,
			'message' => __( 'The order has been cancelled in Svea.', 'svea-webpay-for-woocommerce' ),
		];
	}

	/**
	 * Delivers the order in svea
	 *
	 * @param   WC_Order    $order  the order being delivered
	 * @param   string      $svea_order_id  id of the svea order
	 * @return  array       an array containing result and message
	 */
	public function deliver_order( $order, $svea_order_id ) {
		$config = $this->get_config( $order->get_billing_country() );

		$response = WebPay::deliverOrder( $config )
					->setOrderId( $svea_order_id )
					->setCountryCode( $order->get_billing_country() )
					->setInvoiceDistributionType( DistributionType::POST )
					->deliverPaymentPlanOrder()
					->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return [
				'success' => false,
				'message' => $response->errormessage,
			];
		}

		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $order_item_id => $order_item ) {
			wc_add_order_item_meta( $order_item_id, 'svea_delivered', date( 'Y-m-d H:i:s' ) );
		}

		$order->update_status( 'completed' );

		/**
		 * The request was successful
		 */
		$order->add_order_note(
			__( 'All items have been delivered in Svea.', 'svea-webpay-for-woocommerce' )
		);

		return [
			'success' => true,
			'message' => __( 'All items have been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
		];
	}

	/**
	 * Get the configuration object
	 *
	 * @return WC_Svea_Config_Production|WC_Svea_Config_Test
	 */
	public function get_testmode() {
		return $this->testmode;
	}

	/**
	 * Handle callback request for confirmed authentication
	 *
	 * @return void
	 */
	public function handle_callback_request_confirmed() {
		self::log( sprintf( 'Callback request for strong authentication initiated by client %s', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) );

		$order_id = ! empty( $_GET['order-id'] ) ? intval( $_GET['order-id'] ) : 0;
		$order_key = ! empty( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

		/**
		 * If the response-parameters is not set, do not produce a PHP-error
		 */
		if ( empty( $order_id ) || empty( $order_key ) ) {
			status_header( 400 );
			self::log( 'Bad parameters in rejection callback' );
			exit;
		}

		$wc_order = wc_get_order( (int) $order_id );

		if ( ! $wc_order ) {
			self::log( 'No order was found by ID: ' . $order_id );
			exit;
		}

		if ( ! $wc_order->key_is_valid( $order_key ) ) {
			self::log( 'Order key was not valid for order ' . $order_id );
			exit;
		}

		self::log( 'Authentication success for order #' . $order_id );

		$svea_order_id = $wc_order->get_meta( '_svea_order_id' );

		$wc_order->payment_complete( $svea_order_id );

		self::log( sprintf( 'Payment was successful and order %s is complete', $wc_order->get_id() ) );

		// Remove cart
		WC()->cart->empty_cart();

		wp_safe_redirect( esc_url_raw( $wc_order->get_checkout_order_received_url() ) );

		exit;
	}

	/**
	 * Handle callback request for rejected authentication
	 *
	 * @return void
	 */
	public function handle_callback_request_rejected() {
		self::log( sprintf( 'Callback request for strong authentication initiated by client %s', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) );

		$order_id = ! empty( $_GET['order-id'] ) ? intval( $_GET['order-id'] ) : ''; // phpcs:ignore
		$order_key = ! empty( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : ''; // phpcs:ignore

		/**
		 * If the response-parameters is not set, do not produce a PHP-error
		 */
		if ( empty( $order_id ) || empty( $order_key ) ) {
			status_header( 400 );
			self::log( 'Bad parameters in rejection callback' );
			exit;
		}

		$wc_order = wc_get_order( (int) $order_id );

		if ( ! $wc_order ) {
			self::log( 'No order was found by ID' );
			exit;
		}

		if ( ! $wc_order->key_is_valid( $order_key ) ) {
			self::log( 'Order key was not valid for order' );
			exit;
		}

		wc_add_notice( WC_Gateway_Svea_Helper::get_svea_error_message( (int) 40006 ), 'error' );

		self::log( 'Authentication failed for order #' . $order_id );

		wp_safe_redirect( esc_url_raw( wc_get_checkout_url() ) );

		exit;
	}
}
