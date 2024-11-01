<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="svea-part-payment-plans">
	<h3><?php esc_html_e( 'Part Payment Plans', 'svea-webpay-for-woocommerce' ); ?></h3>
	<?php for ( $i = 0;$i < count( $campaigns );++$i ) : ?>
		<?php $campaign = $campaigns[ $i ]; ?>
		<?php if ( $campaign->fromAmount > $total || $campaign->toAmount < $total ) continue; // phpcs:ignore ?>
		<div class="part-pay-campaign-input-container">
			<input id="part-pay-campaign-input-<?php echo esc_attr( $i ); ?>" type="radio" name="part-pay-input-<?php echo esc_attr( $customer_country ); ?>"
			value="<?php echo esc_attr( $campaign->campaignCode ); // phpcs:ignore ?>" />
			<label class="part-pay-campaign-input-label" for="part-pay-campaign-input-<?php echo esc_attr( $i ); ?>">
				<?php echo esc_html( $campaign->description ); ?>
			</label>
		</div>
	<?php endfor; ?>
</div>
