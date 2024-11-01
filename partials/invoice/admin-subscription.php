<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="svea-fields svea-invoice-fields svea-fields-admin">
	<div class="customer-type-container">
		<?php
			woocommerce_form_field(
				'_iv_billing_customer_type',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'options'  => [
						'false'      => __( '- Choose customer type -', 'svea-webpay-for-woocommerce' ),
						'company'    => __( 'Company', 'svea-webpay-for-woocommerce' ),
						'individual' => __( 'Individual', 'svea-webpay-for-woocommerce' ),
					],
					'label'    => __( 'Customer Type', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				],
				isset( $post_data['iv_billing_customer_type'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_billing_customer_type'] ) ) : false
			);
			?>
	</div>
	<div class="organisation-number-container">
		<?php
			woocommerce_form_field(
				'_iv_billing_org_number',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Organisation number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				],
				isset( $post_data['iv_billing_org_number'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_billing_org_number'] ) ) : null
			);
			?>
	</div>
	<div class="personal-number-container">
		<?php
			woocommerce_form_field(
				'_iv_billing_ssn',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Personal number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				],
				isset( $post_data['iv_billing_ssn'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_billing_ssn'] ) ) : null
			);
			?>
	</div>
	<div class="svea-get-address-button-container">
		<a class="svea-get-address-button" href="#"><?php esc_html_e( 'Get address', 'svea-webpay-for-woocommerce' ); ?></a>
	</div>
	<div class="org-address-selector-container">
		<p class="form-row form-row-wide">
			<select class="org-address-selector"></select>
		</p>
		<input type="hidden" name="_address_selector" class="address-selector" />
	</div>
	<div class="vat-number-container">
		<?php
			woocommerce_form_field(
				'_iv_billing_vat_number',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'VAT number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				],
				isset( $post_data['iv_billing_vat_number'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_billing_vat_number'] ) ) : null
			);
			?>
	</div>
	<div class="birth-date-container">
		<?php esc_html_e( 'Date of birth', 'svea-webpay-for-woocommerce' ); ?>
		<?php
			$current_year = intval( date( 'Y' ) );

			$years = array_combine(
				range( $current_year, $current_year - 100 ),
				range( $current_year, $current_year - 100 )
			);

			woocommerce_form_field(
				'_iv_birth_date_year',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide birth-date-year' ],
					'label'    => __( 'Year', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $years,
				],
				isset( $post_data['iv_birth_date_year'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_birth_date_year'] ) ) : null
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
				'_iv_birth_date_month',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide birth-date-month' ],
					'label'    => __( 'Month', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $months,
				],
				isset( $post_data['iv_birth_date_month'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_birth_date_month'] ) ) : null
			);

			$days = array_combine( range( 1, 31 ), range( 1, 31 ) );

			woocommerce_form_field(
				'_iv_birth_date_day',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide birth-date-day' ],
					'label'    => __( 'Day', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $days,
				],
				isset( $post_data['iv_birth_date_day'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_birth_date_day'] ) ) : null
			);
			?>
	</div>
	<div class="initials-container">
		<?php
			woocommerce_form_field(
				'_iv_billing_initials',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Initials', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				],
				isset( $post_data['iv_billing_initials'] ) ? sanitize_text_field( wp_unslash( $post_data['iv_billing_initials'] ) ) : null
			);
			?>
	</div>
</div>
