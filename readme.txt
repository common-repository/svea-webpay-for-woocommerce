=== Svea WebPay for WooCommerce ===
Contributors: sveaekonomi, thegeneration
Tags: woocommerce, svea ekonomi, checkout, payment gateway, credit card, invoice, part payment, direct bank
Donate link: https://www.svea.com/
Requires at least: 4.9
Tested up to: 6.5.0
Requires PHP: 7.0
WC requires at least: 4.0.0
WC tested up to: 8.7.0
License: Apache 2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0
Stable tag: 3.2.1

The Svea Webpay payment module is a complete solution for shops using WordPress / WooCommerce as an e-commerce platform.

== Description ==

The Svea Webpay payment module is a complete solution for shops using WordPress / WooCommerce as an e-commerce platform.

The installation is simple and all payment methods are integrated; Invoice, Part payments, Card payments and Direct payments.

= Part payment widget =

The plugin provides a widget that can be displayed on the product pages to inform your customers that they can pay with part payments when checking out. The lowest monthly price at which they can pay through part payments will be displayed.

To activate the feature, follow these steps:

1. Go to **WooCommerce > Settings > Payments > SveaWebPay Part Payment**
2. Check the box **Display product part payment widget**
3. Select where on the page you want to display the widget
4. View the part payment widget on the product page for eligable products. If the widget is not displayed it might be due to the price since part payment plans often require a minimum amount.

== Installation ==

1. Install the plugin either through your web browser in the WordPress admin panel or manually through FTP.
2. Activate the plugin
3. With the credentials you have received from Svea, configure the payment methods and countries you will accept payments through by going to the settings pages for the payment methods under **WooCommerce > Settings > Payments**

== Upgrade Notice ==

= 3.0.0 =
3.0.0 is a major release.

== Screenshots ==

1. Checkout page showing all available payment methods
2. Part payment widget on a single product page

== Changelog ==

= 3.2.1 2024-04-05 =
* Updated version of SDK

= 3.2.0 2024-01-07 =
* Added SVEACARDPAY_PF as card payment metod

= 3.1.1 2023-10-20 =
* Corrected missing vendor packages

= 3.1.0 2023-10-19 =
* Strong authentication for Swedish invoice / part payment orders

= 3.0.5 2023-06-07 =
* Support for PHP8
* Allow simple HTML in payment description

= 3.0.4 2022-09-13 =
* Don't set firstname and lastname for business names with two words when using "get address"

= 3.0.3 2022-07-27 =
* Update supported versions

= 3.0.2 2022-01-19 =
* Corrected js-trigger when updating address information via get_address
* Removed duplicate name on variations

= 3.0.1 2021-03-04 =
* Update supported versions

= 3.0.0 2020-08-13 =
* Initial release on wordpress.org

== Frequently Asked Questions ==

= Do you support WooCommerce Subscriptions? =

Yes WooCommerce Subscriptions is fully supported for both card and invoice payments.

= Do you support WooCommerce Sequential Order Numbers? =

Yes it's supported.
