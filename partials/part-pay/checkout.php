<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="svea-fields svea-part-pay-fields">
	<?php
	if ( $country === 'SE' || $country === 'DK'
			|| $country === 'FI' || $country === 'NO' ) :
		?>
	<div class="personal-number-container">
		<?php
			$should_hide_ssn = ( $country === 'SE' || $country === 'DK' || $country === 'NO' ) && WC_SveaWebPay_Gateway_Shortcodes::is_using_get_address_shortcode();

		if ( $should_hide_ssn ) :
			?>
			<input type="hidden" value="<?php echo isset( $post_data['pp_billing_ssn'] ) ? esc_attr( $post_data['pp_billing_ssn'] ) : ''; ?>"
				name="pp_billing_ssn" />
			<?php
			else :
				woocommerce_form_field(
					'pp_billing_ssn',
					[
						'type'     => ( $should_hide_ssn ? 'hidden' : 'text' ),
						'required' => true,
						'class'    => [ 'form-row-wide' ],
						'label'    => __( 'Personal number', 'svea-webpay-for-woocommerce' ),
					],
					isset( $post_data['pp_billing_ssn'] ) ? $post_data['pp_billing_ssn'] : false
				);
			endif;
			?>
	</div>
		<?php
	endif;

	$should_hide_get_address = ( $country === 'SE' || $country === 'DK' ) && WC_SveaWebPay_Gateway_Shortcodes::is_using_get_address_shortcode();

	if ( ! $should_hide_get_address ) :
		?>
	<div class="svea-get-address-button-container">
		<a class="svea-get-address-button" href="#"><?php esc_html_e( 'Get address', 'svea-webpay-for-woocommerce' ); ?></a>
	</div>
	<?php endif; ?>
	<?php if ( $country === 'NL' || $country === 'DE' ) : ?>
	<div class="birth-date-container">
		<?php esc_html_e( 'Date of birth', 'svea-webpay-for-woocommerce' ); ?>
		<?php
			$current_year = intval( date( 'Y' ) );

			$years = array_combine(
				range( $current_year, $current_year - 100 ),
				range( $current_year, $current_year - 100 )
			);

			woocommerce_form_field(
				'pp_birth_date_year',
				[
					'type'     => 'select',
					'required' => true,
					'class'    => [ 'form-row-wide birth-date-year' ],
					'label'    => __( 'Year', 'svea-webpay-for-woocommerce' ),
					'options'  => $years,
				],
				isset( $post_data['pp_birth_date_year'] ) ? $post_data['pp_birth_date_year'] : false
			);

			$months = [
				'1'  => __( 'January', 'svea-webpay-for-woocommerce' ),
				'2'  => __( 'February', 'svea-webpay-for-woocommerce' ),
				'3'  => __( 'Mars', 'svea-webpay-for-woocommerce' ),
				'4'  => __( 'April', 'svea-webpay-for-woocommerce' ),
				'5'  => __( 'May', 'svea-webpay-for-woocommerce' ),
				'6'  => __( 'June', 'svea-webpay-for-woocommerce' ),
				'7'  => __( 'July', 'svea-webpay-for-woocommerce' ),
				'8'  => __( 'August', 'svea-webpay-for-woocommerce' ),
				'9'  => __( 'September', 'svea-webpay-for-woocommerce' ),
				'10' => __( 'October', 'svea-webpay-for-woocommerce' ),
				'11' => __( 'November', 'svea-webpay-for-woocommerce' ),
				'12' => __( 'December', 'svea-webpay-for-woocommerce' ),
			];

			woocommerce_form_field(
				'pp_birth_date_month',
				[
					'type'     => 'select',
					'required' => true,
					'class'    => [ 'form-row-wide birth-date-month' ],
					'label'    => __( 'Month', 'svea-webpay-for-woocommerce' ),
					'options'  => $months,
				],
				isset( $post_data['pp_birth_date_month'] ) ? $post_data['pp_birth_date_month'] : false
			);

			$days = array_combine( range( 1, 31 ), range( 1, 31 ) );

			woocommerce_form_field(
				'pp_birth_date_day',
				[
					'type'     => 'select',
					'required' => true,
					'class'    => [ 'form-row-wide birth-date-day' ],
					'label'    => __( 'Day', 'svea-webpay-for-woocommerce' ),
					'options'  => $days,
				],
				isset( $post_data['pp_birth_date_day'] ) ? $post_data['pp_birth_date_day'] : false
			);
		?>
	</div>
	<?php endif; ?>
	<?php if ( $country === 'NL' ) : ?>
	<div class="initials-container">
		<?php
			woocommerce_form_field(
				'pp_billing_initials',
				[
					'type'     => 'text',
					'required' => true,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Initials', 'svea-webpay-for-woocommerce' ),
				],
				isset( $post_data['pp_billing_initials'] ) ? $post_data['pp_billing_initials'] : false
			);
		?>
	</div>
	<?php endif; ?>
</div>
