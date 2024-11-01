<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Svea\WebPay\WebPay;
use Svea\WebPay\WebPayItem;
use Svea\WebPay\WebPayAdmin;
use Svea\WebPay\Response\SveaResponse;
use Svea\WebPay\Constant\DistributionType;

/**
 * Class to handle invoice payments through Svea WebPay
 */
class WC_Gateway_Svea_Invoice extends WC_Payment_Gateway {

	/**
	 * Id of this gateway
	 *
	 * @var     string
	 */
	const GATEWAY_ID = 'sveawebpay_invoice';

	/**
	 * Static instance of this class
	 *
	 * @var WC_Gateway_Svea_Invoice
	 */
	private static $instance = null;

	/**
	 * Whether or not the log should be enabled
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
	private $base_country;

	/**
	 * Enabled countries
	 *
	 * @var array
	 */
	private $enabled_countries;

	/**
	 * Selected currency
	 *
	 * @var string
	 */
	private $selected_currency;

	/**
	 * Testmode
	 *
	 * @var bool
	 */
	private $testmode;

	/**
	 * Distribution type
	 *
	 * @var string
	 */
	private $distribution_type;

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
	 * Invoice fee label
	 *
	 * @var string
	 */
	public $invoice_fee_label;

	/**
	 * Invoice fee
	 *
	 * @var float
	 */
	public $invoice_fee;

	/**
	 * Invoice fee tax
	 *
	 * @var float
	 */
	public $invoice_fee_tax;

	/**
	 * Invoice fee taxable
	 *
	 * @var bool
	 */
	public $invoice_fee_taxable;

	/**
	 * svea_invoice_currencies
	 *
	 * @var array
	 */
	public $svea_invoice_currencies;

	/**
	 * same shipping as billing
	 *
	 * @var bool
	 */
	public $same_shipping_as_billing;

	/**
	 * Configuration file
	 *
	 * @var WC_Svea_Config_Production
	 */
	private $config;

	/**
	 * Initialize the gateway
	 *
	 * @return WC_Gateway_Svea_Invoice
	 */
	public static function init() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new WC_Gateway_Svea_Invoice();
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

		$this->supports = [
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'multiple_subscriptions',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			//'refunds'
		];

		$this->id = self::GATEWAY_ID;

		$this->method_title = __( 'SveaWebPay Invoice Payment', 'svea-webpay-for-woocommerce' );
		$this->icon = apply_filters( 'woocommerce_sveawebpay_invoice_icon', 'https://cdn.svea.com/sveaekonomi/rgb_ekonomi_large.png' );
		$this->has_fields = true;

		$this->svea_invoice_currencies = [
			'DKK' => 'DKK', // Danish Kroner
			'EUR' => 'EUR', // Euro
			'NOK' => 'NOK', // Norwegian Kroner
			'SEK' => 'SEK',  // Swedish Kronor
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->title = __( $this->get_option( 'title' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, [ $this, 'scheduled_subscription_payment' ], 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'add_subscription_order_fields' ], 10, 1 );
		add_action( 'woocommerce_process_shop_subscription_meta', [ $this, 'save_subscription_meta' ], 10, 1 );
		add_action( 'woocommerce_api_wc_gateway_svea_invoice_strong_auth_confirmed', [ $this, 'handle_callback_request_confirmed' ] );
		add_action( 'woocommerce_api_wc_gateway_svea_invoice_strong_auth_rejected', [ $this, 'handle_callback_request_rejected' ] );

		$this->same_shipping_as_billing = $this->get_option( 'same_shipping_as_billing' ) === 'yes';

		if ( ! isset( WC()->customer ) ) {
			return;
		}

		$customer_country = strtolower( WC()->customer->get_billing_country() );

		//Merchant set fields
		$this->enabled = $this->get_option( 'enabled' );

		// Set logo by customer country
		$this->icon = $this->get_svea_invoice_logo_by_country( $customer_country );

		$wc_countries = new WC_Countries();

		$this->base_country = $wc_countries->get_base_country();

		$this->enabled_countries = is_array( $this->get_option( 'enabled_countries' ) ) ? $this->get_option( 'enabled_countries' ) : [];

		$this->selected_currency = get_woocommerce_currency();

		$this->testmode = $this->get_option( 'testmode_' . $customer_country ) === 'yes';
		$this->distribution_type = $this->get_option( 'distribution_type_' . $customer_country );
		$this->username = $this->get_option( 'username_' . $customer_country );
		$this->password = $this->get_option( 'password_' . $customer_country );
		$this->client_nr = $this->get_option( 'client_nr_' . $customer_country );
		$this->invoice_fee_label = $this->get_option( 'invoice_fee_label_' . $customer_country );
		$this->invoice_fee = $this->get_option( 'invoice_fee_' . $customer_country );
		$this->invoice_fee_tax = $this->get_option( 'invoice_fee_tax_' . $customer_country );
		$this->invoice_fee_taxable = $this->get_option( 'invoice_fee_taxable_' . $customer_country ) === 'yes';

		self::$log_enabled = $this->get_option( 'debug' ) === 'yes';

		$config_class = $this->testmode ? 'WC_Svea_Config_Test' : 'WC_Svea_Config_Production';

		$this->config = new $config_class( false, false, $this->client_nr, $this->password, $this->username );

		$this->description = __( $this->get_option( 'description' ), 'svea-webpay-for-woocommerce' ); // phpcs:ignore
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
	 * Get Svea Invoice logo depending on country
	 *
	 * @param string $country
	 *
	 * @return string URL of the invoice logo
	 */
	public function get_svea_invoice_logo_by_country( $country = '' ) {
		$default_logo = apply_filters( 'woocommerce_sveawebpay_invoice_icon', 'https://cdn.svea.com/sveaekonomi/rgb_ekonomi_large.png' );

		$country = strtoupper( $country );

		$logos = [
			'SE' => 'https://cdn.svea.com/webpay/buttons/sv/Button_Invoice_PosWhite_SV.png',
			'NO' => 'https://cdn.svea.com/webpay/buttons/no/Button_Invoice_PosWhite_NO.png',
			'FI' => 'https://cdn.svea.com/webpay/buttons/fi/Button_Invoice_PosWhite_FI.png',
			'DE' => 'https://cdn.svea.com/webpay/buttons/de/Button_Invoice_PosWhite_DE.png',
		];

		$logo = $default_logo;

		if ( isset( $logos[ $country ] ) ) {
			$logo = $logos[ $country ];
		}

		return apply_filters( 'woocommerce_sveawebpay_invoice_icon', $logo, $country );
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

	public static function display_admin_action_buttons() {
		?>
		<button type="button" class="button svea-credit-items"><?php esc_html_e( 'Credit via svea', 'svea-webpay-for-woocommerce' ); ?></button>
		<button type="button" class="button svea-deliver-items"><?php esc_html_e( 'Deliver via svea', 'svea-webpay-for-woocommerce' ); ?></button>
		<?php
	}

	/**
	 * Meta box for administrative functions for an order
	 *
	 * @return void
	 */
	public static function admin_functions_meta_box() {
		$deliver_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::DELIVER_NONCE );
		$cancel_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::CANCEL_NONCE );
		$credit_nonce = wp_create_nonce( WC_SveaWebPay_Gateway_Admin_Functions::CREDIT_NONCE );

		?>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_deliver_order&order_id=' . get_the_ID() . '&security=' . $deliver_nonce ) ); ?>">
			<?php esc_html_e( 'Deliver order', 'svea-webpay-for-woocommerce' ); ?>
		</a><br>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_cancel_order&order_id=' . get_the_ID() . '&security=' . $cancel_nonce ) ); ?>">
			<?php esc_html_e( 'Cancel order', 'svea-webpay-for-woocommerce' ); ?>
		</a><br>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=svea_webpay_admin_credit_order&order_id=' . get_the_ID() . '&security=' . $credit_nonce ) ); ?>">
			<?php esc_html_e( 'Credit invoice', 'svea-webpay-for-woocommerce' ); ?>
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
	 * Check if the current currency is supported and enabled
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
	 * Check if the Svea Invoice payment gateway is enabled.
	 *
	 * @return bool Returns true if the gateway is enabled, false otherwise.
	 */
	public function is_enabled() {
		if ( $this->enabled !== 'yes' ) {
			return false;
		}

		return true;
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

		$invoice_checkout_template = locate_template( 'woocommerce-gateway-svea-webpay/invoice/checkout.php' );

		if ( $invoice_checkout_template === '' ) {
			$invoice_checkout_template = WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/partials/invoice/checkout.php';
		}

		include( $invoice_checkout_template );
	}

	/**
	 * Adds a fee to the cart if the payment method is SveaWebPay Invoice
	 *
	 * @return void
	 */
	public function add_invoice_fee() {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$fees_total = 0.0;

		foreach ( WC()->cart->get_fees() as $fee ) {
			$fees_total += $fee->amount;
		}

		/**
		 * Calculate totals of the cart based on
		 * cart contents total, cart shipping total and cart fees total
		 */
		$cart_total = WC()->cart->cart_contents_total + WC()->cart->shipping_total + $fees_total;

		if ( isset( WC()->cart )
			&& strlen( (string) $this->invoice_fee ) > 0
			&& is_numeric( $this->invoice_fee )
			&& $cart_total > 0
			&& $this->invoice_fee > 0 ) {

			WC()->cart->add_fee( $this->invoice_fee_label, floatval( $this->invoice_fee ), $this->invoice_fee_taxable, '' );
		}
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

		if ( ! isset( $_POST['iv_billing_customer_type'] ) ) {
			$errors->add( 'validation', __( 'Please select either company or individual customer type.', 'svea-webpay-for-woocommerce' ) );
			return;
		}

		$customer_type = sanitize_text_field( wp_unslash( $_POST['iv_billing_customer_type'] ) );

		switch ( $customer_country ) {
			case 'SE':
			case 'DK':
				$request = WebPay::getAddresses( $this->get_config( $customer_country ) );
				$request->setCountryCode( $customer_country );

				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['address_selector'] ) ) {
						$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					} else if ( ! isset( $_POST['iv_billing_org_number'] ) || $_POST['iv_billing_org_number'] === '' ) {
						$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}

					$request->setCustomerIdentifier( sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) )
						->getCompanyAddresses();
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_ssn'] ) || $_POST['iv_billing_ssn'] === '' ) {
						$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}

					$request->setCustomerIdentifier( sanitize_text_field( wp_unslash( $_POST['iv_billing_ssn'] ) ) )
						->getIndividualAddresses();
				} else {
					$errors->add( 'validation', __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ) );
					return;
				}

				$response = $request->doRequest();
				$result_code = $response->resultcode;

				if ( $result_code === 'Accepted' ) {
					if ( ! empty( $_POST['address_selector'] ) ) {
						foreach ( $response->customerIdentity as $ci ) {
							if ( $ci->addressSelector === $_POST['address_selector'] ) {
								$selected_address = $ci;
								break;
							}
						}
					} else $selected_address = $response->customerIdentity[0];
					if ( ! isset( $selected_address ) ) {
						$errors->add( 'validation', __( 'We could not find your address in our database.', 'svea-webpay-for-woocommerce' ) );
						return;
					}

					// Update the order with the correct data after creation
					$update_order_data = function ( $wc_order ) use ( $selected_address ) {
						if ( ! empty( $selected_address->firstName ) ) {
							$wc_order->set_billing_first_name( $selected_address->firstName );
						}

						if ( ! empty( $selected_address->lastName ) ) {
							$wc_order->set_billing_last_name( $selected_address->lastName );
						}

						$wc_order->set_billing_company( $selected_address->fullName );
						$wc_order->set_billing_address_1( $selected_address->street );
						$wc_order->set_billing_address_2( $selected_address->coAddress );
						$wc_order->set_billing_postcode( $selected_address->zipCode );
						$wc_order->set_billing_city( $selected_address->locality );
					};

					add_action( 'woocommerce_checkout_create_order', $update_order_data, 10, 1 );
				} else if ( $result_code === 'Error' || $result_code === 'NoSuchEntity' ) {
					if ( $customer_type === 'company' ) {
						$errors->add( 'validation', __( 'The <strong>Organisational number</strong> is not valid.', 'svea-webpay-for-woocommerce' ) );
					} else if ( $customer_type === 'individual' ) {
						$errors->add( 'validation', __( 'The <strong>Personal number</strong> is not valid.', 'svea-webpay-for-woocommerce' ) );
					}

					return;
				} else {
					if ( $_POST['iv_billing_customer_type'] === 'company' ) {
						$errors->add( 'validation', __( 'An unknown error occurred while trying to lookup your Organisational number.', 'svea-webpay-for-woocommerce' ) );
					} else if ( $_POST['iv_billing_customer_type'] === 'individual' ) {
						$errors->add( 'validation', __( 'An unknown error occurred while trying to lookup your Personal number.', 'svea-webpay-for-woocommerce' ) );
					} else {
						$errors->add( 'validation', __( 'An unknown error occurred.', 'svea-webpay-for-woocommerce' ) );
					}

					return;
				}
				break;
			case 'NO':
				if ( $customer_type === 'company' ) {
					$request = WebPay::getAddresses( $this->get_config( $customer_country ) )
						->setCountryCode( $customer_country );

					if ( ! isset( $_POST['address_selector'] ) ) {
						$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					} else if ( ! isset( $_POST['iv_billing_org_number'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) ) <= 0 ) {
						$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}

					$request->setCustomerIdentifier( sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) )
						->getCompanyAddresses();

					$response = $request->doRequest();
					$result_code = $response->resultcode;

					if ( $result_code === 'Accepted' ) {
						foreach ( $response->customerIdentity as $ci ) {
							if ( $ci->addressSelector === $_POST['address_selector'] ) {
								$selected_address = $ci;
								break;
							}
						}

						if ( ! isset( $selected_address ) ) {
							$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
							return;
						}

						// Update the order with the correct data after creation
						$update_order_data = function ( $wc_order ) use ( $selected_address ) {
							if ( ! empty( $selected_address->firstName ) ) {
								$wc_order->set_billing_first_name( $selected_address->firstName );
							}

							if ( ! empty( $selected_address->lastName ) ) {
								$wc_order->set_billing_last_name( $selected_address->lastName );
							}

							$wc_order->set_billing_company( $selected_address->fullName );
							$wc_order->set_billing_address_1( $selected_address->street );
							$wc_order->set_billing_address_2( $selected_address->coAddress );
							$wc_order->set_billing_postcode( $selected_address->zipCode );
							$wc_order->set_billing_city( $selected_address->locality );
						};

						add_action( 'woocommerce_checkout_create_order', $update_order_data, 10, 1 );
					} else if ( $result_code === 'Error' || $result_code === 'NoSuchEntity' ) {
						$errors->add( 'validation', __( $response['errormessage'], 'svea-webpay-for-woocommerce' ) ); // phpcs:ignore
					} else {
						$errors->add( 'validation', __( 'An unknown error occurred while trying to lookup your Organisational number.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_ssn'] ) || $_POST['iv_billing_ssn'] === '' ) {
						$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else {
					$errors->add( 'validation', __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ) );
					return;
				}
				break;
			case 'FI':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['iv_billing_org_number'] ) || $_POST['iv_billing_org_number'] === '' ) {
						$errors->add( 'validation', __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					} else if ( strlen( sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) ) < 8 ) {
						$errors->add( 'validation', __( 'The <strong>Organisational number</strong> entered is not correct.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_ssn'] ) || $_POST['iv_billing_ssn'] === '' ) {
						$errors->add( 'validation', __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else {
					$errors->add( 'validation', __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ) );
					return;
				}
				break;
			case 'NL':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['iv_billing_vat_number'] ) ) {
						$errors->add( 'validation', __( '<strong>VAT number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
					if ( ! isset( $_POST['billing_company'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) ) <= 0 ) {
						$errors->add( 'validation', __( '<strong>Company Name</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_initials'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['iv_billing_initials'] ) ) ) < 2 ) {
						$errors->add( 'validation', __( '<strong>Initials</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
					if ( ! isset( $_POST['iv_birth_date_year'] ) || ! isset( $_POST['iv_birth_date_month'] ) || ! isset( $_POST['iv_birth_date_day'] ) ) {
						$errors->add( 'validation', __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else {
					$errors->add( 'validation', __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ) );
					return;
				}
				break;
			case 'DE':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['iv_billing_vat_number'] ) ) {
						$errors->add( 'validation', __( '<strong>VAT number</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
					if ( ! isset( $_POST['billing_company'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) ) === 0 ) {
						$errors->add( 'validation', __( '<strong>Company Name</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['iv_birth_date_year'] ) || ! isset( $_POST['iv_birth_date_month'] ) || ! isset( $_POST['iv_birth_date_day'] ) ) {
						$errors->add( 'validation', __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ) );
						return;
					}
				} else {
					$errors->add( 'validation', __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ) );
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
	 * Check if the invoice gateway is active and set
	 *
	 * @return boolean
	 */
	public function invoice_is_active_and_set() {
		if ( ! array_key_exists( get_woocommerce_currency(), $this->svea_invoice_currencies ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Display options in admin or an error if something is not right
	 *
	 * @return  void
	 */
	public function admin_options() {
		?>
		<h3> <?php esc_html_e( 'SveaWebPay Invoice Payment', 'svea-webpay-for-woocommerce' ); ?> </h3>
		<p> <?php esc_html_e( 'Process invoice payments through SveaWebPay.', 'svea-webpay-for-woocommerce' ); ?> </p>
		<?php if ( $this->invoice_is_active_and_set() ) : ?>
		<table class="form-table">
			<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			?>
		</table>
		<h3><?php esc_html_e( 'Get Address Shortcode', 'svea-webpay-for-woocommerce' ); ?></h3>
		<p>
			<?php esc_html_e( 'If you want to move the Get-Address box on the checkout page you can do so with the shortcode', 'svea-webpay-for-woocommerce' ); ?> <code>[svea_get_address]</code>
		</p>
		<p>
			<?php esc_html_e( 'By using the shortcode on the checkout page, you automatically disable the Get-Address box in the gateways. You can only use the shortcode if you have a valid Svea Invoice account.', 'svea-webpay-for-woocommerce' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'The shortcode is usable for the countries in which you are using Svea Invoice, independentenly of the payment method.', 'svea-webpay-for-woocommerce' ); ?>
		</p>
		<?php else : ?>
		<div class="inline error"><p><?php esc_html_e( 'Sveawebpay does not support your currency and or country.', 'svea-webpay-for-woocommerce' ); ?></p></div>
			<?php
		endif;
	}

	/**
	 * Initialize our options for this gateway
	 *
	 * @return  void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'                  => [
				'title'   => __( 'Enable/disable', 'svea-webpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SveaWebPay Invoice Payment', 'svea-webpay-for-woocommerce' ),
				'default' => 'no',
			],
			'title'                    => [
				'title'       => __( 'Title', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Invoice 14 days', 'svea-webpay-for-woocommerce' ),
			],
			'description'              => [
				'title'       => __( 'Description', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description the user sees during checkout', 'svea-webpay-for-woocommerce' ),
				'default'     => __( 'Pay with invoice through Svea Ekonomi', 'svea-webpay-for-woocommerce' ),
			],
			'enabled_countries'        => [
				'title'       => __( 'Enabled countries', 'svea-webpay-for-woocommerce' ),
				'type'        => 'multiselect',
				'description' => __( 'Choose the countries you want SveaWebPay Invoice Payment to be active in', 'svea-webpay-for-woocommerce' ),
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
			'same_shipping_as_billing' => [
				'title'       => __( 'Same shipping address as billing address', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If checked, billing address will override the shipping address', 'svea-webpay-for-woocommerce' ),
				'default'     => 'yes',
			],
			//Denmark
			'testmode_dk'              => [
				'title'       => __( 'Test mode Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Denmark', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_dk'     => [
				'title'       => __( 'Distribution type Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Denmark', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_dk'              => [
				'title'       => __( 'Username Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_dk'              => [
				'title'       => __( 'Password Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_dk'             => [
				'title'       => __( 'Client number Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_dk'     => [
				'title'       => __( 'Invoice fee label Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Denmark', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Denmark',
			],
			'invoice_fee_dk'           => [
				'title'       => __( 'Invoice fee Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Denmark, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_dk'   => [
				'title'       => __( 'Invoice fee taxable Denmark', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Denmark, check the box.', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Germany
			'testmode_de'              => [
				'title'       => __( 'Test mode Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Germany', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_de'     => [
				'title'       => __( 'Distribution type Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Germany', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_de'              => [
				'title'       => __( 'Username Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_de'              => [
				'title'       => __( 'Password Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_de'             => [
				'title'       => __( 'Client number Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_de'     => [
				'title'       => __( 'Invoice fee label Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Germany', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Germany',
			],
			'invoice_fee_de'           => [
				'title'       => __( 'Invoice fee Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Germany, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_de'   => [
				'title'       => __( 'Invoice fee taxable Germany', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Germany, check the box.' ),
				'default'     => '',
			],
			//Finland
			'testmode_fi'              => [
				'title'       => __( 'Test mode Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Finland', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_fi'     => [
				'title'       => __( 'Distribution type Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Finland', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_fi'              => [
				'title'       => __( 'Username Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_fi'              => [
				'title'       => __( 'Password Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_fi'             => [
				'title'       => __( 'Client number Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_fi'     => [
				'title'       => __( 'Invoice fee label Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Finland', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Finland',
			],
			'invoice_fee_fi'           => [
				'title'       => __( 'Invoice fee Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Finland, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_fi'   => [
				'title'       => __( 'Invoice fee taxable Finland', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Finland, check the box.' ),
				'default'     => '',
			],
			//Netherlands
			'testmode_nl'              => [
				'title'       => __( 'Test mode Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Netherlands', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_nl'     => [
				'title'       => __( 'Distribution type Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Netherlands', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_nl'              => [
				'title'       => __( 'Username Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_nl'              => [
				'title'       => __( 'Password Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_nl'             => [
				'title'       => __( 'Client number Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_nl'     => [
				'title'       => __( 'Invoice fee label Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Netherlands', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Netherlands',
			],
			'invoice_fee_nl'           => [
				'title'       => __( 'Invoice fee Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Netherlands, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_nl'   => [
				'title'       => __( 'Invoice fee taxable Netherlands', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Netherlands, check the box.', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			//Norway
			'testmode_no'              => [
				'title'       => __( 'Test mode Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Norway', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_no'     => [
				'title'       => __( 'Distribution type Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Norway', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_no'              => [
				'title'       => __( 'Username Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_no'              => [
				'title'       => __( 'Password Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_no'             => [
				'title'       => __( 'Client number Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_no'     => [
				'title'       => __( 'Invoice fee label Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Norway', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Norway',
			],
			'invoice_fee_no'           => [
				'title'       => __( 'Invoice fee Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Norway, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_no'   => [
				'title'       => __( 'Invoice fee taxable Norway', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Norway, check the box.' ),
				'default'     => '',
			],
			//Sweden
			'testmode_se'              => [
				'title'       => __( 'Test mode Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable/disable test mode in Sweden', 'svea-webpay-for-woocommerce' ),
				'description' => __( 'When testing out the gateway, this option should be enabled', 'svea-webpay-for-woocommerce' ),
				'default'     => 'no',
			],
			'distribution_type_se'     => [
				'title'       => __( 'Distribution type Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'This controls which distribution type you will be using for invoices in Sweden', 'svea-webpay-for-woocommerce' ),
				'options'     => [
					DistributionType::POST  => __( 'Post', 'svea-webpay-for-woocommerce' ),
					DistributionType::EMAIL => __( 'E-mail', 'svea-webpay-for-woocommerce' ),
				],
				'default'     => DistributionType::EMAIL,
			],
			'username_se'              => [
				'title'       => __( 'Username Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Username for invoice payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'password_se'              => [
				'title'       => __( 'Password Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Password for invoice payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'client_nr_se'             => [
				'title'       => __( 'Client number Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Client number for invoice payments in Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_label_se'     => [
				'title'       => __( 'Invoice fee label Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Label for the invoice fee for Sweden', 'svea-webpay-for-woocommerce' ),
				'default'     => 'Invoice fee Sweden',
			],
			'invoice_fee_se'           => [
				'title'       => __( 'Invoice fee Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Invoice fee for Sweden, this should be entered exclusive of tax', 'svea-webpay-for-woocommerce' ),
				'default'     => '',
			],
			'invoice_fee_taxable_se'   => [
				'title'       => __( 'Invoice fee taxable Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'If the invoice fee is taxable in Sweden, check the box.' ),
				'default'     => '',
			],
			'strong_auth_se'   => [
				'title'       => __( 'Strong authentication', 'svea-webpay-for-woocommerce' ),
				'label'       => __( 'Enable strong authentication for invoice orders in Sweden', 'svea-webpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'For this to work, the setting must also be toggled on in your Svea account.', 'svea-webpay-for-woocommerce' ) . '<br />' ,
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
					"Disable automatic syncing of orders in WooCommerce to Svea. <br />
						If you enable this option, your refunded orders will not be refunded in Svea. <br />
						Your delivered orders will not be delivered in Svea and your cancelled orders will not be cancelled in Svea. <br />
						<strong>Don't touch this if you don't know what you're doing</strong>.",
					'svea-webpay-for-woocommerce'
				),
				'default'     => 'no',
			],
		];
	}/** @noinspection PhpUndefinedClassInspection */

	/**
	 * Fetches the distribution type for the provided country
	 *
	 * @param   $country    the country that we are getting distribution type from
	 * @return  string      the distribution type for the provided country
	 */
	public function get_distribution_type( $country = '' ) {
		$country = strtolower( $country );
		return $this->get_option( 'distribution_type_' . $country );
	}

	/**
	 * Get the Svea configuration for the provided country
	 *
	 * @param   mixed   $country    optional country
	 * @return  WC_Svea_Config_Test|WC_Svea_Config_Production   svea configuration
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
	 * Process refunds
	 *
	 * @param   string  $order_id   id of the order being refunded
	 * @param   float   $amount     amount being refunded
	 * @param   string  $reason     reason of the refund
	 *
	 * @return  boolean
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		$svea_order_id = wc_get_order_item_meta( $order->get_id(), 'svea_order_id' );

		if ( ! $svea_order_id || strlen( (string) $svea_order_id ) <= 0 ) {
			return false;
		}

		$response = WebPayAdmin::queryOrder( $this->config )
			->setOrderId( $svea_order_id )
			->setCountryCode( $order->get_billing_country() )
			->queryInvoiceOrder()
			->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return new WP_Error( 'error', $response->errormessage );
		}

		if ( ! isset( $response->numberedOrderRows ) || count( $response->numberedOrderRows ) === 0 ) {
			return new WP_Error( 'no_items', __( 'Couldn\'t find any order rows in Svea', 'svea-webpay-for-woocommerce' ) );
		}

		$invoice_id = $response->numberedOrderRows[0]->invoiceId;

		if ( is_null( $invoice_id ) )
			return new WP_Error( 'not_delivered', __( 'You have to deliver the order at Svea first', 'svea-webpay-for-woocommerce' ) );

		$credit_name = __( 'Refund', 'svea-webpay-for-woocommerce' );

		if ( strlen( $reason ) > 0 )
			$credit_name .= ': ' . $reason;

		$response = WebPayAdmin::creditOrderRows( $this->config )
			->setInvoiceId( $invoice_id )
			->setCountryCode( $order->get_billing_country() )
			->setInvoiceDistributionType( $this->get_distribution_type() )
			->addCreditOrderRow(
				WebPayItem::orderRow()
					->setAmountExVat( (float) $amount )
					->setVatPercent( 0 )
					->setDiscountPercent( 0 )
					->setName( $credit_name )
					->setQuantity( 1 )
			)
			->creditInvoiceOrderRows()
			->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			new WP_Error( 'error', $response->errormessage );
		}

		return true;
	}

	/**
	 * This function handles the payment processing
	 *
	 * @param   int     $order_id   id of the order being processed
	 * @return  array   an array containing the result of the payment
	 */
	public function process_payment( $order_id ) {
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
		$customer_company = $wc_order->get_billing_company();
		$strong_auth = $this->uses_strong_auth( $customer_country );

		$config = $this->get_config();

		$subscriptions = false;

		/**
		 * We need to recalculate totals to include
		 * the invoice fee in the total amount in
		 * WooCommerce
		 */
		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			if ( ( wcs_order_contains_subscription( $wc_order )
				|| wcs_order_contains_switch( $wc_order )
				|| wcs_order_contains_resubscribe( $wc_order )
				|| wcs_order_contains_renewal( $wc_order ) ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( $wc_order->get_id(), [ 'order_type' => 'any' ] );
			} else if ( wcs_is_subscription( $wc_order->get_id() ) ) {
				$subscriptions = [ wcs_get_subscription( $wc_order->get_id() ) ];
			}
		}

		/**
		 * Convert our WooCommerce order to Svea
		 */
		$svea_order = WC_Gateway_Svea_Helper::create_svea_order( $wc_order, $config );

		$svea_order
			->setClientOrderNumber( apply_filters( 'woocommerce_sveawebpay_invoice_client_order_number', $wc_order->get_order_number() ) )
			->setCurrency( get_woocommerce_currency() )
			->setCountryCode( $customer_country )
			->setOrderDate( date( 'c' ) );

		switch ( strtoupper( $customer_country ) ) {
			case 'SE':
			case 'DK':
			case 'NO':
			case 'FI':
				if ( $_POST['iv_billing_customer_type'] === 'company' ) {
					if ( ! isset( $_POST['iv_billing_org_number'][0] ) ) {
						wc_add_notice( __( 'Organisation number is required.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::companyCustomer()
						->setNationalIdNumber( sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) );

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_billing_org_number', sanitize_text_field( wp_unslash( $_POST['iv_billing_org_number'] ) ) );
						}
					}

					if ( isset( $_POST['address_selector'] ) && strlen( sanitize_text_field( wp_unslash( $_POST['address_selector'] ) ) ) > 0 ) {
						$customer_information->setAddressSelector( sanitize_text_field( wp_unslash( $_POST['address_selector'] ) ) );

						if ( $subscriptions ) {
							foreach ( $subscriptions as $subscription ) {
								update_post_meta( $subscription->get_id(), '_svea_address_selector', sanitize_text_field( wp_unslash( $_POST['address_selector'] ) ) );
							}
						}
					}
				} else if ( $_POST['iv_billing_customer_type'] === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_ssn'][0] ) ) {
						wc_add_notice( __( 'Personal number is required.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setNationalIdNumber( sanitize_text_field( wp_unslash( $_POST['iv_billing_ssn'] ) ) );

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_billing_ssn', sanitize_text_field( wp_unslash( $_POST['iv_billing_ssn'] ) ) );
						}
					}
				} else {
					wc_add_notice( __( 'Personal/organisation number is required.', 'svea-webpay-for-woocommerce' ), 'error' );
					return [
						'result' => 'failure',
					];
				}

				if ( $subscriptions ) {
					foreach ( $subscriptions as $subscription ) {
						update_post_meta( $subscription->get_id(), '_svea_iv_billing_customer_type', sanitize_text_field( wp_unslash( $_POST['iv_billing_customer_type'] ) ) );
					}
				}

				$customer_information->setStreetAddress( $customer_address_1 );
				break;
			case 'NL':
				$exploded_zip_code = str_split( $customer_zip_code );
				$customer_zip_code = '';
				$lastChar = false;

				foreach ( $exploded_zip_code as $char ) {
					if ( is_numeric( $lastChar ) && ! is_numeric( $char ) ) {
						$customer_zip_code .= ' ' . $char;
					} else {
						$customer_zip_code .= $char;
					}

					$lastChar = $char;
				}

				if ( $_POST['iv_billing_customer_type'] === 'company' ) {
					if ( ! isset( $_POST['iv_billing_vat_number'][0] ) ) {
						wc_add_notice( __( 'VAT number is required.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::companyCustomer()
						->setVatNumber( sanitize_text_field( wp_unslash( $_POST['iv_billing_vat_number'] ) ) )
						->setCompanyName( $customer_company );

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_billing_vat_number', sanitize_text_field( wp_unslash( $_POST['iv_billing_vat_number'] ) ) );
						}
					}
				} else if ( $_POST['iv_billing_customer_type'] === 'individual' ) {
					if ( ! isset( $_POST['iv_billing_initials'][0] ) ) {
						wc_add_notice( __( 'Initials is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					if ( ! isset( $_POST['iv_birth_date_year'][0] ) ) {
						wc_add_notice( __( 'Birth date year is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					if ( ! isset( $_POST['iv_birth_date_month'][0] ) ) {
						wc_add_notice( __( 'Birth date month is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					if ( ! isset( $_POST['iv_birth_date_day'][0] ) ) {
						wc_add_notice( __( 'Birth date day is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setInitials( sanitize_text_field( wp_unslash( $_POST['iv_billing_initials'] ) ) )
						->setBirthDate(
							intval( $_POST['iv_birth_date_year'] ),
							intval( $_POST['iv_birth_date_month'] ),
							intval( $_POST['iv_birth_date_day'] )
						);

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_billing_initials', sanitize_text_field( wp_unslash( $_POST['iv_billing_initials'] ) ) );
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_year', intval( $_POST['iv_birth_date_year'] ) );
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_month', intval( $_POST['iv_birth_date_month'] ) );
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_day', intval( $_POST['iv_birth_date_day'] ) );
						}
					}
				}

				if ( $subscriptions ) {
					foreach ( $subscriptions as $subscription ) {
						update_post_meta( $subscription->get_id(), '_svea_iv_billing_customer_type', sanitize_text_field( wp_unslash( $_POST['iv_billing_customer_type'] ) ) );
					}
				}

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );

				break;
			case 'DE':
				if ( $_POST['iv_billing_customer_type'] === 'company' ) {
					if ( ! isset( $_POST['iv_billing_vat_number'][0] ) ) {
						wc_add_notice( __( 'VAT number is required.', 'svea-webpay-for-woocommerce' ), 'error' );

						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::companyCustomer()
						->setCompanyName( $customer_company )
						->setVatNumber( sanitize_text_field( wp_unslash( $_POST['iv_billing_vat_number'] ) ) );

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_billing_vat_number', sanitize_text_field( wp_unslash( $_POST['iv_billing_vat_number'] ) ) );
						}
					}
				} else if ( $_POST['iv_billing_customer_type'] === 'individual' ) {
					if ( ! isset( $_POST['iv_birth_date_year'][0] ) ) {
						wc_add_notice( __( 'Birth date year is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );

						return [
							'result' => 'failure',
						];
					}

					if ( ! isset( $_POST['iv_birth_date_month'][0] ) ) {
						wc_add_notice( __( 'Birth date month is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					if ( ! isset( $_POST['iv_birth_date_day'][0] ) ) {
						wc_add_notice( __( 'Birth date day is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return [
							'result' => 'failure',
						];
					}

					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setBirthDate(
							intval( $_POST['iv_birth_date_year'] ),
							intval( $_POST['iv_birth_date_month'] ),
							intval( $_POST['iv_birth_date_day'] )
						);

					if ( $subscriptions ) {
						foreach ( $subscriptions as $subscription ) {
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_year', intval( $_POST['iv_birth_date_year'] ) );
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_month', intval( $_POST['iv_birth_date_month'] ) );
							update_post_meta( $subscription->get_id(), '_svea_iv_birth_date_day', intval( $_POST['iv_birth_date_day'] ) );
						}
					}
				}

				if ( $subscriptions ) {
					foreach ( $subscriptions as $subscription ) {
						update_post_meta( $subscription->get_id(), '_svea_iv_billing_customer_type', sanitize_text_field( wp_unslash( $_POST['iv_billing_customer_type'] ) ) );
					}
				}

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );

				break;

			// Handle countries not supported by the payment gateway
			default:
				wc_add_notice( __( 'Country is not supported for invoice payments.', 'svea-webpay-for-woocommerce' ), 'error' );

				return [
					'result' => 'failure',
				];
		}

		$return_url = $wc_order->get_checkout_order_received_url();

		if ( isset( $_POST['iv_billing_customer_type'] ) && $_POST['iv_billing_customer_type'] === 'company'
			&& ! $this->same_shipping_as_billing && strlen( $wc_order->get_shipping_first_name() ) > 0
			&& strlen( $wc_order->get_shipping_last_name() ) > 0 ) {
			$customer_reference = $wc_order->get_shipping_first_name() . ' ' . $wc_order->get_shipping_last_name();

			if ( function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $customer_reference ) > 32 ) {
					$customer_reference = mb_substr( $customer_reference, 0, 29 ) . '...';
				}
			} else if ( strlen( $customer_reference ) > 32 ) {
				$customer_reference = substr( $customer_reference, 0, 29 ) . '...';
			}

			$svea_order->setCustomerReference( $customer_reference );
		}

		/**
		 * Set customer information in the Svea Order
		 */
		$customer_information
			->setZipCode( $customer_zip_code )
			->setLocality( $customer_city )
			->setIpAddress( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' )
			->setEmail( $customer_email )
			->setPhoneNumber( $customer_phone )
			->setCoAddress( $customer_address_2 );

		$svea_order->addCustomerDetails( $customer_information );

		/**
		 * If we are hooked into WooCommerce subscriptions,
		 * see if any payment is required right now
		 */
		if ( $subscriptions && $wc_order->get_total() <= 0 ) {
			$wc_order->payment_complete();

			// Remove cart
			WC()->cart->empty_cart();

			if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
				foreach ( $subscriptions as $subscription ) {
					WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, $this->id );
				}
			}

			return [
				'result'   => 'success',
				'redirect' => $return_url,
			];
		}

		self::log( 'Issuing payment for order: #' . $wc_order->get_id() );

		if ( $strong_auth ) {
			$url = $this->get_return_url( $wc_order );

			$confirmation_url = esc_url_raw(
				add_query_arg(
					[
						'wc-api'   => 'wc_gateway_svea_invoice_strong_auth_confirmed',
						'order-id' => $wc_order->get_id(),
						'key'      => $wc_order->get_order_key(),
					],
					$url
				)
			);

			$rejection_url = esc_url_raw(
				add_query_arg(
					[
						'wc-api'   => 'wc_gateway_svea_invoice_strong_auth_rejected',
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
			$response = $svea_order->useInvoicePayment()->doRequest();
		} catch ( Exception $e ) {
			self::log( 'Error message for order #' . $wc_order->get_id() . ', ' . $e->getMessage() );
			wc_add_notice( __( 'An error occurred whilst connecting to Svea. Please contact the store owner and display this message.', 'svea-webpay-for-woocommerce' ), 'error' );

			return [
				'result' => 'failure',
			];
		}

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			if ( isset( $response->resultcode ) ) {
				if ( isset( $response->errormessage ) ) {
					self::log( 'Error message for order #' . $wc_order->get_id() . ', ' . $response->errormessage );
				}

				wc_add_notice( WC_Gateway_Svea_Helper::get_svea_error_message( $response->resultcode ), 'error' );
				$wc_order->add_order_note(
					sprintf(
						// translators: %s is the error that the customer received
						__( 'Customer received error: %s', 'svea-webpay-for-woocommerce' ),
						WC_Gateway_Svea_Helper::get_svea_error_message( $response->resultcode )
					)
				);

			} else {
				self::log( 'Unknown error occurred for payment with order number: #' . $wc_order->get_id() );
				wc_add_notice( __( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' ), 'error' );
			}

			return [
				'result' => 'failure',
			];
		}

		if ( $subscriptions ) {
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->get_payment_method() !== $this->id ) {
					$subscription->set_payment_method( $this->id );
					$subscription->save();
				}
			}
		}

		$svea_order_id = $response->sveaOrderId;

		update_post_meta( $order_id, '_svea_order_id', $svea_order_id );

		if ( $strong_auth && ! empty( $response->redirectUrl ) ) {
			self::log( 'Redirecting to identification url for order #' . $wc_order->get_id() );

			WC_SveaWebPay_Gateway_Cron_Functions::setup_strong_auth_check_cron( $order_id, $svea_order_id, 'invoice' );

			return [
				'result'   => 'success',
				'redirect' => $response->redirectUrl,
			];
		}

		self::log( 'Payment complete for order #' . $wc_order->get_id() . ', ' . $response->errormessage );

		$wc_order->payment_complete( $svea_order_id );

		// Remove cart
		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => $return_url,
		];
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
	 * Adds fields to subscription orders
	 *
	 * @return  void
	 */
	public function add_subscription_order_fields( $subscription ) {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		$post_data = [
			'iv_billing_customer_type' => get_post_meta( $subscription->get_id(), '_svea_iv_billing_customer_type', true ),
			'iv_billing_org_number'    => get_post_meta( $subscription->get_id(), '_svea_iv_billing_org_number', true ),
			'iv_billing_ssn'           => get_post_meta( $subscription->get_id(), '_svea_iv_billing_ssn', true ),
			'iv_billing_vat_number'    => get_post_meta( $subscription->get_id(), '_svea_iv_billing_vat_number', true ),
			'iv_birth_date_year'       => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_year', true ),
			'iv_birth_date_month'      => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_month', true ),
			'iv_birth_date_day'        => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_day', true ),
			'iv_billing_initials'      => get_post_meta( $subscription->get_id(), '_svea_iv_billing_initials', true ),
		];

		include( WC_SVEAWEBPAY_GATEWAY_PLUGIN_DIR . '/partials/invoice/admin-subscription.php' );
	}

	/**
	 * Validate order fields passed in subscriptions
	 *
	 * @return mixed
	 */
	public function validate_subscription_order_fields( $payment_method_id, $payment_meta, $subscription ) {
		return $payment_meta;
	}

	/**
	 * Save meta on subscription
	 *
	 * @param int $post_id
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function save_subscription_meta( $post_id ) {
		if ( isset( $_POST['wc_order_action'] ) && strlen( sanitize_text_field( wp_unslash( $_POST['wc_order_action'] ) ) ) > 0 ) {
			return;
		}

		$subscription = wcs_get_subscription( $post_id );

		if ( ! isset( $_POST['_billing_country'] ) || ! isset( $_POST['_iv_billing_customer_type'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_payment_method'] ) || $_POST['_payment_method'] !== $this->id ) {
			return;
		}

		$customer_country = strtoupper( sanitize_text_field( wp_unslash( $_POST['_billing_country'] ) ) );
		$customer_type = sanitize_text_field( wp_unslash( $_POST['_iv_billing_customer_type'] ) );

		switch ( strtoupper( $customer_country ) ) {
			case 'SE':
			case 'DK':
			case 'NO':
			case 'FI':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['_address_selector'] ) ) {
						wcs_add_admin_notice( __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					} else if ( ! isset( $_POST['_iv_billing_org_number'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['_iv_billing_org_number'] ) ) ) <= 0 ) {
						wcs_add_admin_notice( __( '<strong>Organisational number</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['_iv_billing_ssn'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['_iv_billing_ssn'] ) ) ) <= 0 ) {
						wcs_add_admin_notice( __( '<strong>Personal number</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else {
					wcs_add_admin_notice( __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ), 'error' );
					return;
				}
				break;
			case 'NL':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['_iv_billing_vat_number'] ) ) {
						wcs_add_admin_notice( __( '<strong>VAT number</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}

					if ( ! isset( $_POST['_billing_company'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['_billing_company'] ) ) ) <= 0 ) {
						wcs_add_admin_notice( __( '<strong>Company Name</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['_iv_billing_initials'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['_iv_billing_initials'] ) ) ) < 2 ) {
						wcs_add_admin_notice( __( '<strong>Initials</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}

					if ( ! isset( $_POST['_iv_birth_date_year'] ) || ! isset( $_POST['_iv_birth_date_month'] ) || ! isset( $_POST['_iv_birth_date_day'] ) ) {
						wcs_add_admin_notice( __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else {
					wcs_add_admin_notice( __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ), 'error' );
					return;
				}
				break;
			case 'DE':
				if ( $customer_type === 'company' ) {
					if ( ! isset( $_POST['_iv_billing_vat_number'] ) ) {
						wcs_add_admin_notice( __( '<strong>VAT number</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}

					if ( ! isset( $_POST['_billing_company'] ) || strlen( sanitize_text_field( wp_unslash( $_POST['_billing_company'] ) ) ) <= 0 ) {
						wcs_add_admin_notice( __( '<strong>Company Name</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else if ( $customer_type === 'individual' ) {
					if ( ! isset( $_POST['_iv_birth_date_year'] ) || ! isset( $_POST['_iv_birth_date_month'] ) || ! isset( $_POST['_iv_birth_date_day'] ) ) {
						wcs_add_admin_notice( __( '<strong>Date of birth</strong> is a required field.', 'svea-webpay-for-woocommerce' ), 'error' );
						return;
					}
				} else {
					wcs_add_admin_notice( __( 'Invalid customer type', 'svea-webpay-for-woocommerce' ), 'error' );
					return;
				}
				break;
		}

		$fields = [
			'_svea_iv_billing_customer_type' => '_iv_billing_customer_type',
			'_svea_address_selector'         => '_address_selector',
			'_svea_iv_billing_ssn'           => '_iv_billing_ssn',
			'_svea_iv_billing_initials'      => '_iv_billing_initials',
			'_svea_iv_billing_vat_number'    => '_iv_billing_vat_number',
			'_svea_iv_birth_date_day'        => '_iv_birth_date_day',
			'_svea_iv_birth_date_month'      => '_iv_birth_date_month',
			'_svea_iv_birth_date_year'       => '_iv_birth_date_year',
			'_svea_iv_billing_org_number'    => '_iv_billing_org_number',
		];

		foreach ( $fields as $field_key => $field_value ) {
			if ( ! isset( $_POST[ $field_value ] ) ) {
				continue;
			}

			update_post_meta( $subscription->get_id(), $field_key, sanitize_text_field( wp_unslash( $_POST[ $field_value ] ) ) );
		}
	}

	/**
	 * Handles scheduled subscription payments
	 *
	 * @param   float       $amount_to_charge   the amount that should be charged
	 * @param   WC_Order    $wc_order           the original order
	 * @param   int         $product_id         id of the subscription product
	 * @return  void
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $wc_order ) {

		self::log( 'Scheduled subscription payment initiated' );

		/**
		 * Get the subscription from the WooCommerce order
		 */
		$subscriptions = wcs_get_subscriptions_for_order( $wc_order->get_id(), [ 'order_type' => 'any' ] );

		$subscription = array_shift( $subscriptions );

		$wc_original_order = false;

		if ( $subscription->get_parent() !== false ) {
			$wc_original_order = $subscription->get_parent();
		}

		$customer_first_name = $wc_order->get_billing_first_name();
		$customer_last_name = $wc_order->get_billing_last_name();
		$customer_address_1 = $wc_order->get_billing_address_1();
		$customer_address_2 = $wc_order->get_billing_address_2();
		$customer_zip_code = $wc_order->get_billing_postcode();
		$customer_city = $wc_order->get_billing_city();
		$customer_country = $wc_order->get_billing_country();
		$customer_email = $wc_order->get_billing_email();
		$customer_phone = $wc_order->get_billing_phone();
		$customer_company = $wc_order->get_billing_company();

		$invoice_fee_label = $this->get_option( 'invoice_fee_label_' . strtolower( $customer_country ) );
		$invoice_fee = floatval( $this->get_option( 'invoice_fee_' . strtolower( $customer_country ) ) );
		$invoice_fee_taxable = $this->get_option( 'invoice_fee_taxable_' . strtolower( $customer_country ) ) === 'yes';

		if ( strlen( $invoice_fee_label ) > 0 && is_numeric( $invoice_fee ) ) {
			$invoice_fee_exists = false;

			foreach ( $wc_order->get_fees() as $fee ) {
				if ( $fee->get_name() === $invoice_fee_label ) {
					$invoice_fee_exists = true;
					break;
				}
			}

			if ( $invoice_fee > 0 && ! $invoice_fee_exists ) {

				$fee = new WC_Order_item_Fee();
				$fee->set_name( $invoice_fee_label );
				$fee->set_total( $invoice_fee );
				$fee->set_order_id( $wc_order->get_id() );

				if ( $invoice_fee_taxable ) {
					$invoice_tax_rates = WC_Tax::find_rates(
						[
							'country'  => $wc_order->get_billing_country(),
							'state'    => $wc_order->get_billing_state(),
							'city'     => $wc_order->get_billing_city(),
							'postcode' => $wc_order->get_billing_postcode(),
						]
					);

					$invoice_fee_tax = 0;

					if ( count( $invoice_tax_rates ) > 0 ) {
						$invoice_tax_rate = array_shift( $invoice_tax_rates );

						$fee_total = $invoice_fee * ( $invoice_tax_rate['rate'] / 100 + 1 );

						$invoice_fee_tax = $fee_total - $invoice_fee;
					} else {
						$invoice_fee_tax = 0;
					}

					$fee->set_tax_status( 'taxable' );
					$fee->set_tax_class( '' );
					$fee->set_total_tax( $invoice_fee_tax );
				}

				$fee->save();

				$wc_order->add_item( $fee );

				/**
				 * We need WooCommerce to recalculate the totals after we have added our fee
				 */
				$wc_order->calculate_totals();
			}
		}

		$config = $this->get_config( $customer_country );

		/**
		 * Convert our WooCommerce order to Svea
		 */
		// $svea_order = WC_Gateway_Svea_Helper::create_svea_subscription_order( $wc_order, $config );
		// Use same helper function for both subscription payments and regular ones
		$svea_order = WC_Gateway_Svea_Helper::create_svea_order( $wc_order, $config );

		$svea_order
			->setClientOrderNumber( apply_filters( 'woocommerce_sveawebpay_invoice_client_order_number', $wc_order->get_order_number() ) )
			->setCurrency( get_woocommerce_currency() )
			->setCountryCode( $customer_country )
			->setOrderDate( date( 'c' ) );

		/**
		 * Ensure that data is fetched from both the old and the new system
		 */
		$svea_data = [
			'customer_type'    => get_post_meta( $subscription->get_id(), '_svea_iv_billing_customer_type', true ),
			'address_selector' => get_post_meta( $subscription->get_id(), '_svea_address_selector', true ),
			'org_number'       => get_post_meta( $subscription->get_id(), '_svea_iv_billing_org_number', true ),
			'initials'         => get_post_meta( $subscription->get_id(), '_svea_iv_billing_initials', true ),
			'ssn'              => get_post_meta( $subscription->get_id(), '_svea_iv_billing_ssn', true ),
			'vat_number'       => get_post_meta( $subscription->get_id(), '_svea_iv_billing_vat_number', true ),
			'birth_date_year'  => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_year', true ),
			'birth_date_month' => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_month', true ),
			'birth_date_day'   => get_post_meta( $subscription->get_id(), '_svea_iv_birth_date_day', true ),
		];

		switch ( strtoupper( $customer_country ) ) {
			case 'SE':
			case 'DK':
			case 'NO':
			case 'FI':
				if ( $svea_data['customer_type'] === 'company' ) {
					$customer_information = WebPayItem::companyCustomer()
						->setNationalIdNumber( $svea_data['org_number'] );

					if ( $svea_data['address_selector']
						&& strlen( (string) $svea_data['address_selector'] ) > 0 ) {
						$customer_information->setAddressSelector( $svea_data['address_selector'] );
					}
				} else if ( $svea_data['customer_type'] === 'individual' ) {
					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setNationalIdNumber( $svea_data['ssn'] );
				}

				$customer_information->setStreetAddress( $customer_address_1 );
				break;
			case 'NL':
				$exploded_zip_code = str_split( $customer_zip_code );
				$customer_zip_code = '';
				$lastChar = false;
				foreach ( $exploded_zip_code as $char ) {
					if ( is_numeric( $lastChar ) && ! is_numeric( $char ) )
						$customer_zip_code .= ' ' . $char;
					else $customer_zip_code .= $char;
					$lastChar = $char;
				}

				if ( $svea_data['customer_type'] === 'company' ) {
					$customer_information = WebPayItem::companyCustomer()
						->setVatNumber( $svea_data['vat_number'] )
						->setCompanyName( $customer_company );
				} else if ( $svea_data['customer_type'] === 'individual' ) {
					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setInitials( $svea_data['initials'] )
						->setBirthDate(
							intval( $svea_data['birth_date_year'] ),
							intval( $svea_data['birth_date_month'] ),
							intval( $svea_data['birth_date_day'] )
						);
				}

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );

				break;
			case 'DE':
				if ( $svea_data['customer_type'] === 'company' ) {
					$customer_information = WebPayItem::companyCustomer()
						->setCompanyName( $customer_company )
						->setVatNumber( $svea_data['vat_number'] );

				} else if ( $svea_data['customer_type'] === 'individual' ) {
					$customer_information = WebPayItem::individualCustomer()
						->setName( $customer_first_name, $customer_last_name )
						->setBirthDate(
							intval( $svea_data['birth_date_year'] ),
							intval( $svea_data['birth_date_month'] ),
							intval( $svea_data['birth_date_day'] )
						);
				}

				$svea_address = Svea\WebPay\Helper\Helper::splitStreetAddress( $customer_address_1 );

				$customer_information->setStreetAddress( $svea_address[1], $svea_address[2] );
				break;
		}

		if ( $svea_data['customer_type']
			&& ! $this->same_shipping_as_billing && strlen( $wc_order->get_shipping_first_name() ) > 0
			&& strlen( $wc_order->get_shipping_last_name() ) > 0 ) {
			$customer_reference = $wc_order->get_shipping_first_name() . ' ' . $wc_order->get_shipping_last_name();

			if ( function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $customer_reference ) > 32 ) {
					$customer_reference = mb_substr( $customer_reference, 0, 29 ) . '...';
				}
			} else if ( strlen( $customer_reference ) > 32 ) {
				$customer_reference = substr( $customer_reference, 0, 29 ) . '...';
			}

			$svea_order->setCustomerReference( $customer_reference );
		}

		$customer_information
			->setZipCode( $customer_zip_code )
			->setLocality( $customer_city )
			->setIpAddress( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' )
			->setEmail( $customer_email )
			->setPhoneNumber( $customer_phone )
			->setCoAddress( $customer_address_2 );

		$svea_order->addCustomerDetails( $customer_information );

		try {
			$response = $svea_order->useInvoicePayment()->doRequest();
		} catch ( Exception $e ) {
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $wc_order );

			$wc_order->update_status( 'failed' );

			self::log( 'Error: ' . $e->getMessage() );

			$wc_order->add_order_note( __( 'Error occurred whilst processing subscription:', 'svea-webpay-for-woocommerce' ) . ' ' . $e->getMessage() );
			return;
		}

		/**
		 * See if the response was accepted and successful
		 */
		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {

			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $wc_order );

			$wc_order->update_status( 'failed' );

			self::log( 'Payment failed' );

			if ( isset( $response->resultcode ) ) {
				$wc_order->add_order_note(
					sprintf(
						// translators: %s is the error that occurred when processing the subscription
						__( 'Error occurred whilst processing subscription: %s', 'svea-webpay-for-woocommerce' ),
						WC_Gateway_Svea_Helper::get_svea_error_message( $response->resultcode )
					)
				);
			} else {
				$wc_order->add_order_note(
					sprintf(
						// translators: %s is the error that occurred when processing the subscription
						__( 'Error occurred whilst processing subscription: %s', 'svea-webpay-for-woocommerce' ),
						__( 'An unknown error occurred. Please contact the store owner about this issue.', 'svea-webpay-for-woocommerce' )
					)
				);
			}

			return;
		}

		/**
		 * Retrieve Svea's order id, we will use this to track
		 * and administrate this order in the future
		 */
		$svea_order_id = $response->sveaOrderId;

		WC_Subscriptions_Manager::process_subscription_payments_on_order( $wc_order );

		/**
		 * Save Svea's order id on the newly created subscription order
		 * so that we can administrate it in the future
		 */
		update_post_meta( $wc_order->get_id(), '_svea_order_id', $svea_order_id );
		$wc_order->payment_complete( $svea_order_id );

		self::log( 'Payment successful' );
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
					->cancelInvoiceOrder()
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
	 * Credits the order in svea
	 *
	 * @param   WC_Order    $order  the order being credited
	 * @param   string      $svea_order_id  id of the svea order
	 * @return  array       an array containing result and message
	 */
	public function credit_order( $order, $svea_order_id ) {
		$config = $this->get_config( $order->get_billing_country() );

		$response = WebPayAdmin::queryOrder( $config )
			->setOrderId( $svea_order_id )
			->setCountryCode( $order->get_billing_country() )
			->queryInvoiceOrder()
			->doRequest();

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return [
				'success' => false,
				'message' => $response->errormessage,
			];
		}

		$numbered_order_rows = $response->numberedOrderRows;

		$row_numbers = [];

		foreach ( $numbered_order_rows as $row ) {
			$row_numbers[] = $row->rowNumber;
		}

		$invoice_ids = [];

		foreach ( $numbered_order_rows as $numbered_order_row ) {
			if ( is_null( $numbered_order_row->invoiceId ) ) {
				return [
					'success' => false,
					'message' => __( 'An invoice could not be found, deliver the order first', 'svea-webpay-for-woocommerce' ),
				];
			}

			if ( ! isset( $invoice_ids[ $numbered_order_row->invoiceId ] ) ) {
				$invoice_ids[ $numbered_order_row->invoiceId ] = [
					'row_numbers'         => [],
					'numbered_order_rows' => [],
				];
			}

			$invoice_ids[ $numbered_order_row->invoiceId ]['row_numbers'][] = $numbered_order_row->rowNumber;
			$invoice_ids[ $numbered_order_row->invoiceId ]['numbered_order_rows'][] = $numbered_order_row;
		}

		foreach ( $invoice_ids as $invoice_id => $data ) {
			$response = WebPayAdmin::creditOrderRows( $config )
				->setCountryCode( $order->get_billing_country() )
				->setRowsToCredit( $data['row_numbers'] )
				->addNumberedOrderRows( $data['numbered_order_rows'] )
				->setInvoiceId( $invoice_id )
				->setInvoiceDistributionType( $this->get_distribution_type( $order->get_billing_country() ) )
				->creditInvoiceOrderRows()
				->doRequest();

			if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
				return [
					'success' => false,
					'message' => $response->errormessage,
				];
			}
		}

		foreach ( array_keys( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) ) as $order_item_id ) {
			if ( wc_get_order_item_meta( $order_item_id, 'svea_credited' ) ) {
				continue;
			}

			wc_add_order_item_meta( $order_item_id, 'svea_credited', date( 'Y-m-d H:i:s' ) );
		}

		/**
		 * The request was successful
		 */
		$number_of_rows = count( $row_numbers );

		$order->update_status( 'refunded' );

		if ( $number_of_rows === 1 ) {
			$order->add_order_note(
				sprintf(
					// translators: %s is the number of items
					__( '%d item has been credited in Svea.', 'svea-webpay-for-woocommerce' ),
					$number_of_rows
				)
			);

			return [
				'success' => true,
				'message' => sprintf(
					// translators: %s is the number of items
					__( '%d item has been credited in Svea.', 'svea-webpay-for-woocommerce' ),
					$number_of_rows
				),
			];
		} else {
			$order->add_order_note(
				sprintf(
					// translators: %s is the number of items
					__( '%d items have been credited in Svea.', 'svea-webpay-for-woocommerce' ),
					$number_of_rows
				)
			);

			return [
				'success' => true,
				'message' => sprintf(
					// translators: %s is the number of items
					__( '%d items have been credited in Svea.', 'svea-webpay-for-woocommerce' ),
					$number_of_rows
				),
			];
		}
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

		$order_id = ! empty( $_GET['order-id'] ) ? intval( $_GET['order-id'] ) : '';
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

	/**
	 * Delivers the order in svea
	 *
	 * @param   WC_Order    $order  the order being delivered
	 * @param   string      $svea_order_id  id of the svea order
	 * @param   array       $order_item_ids     an optional array of order item ids
	 *
	 * @return  array       an array containing result and message
	 */
	public function deliver_order( $order, $svea_order_id, $order_item_ids = [] ) {
		$config = $this->get_config( $order->get_billing_country() );

		if ( count( $order_item_ids ) > 0 ) {
			$response = WebPayAdmin::queryOrder( $config )
				->setOrderId( $svea_order_id )
				->setCountryCode( $order->get_billing_country() )
				->queryInvoiceOrder()
				->doRequest();

			if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
				return [
					'success' => false,
					'message' => $response->errormessage,
				];
			}

			$numbered_order_rows = $response->numberedOrderRows;

			$order_items = $order->get_items( [ 'line_item', 'fee', 'shipping' ] );

			$filtered_order_rows = [];

			foreach ( $order_items as $id => $item ) {
				if ( wc_get_order_item_meta( $id, 'svea_credited' ) || ! in_array( $id, $order_item_ids ) ) {
					continue;
				}

				$article_number = false;

				if ( $item->get_type() === 'line_item' ) {
					$product = $item->get_product();

					if ( $product->exists() && $product->get_sku() ) {
						$article_number = $product->get_sku();
					} else {
						$article_number = $product->get_id();
					}
				} else if ( $item->get_type() === 'shipping' ) {
					$article_number = $item->get_method_id();
				} else if ( $item->get_type() === 'fee' ) {
					$article_number = sanitize_title( $item->get_name() );
				}

				foreach ( $numbered_order_rows as $order_row ) {
					if ( $order_row->articleNumber === $article_number ) {
						$filtered_order_rows[] = $order_row;
						break;
					}
				}
			}

			$numbered_order_rows = $filtered_order_rows;

			if ( count( $numbered_order_rows ) === 0 ) {
				return [
					'success' => false,
					'message' => __( 'There are no order rows to deliver', 'svea-webpay-for-woocommerce' ),
				];
			}

			$row_numbers = [];

			foreach ( $numbered_order_rows as $row ) {
				$row_numbers[] = $row->rowNumber;
			}

			$response = WebPayAdmin::deliverOrderRows( $config )
						->setOrderId( $svea_order_id )
						->setCountryCode( $order->get_billing_country() )
						->setInvoiceDistributionType( $this->get_distribution_type( $order->get_billing_country() ) )
						->setRowsToDeliver( $row_numbers )
						->addNumberedOrderRows( $numbered_order_rows )
						->deliverInvoiceOrderRows()
						->doRequest();
		} else {
			$response = WebPay::deliverOrder( $config )
							->setOrderId( $svea_order_id )
							->setCountryCode( $order->get_billing_country() )
							->setInvoiceDistributionType( $this->get_distribution_type( $order->get_billing_country() ) )
							->deliverInvoiceOrder()
							->doRequest();
		}

		if ( ! $response || ! isset( $response->accepted ) || ! $response->accepted ) {
			return [
				'success' => false,
				'message' => $response->errormessage,
			];
		}

		if ( count( $order_item_ids ) > 0 ) {
			foreach ( $order_item_ids as $order_item_id ) {
				if ( wc_get_order_item_meta( $order_item_id, 'svea_delivered' ) )
					continue;
				wc_add_order_item_meta( $order_item_id, 'svea_delivered', date( 'Y-m-d H:i:s' ) );
			}

			$order_items = $order->get_items( [ 'line_item', 'fee', 'shipping' ] );

			$order_items_delivered = 0;

			foreach ( $order_items as $order_item_id => $order_item ) {
				if ( wc_get_order_item_meta( $order_item_id, 'svea_delivered' ) )
					++$order_items_delivered;
			}

			if ( $order_items_delivered === count( $order_items ) ) {
				$order->update_status( 'completed' );
			}

			/**
			 * The request was successful
			 */
			$number_of_rows = count( $row_numbers );

			if ( $number_of_rows === 1 ) {
				$order->add_order_note(
					sprintf(
						// translators: %s is the number of items
						__( '%d item has been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
						$number_of_rows
					)
				);

				return [
					'success' => true,
					'message' => sprintf(
						// translators: %s is the number of items
						__( '%d item has been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
						$number_of_rows
					),
				];
			} else {
				$order->add_order_note(
					sprintf(
						// translators: %s is the number of items
						__( '%d items have been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
						$number_of_rows
					)
				);

				return [
					'success' => true,
					'message' => sprintf(
						// translators: %s is the number of items
						__( '%d items have been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
						$number_of_rows
					),
				];
			}
		} else {
			foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $order_item_id => $order_item ) {
				wc_add_order_item_meta( $order_item_id, 'svea_delivered', date( 'Y-m-d H:i:s' ) );
			}

			$order->update_status( 'completed' );

			$order->add_order_note(
				__( 'All items have been delivered in Svea.', 'svea-webpay-for-woocommerce' )
			);

			return [
				'success' => true,
				'message' => __( 'All items have been delivered in Svea.', 'svea-webpay-for-woocommerce' ),
			];
		}
	}

	/**
	 * Displays a thank you message for paying with SveaWebPay Invoice Payment on the receipt page.
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function receipt_page( $order ) {
		echo '<p>' . esc_html__( 'Thank you for paying with SveaWebPay Invoice Payment', 'svea-webpay-for-woocommerce' ) . '</p>';
	}
}
