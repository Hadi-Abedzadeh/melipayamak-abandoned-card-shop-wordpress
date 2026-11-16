<?php
/**
 * Plugin Name: یادآور دو مرحله‌ای سبد رهاشده - ملی پیامک
 * Description: یادآور دو مرحله‌ای سبد رهاشده با ارسال عادی یا الگوی ملی پیامک، کوپن دو ساعته، بازیابی خودکار سفارش‌های قدیمی و صف و لاگ مستقل.
 * Version: 1.7.2
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Text Domain: meli-cart-reminder
 * Author: Independent WooCommerce Utility
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MCAR_VERSION', '1.7.2' );
define( 'MCAR_FILE', __FILE__ );
define( 'MCAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MCAR_URL', plugin_dir_url( __FILE__ ) );
require_once MCAR_PATH . 'includes/class-mcar-db.php';
require_once MCAR_PATH . 'includes/class-mcar-meli.php';
require_once MCAR_PATH . 'includes/class-mcar-core.php';
require_once MCAR_PATH . 'includes/class-mcar-attribution.php';
require_once MCAR_PATH . 'includes/class-mcar-admin.php';

register_activation_hook( __FILE__, static function () {
	add_filter( 'cron_schedules', array( 'MCAR_Core', 'cron_intervals' ) );
	MCAR_DB::install();
} );
register_deactivation_hook( __FILE__, array( 'MCAR_DB', 'deactivate' ) );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
add_action( 'plugins_loaded', static function () {
	MCAR_Admin::register();
	add_action( 'init', array( 'MCAR_DB', 'maybe_upgrade' ), 2 );
	if ( class_exists( 'WooCommerce' ) ) { MCAR_Core::register(); MCAR_Attribution::register(); }
	else {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>برای کارکرد یادآور ملی پیامک، ووکامرس باید فعال باشد.</p></div>';
			}
		} );
	}
} );
