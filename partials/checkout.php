<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="svea-fields">
	<div class="part-pay-campaign">
	</div>
	<div class="svea-customer-type-container">
		<?php
			woocommerce_form_field(
				'billing_customer_type',
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
				false
			);
			?>
	</div>
	<div class="svea-organisation-number-container">
		<?php
			woocommerce_form_field(
				'billing_org_number',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Organisation number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				]
			);
			?>
	</div>
	<div class="svea-personal-number-container">
		<?php
			woocommerce_form_field(
				'billing_ssn',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Personal number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				]
			);
			?>
	</div>
	<div class="svea-get-address-button-container">
		<a class="svea-get-address-button" href="#"><?php esc_html_e( 'Get address', 'svea-webpay-for-woocommerce' ); ?></a>
	</div>
	<input type="hidden" name="address_selector" class="address-selector" />
	<div class="svea-org-address-selector-container">
		<p class="form-row form-row-wide">
			<select class="org-address-selector"></select>
		</p>
	</div>
	<div class="svea-vat-number-container">
		<?php
			woocommerce_form_field(
				'billing_vat_number',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'VAT number', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				]
			);
			?>
	</div>
	<div class="svea-birth-date-container">
		<?php esc_html_e( 'Date of birth', 'svea-webpay-for-woocommerce' ); ?>
		<?php
			$current_year = intval( date( 'Y' ) );

			$years = array_combine(
				range( $current_year, $current_year - 100 ),
				range( $current_year, $current_year - 100 )
			);

			woocommerce_form_field(
				'birth_date_year',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Year', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $years,
				]
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
				'birth_date_month',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Month', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $months,
				]
			);

			$days = array_combine( range( 1, 31 ), range( 1, 31 ) );

			woocommerce_form_field(
				'birth_date_day',
				[
					'type'     => 'select',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Day', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
					'options'  => $days,
				]
			);
			?>
	</div>
	<div class="svea-initials-container">
		<?php
			woocommerce_form_field(
				'billing_initials',
				[
					'type'     => 'text',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'label'    => __( 'Initials', 'svea-webpay-for-woocommerce' ) . ' <abbr class="required" title="required">*</abbr>',
				]
			);
			?>
	</div>
</div>
