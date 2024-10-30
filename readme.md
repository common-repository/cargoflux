=== Cargoflux ===

Contributors: cargoflux

Tags: shipping, integration

Requires at least: 6.3

Tested up to: 6.3

Stable tag: 1.3.4

Requires PHP: 7.0

License: GPLv2 or later

License URI: http://www.gnu.org/licenses/gpl-2.0.html

Cargoflux shipping integration for WooCommerce

== Description ==
With Cargoflux you only need a single platform to handle all your shipping needs. Our WooCommerce integration allows you to easily provide your shipping rates directly in your webshop. All you need to do is provide your API key to Cargoflux and choose which methods you want to use.

Cargoflux supports a long range of carriers, including but not limited to:
- GLS
- PostNord
- DHL
- DSV
- FedEx
- UPS
- Many more

This plugin currently features:
- Book shipping rates through your Cargoflux account
- Allow customers to select between up to 3 nearest parcelshops for relevant products
- Charge customers for the exact cost of the shipment or provide your own flat price
- Set a thresholds for free shipping
- Use packing classes to control the packaging of items separately or together so you always get the correct price

Don't have a Cargoflux account already? Visit us on https://cargoflux.com or get in direct touch with us on info@cargoflux.com.

== Notice on 3rd party services ==
This plugin relies completely on its interaction with the external Cargoflux web service. See https://cargoflux.com/

In particular, the Cargoflux service is used to:
- Fetch available shipping products for your account
- Provide price quotes for particular order shipments
- Book shipments for orders - potentially with 3rd party transport providers
- Provide shipping labels and tracking information for particular order shipments

Please refer to Cargoflux's terms of use and privacy statements here https://cargoflux.com/terms_and_privacy

== Installation ==
This section describes how to install the plugin and get it working.
1. Upload cargoflux.zip to the wordpress plugins directory

2. Activate the plugin through the 'Plugins' menu in WordPress

3. Configure the "Cargoflux Rates" shipping method in the WooCommerce shipment section.
== Frequently Asked Questions ==
= Do you need an account with Cargoflux to use this plugin? =
Yes, the plugin requires your unique API key tied to your Cargoflux account. Contact us for more information.

== Changelog ==
= v1.0.0 =

* Initial release

= v1.0.1 =

* Return costs in float

= v1.1.0 =

* Allow disabling currency conversion from WPML plugin

= v1.2.0 =

* Configure zone specific rates

= v1.3.0 =

* Improve configuration layout
* Ignore products with no product code

= v1.3.1 =

* Minor fixes regarding file paths

= v1.3.2 =

* Fix issue causing items with no packing group to be packaged separately

= v1.3.3 =

 * Fix stable tag
 * Apply prefixes to class and function declarations to prevent name collisions
 * Add protection against direct access

= v1.3.4

 * Add section regarding use of 3rd party services to readme
