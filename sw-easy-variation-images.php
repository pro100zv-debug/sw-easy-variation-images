<?php
/**
 * Plugin Name:          SW Easy Variation Images
 * Plugin URI:           https://github.com/pro100zv-debug/sw-easy-variation-images
 * Description:          Set one image per attribute value (for example, per color) and every matching WooCommerce variation gets it. Works with any theme; supports variation galleries of WooCommerce, WoodMart and Additional Variation Images Gallery.
 * Version:              1.3.2
 * Author:               SergioWeb
 * Author URI:           https://sergioweb.es/
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      11.3
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          sw-easy-variation-images
 * Domain Path:          /languages
 * Update URI:           https://github.com/pro100zv-debug/sw-easy-variation-images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SW_EVI_VERSION', '1.3.2' );
define( 'SW_EVI_FILE', __FILE__ );
define( 'SW_EVI_DIR', __DIR__ );

/* ---------- Updates from GitHub ---------- */

// Only in the admin and in WP-Cron: never on the storefront.
if ( ( is_admin() || wp_doing_cron() ) && is_readable( __DIR__ . '/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

	$sw_evi_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/pro100zv-debug/sw-easy-variation-images/',
		__FILE__,
		'sw-easy-variation-images'
	);
	$sw_evi_updater->setBranch( 'main' );
	// Only releases that already have the zip attached (built by GitHub Actions).
	$sw_evi_updater->getVcsApi()->enableReleaseAssets(
		'/sw-easy-variation-images\.zip($|[?&#])/i',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
}

/* ---------- WooCommerce feature compatibility ---------- */

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/* ---------- Translations ---------- */

/**
 * Bundled translations. Any regional variant (es_MX, es_AR, ru_UA…)
 * falls back to the base file of its language. English is the default.
 */
function sw_evi_mofile_fallback( $mofile, $domain ) {
	if ( 'sw-easy-variation-images' !== $domain || is_readable( $mofile ) ) {
		return $mofile;
	}
	$bundled_langs = array(
		'es' => 'es_ES',
		'ru' => 'ru_RU',
	);
	if ( preg_match( '/sw-easy-variation-images-([a-z]{2,3})(?:_[A-Za-z0-9_]+)?\.mo$/', $mofile, $m ) ) {
		$lang = strtolower( $m[1] );
		if ( isset( $bundled_langs[ $lang ] ) ) {
			$bundled = __DIR__ . '/languages/sw-easy-variation-images-' . $bundled_langs[ $lang ] . '.mo';
			if ( is_readable( $bundled ) ) {
				return $bundled;
			}
		}
	}
	return $mofile;
}
add_filter( 'load_textdomain_mofile', 'sw_evi_mofile_fallback', 10, 2 );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'sw-easy-variation-images', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/* ---------- Boot ---------- */

require_once __DIR__ . '/includes/class-sw-evi-core.php';
require_once __DIR__ . '/includes/class-sw-evi-admin.php';

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		SW_EVI_Core::init();
		if ( is_admin() ) {
			SW_EVI_Admin::init();
		}
	}
);
