=== Restrict Users for WooCommerce ===
Plugin Name: Restrict Users for WooCommerce
Contributors: Ben Dishler, CBT Hospitality Supplies
Author URI: https://bendishler.com
Tags: woocommerce, checkout, customer restrictions
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Text Domain: restrict-users-for-woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restrict selected WooCommerce customers from checkout while preserving browsing and cart access.

== Description ==

Administrators can enable checkout restrictions, enter one or more WordPress user IDs, and customize the Checkout and My Account notices. The restricted-user list shows each user's ID, company name, username, and email address.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open WooCommerce > Restrict Users to configure it.

== Changelog ==

= 1.0.10 =
* Make action-specific nonce verification explicit in management form handlers.
* Add translator comments for user-count and invalid-ID placeholders.
* Rename the plugin to Restrict Users for WooCommerce and update Tested up to to 7.1.

= 1.0.9 =
* Improve WordPress dependency metadata, request scoping, and Store API route handling.

= 1.0.8 =
* Block both classic and Checkout Block orders for restricted users and place notices on Checkout and My Account as configured.

= 1.0.7 =
* Store restricted user IDs independently from general settings and prevent duplicate restrictions.

= 1.0.6 =
* Show only active restricted users and automatically remove stale user IDs from the restriction setting.

= 1.0.5 =
* Make the enable toggle persist immediately and ensure every stored user ID remains visible and removable.

= 1.0.4 =
* Process user-ID additions and removals directly on the settings screen and show reliable administrator feedback.

= 1.0.3 =
* Replace user-ID management AJAX with reliable native WordPress form submissions.

= 1.0.2 =
* Replace customer search with direct user ID entry and an editable restricted-user list.

= 1.0.1 =
* Improve customer search reliability.

= 1.0.0 =
* Initial release.
