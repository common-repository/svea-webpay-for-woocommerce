<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="svea-get-address-button-container get-address-shortcode">
	<div class="customer-type-container">
		<input type="radio" class="input-radio" value="individual" name="svea_get_address_customer_type" id="svea_get_address_customer_type_individual" checked="true" />
		<label class="radio" for="svea_get_address_customer_type_individual">
			<?php esc_html_e( 'Individual', 'svea-webpay-for-woocommerce' ); ?>
		</label>
		<input type="radio" class="input-radio" value="company" name="svea_get_address_customer_type" id="svea_get_address_customer_type_company" />
		<label class="radio" for="svea_get_address_customer_type_company">
			<?php esc_html_e( 'Company', 'svea-webpay-for-woocommerce' ); ?>
		</label>
	</div>
	<div class="svea-get-address-button-inner">
		<div class="organisation-number-container">
			<label for="svea_billing_org_number">
				<?php esc_html_e( 'Organisation number', 'svea-webpay-for-woocommerce' ); ?>
			</label>
			<input type="text" class="input-text" name="svea_billing_org_number" id="svea_billing_org_number" />
			<div class="org-address-selector-container">
				<select class="org-address-selector"></select>
			</div>
		</div>
		<div class="personal-number-container">
			<label for="svea_billing_ssn">
				<?php esc_html_e( 'Personal number', 'svea-webpay-for-woocommerce' ); ?>
			</label>
			<input type="text" class="input-text" name="svea_billing_ssn" id="svea_billing_ssn" />
		</div>
		<a class="svea-get-address-button" href="#"><?php esc_html_e( 'Get address', 'svea-webpay-for-woocommerce' ); ?></a>
	</div>
</div>
