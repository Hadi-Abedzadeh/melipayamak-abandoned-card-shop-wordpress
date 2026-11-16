<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MCAR_DB {
	public static function table() { global $wpdb; return $wpdb->prefix . 'mcar_jobs'; }
	public static function logs_table() { global $wpdb; return $wpdb->prefix . 'mcar_logs'; }
	public static function touch_table() { global $wpdb; return $wpdb->prefix . 'mcar_touchpoints'; }
	public static function recoveries_table() { global $wpdb; return $wpdb->prefix . 'mcar_recoveries'; }

	public static function defaults() {
		return array(
			'enabled' => '0', 'username' => '', 'password' => '', 'sender' => '',
			'delay' => 30, 'second_delay' => 60,
			'message_first' => "سلام {name} عزیز، خرید شما در {shop_name} هنوز تکمیل نشده است. برای پرداخت، به فروشگاه مراجعه کنید.",
			'message_second' => "سلام {name} عزیز، برای تکمیل خریدتان در {shop_name} کد {coupon_code} با تخفیف {discount_amount} فعال شد. این کد فقط ۲ ساعت اعتبار دارد.",
			'mode_first' => 'plain', 'mode_second' => 'plain',
			'pattern_first_id' => '', 'pattern_second_id' => '',
			'pattern_first_preview' => '', 'pattern_second_preview' => '',
			'pattern_first_vars' => 'name,shop_name',
			'pattern_second_vars' => 'name,discount_amount,coupon_code',
			'coupon_type' => 'percent', 'coupon_amount' => '10',
			'include_on_hold' => '0', 'blocked_numbers' => '',
			'phone_meta_key' => '', 'phone_login_fallback' => '1',
			'history_enabled' => '1', 'history_days' => 30,
			'max_per_run' => 10, 'attribution_days' => 90,
		);
	}

	public static function settings() {
		$options = get_option( 'mcar_settings', array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$table = self::table();
		$logs = self::logs_table();
		$touches = self::touch_table();
		$recoveries = self::recoveries_table();
		// UTC timestamps throughout; one job = one shopping episode, two stages.
		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_key varchar(120) NOT NULL,
			kind varchar(10) NOT NULL,
			session_hash varchar(64) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_historical tinyint(1) unsigned NOT NULL DEFAULT 0,
			phone varchar(20) NOT NULL DEFAULT '',
			customer_name varchar(100) NOT NULL DEFAULT '',
			cart_hash varchar(64) NOT NULL DEFAULT '',
			total decimal(20,2) NOT NULL DEFAULT 0,
			currency varchar(10) NOT NULL DEFAULT '',
			stage tinyint(3) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			coupon_code varchar(50) NOT NULL DEFAULT '',
			coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
			coupon_expires_at datetime DEFAULT NULL,
			provider_id_first varchar(40) NOT NULL DEFAULT '',
			provider_id_second varchar(40) NOT NULL DEFAULT '',
			due_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			first_sent_at datetime DEFAULT NULL,
			second_sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_key (source_key),
			KEY due_status (status,due_at),
			KEY session_hash (session_hash),
			KEY order_id (order_id),
			KEY phone_stage (phone,stage),
			KEY historical_due (is_historical,status,due_at)
		) $collate;";
		$sql2 = "CREATE TABLE $logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			level varchar(12) NOT NULL,
			event varchar(60) NOT NULL,
			details text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY job_id (job_id)
		) $collate;";
		// Keep product snapshots beyond 45-day operational log cleanup for later purchases.
		$sql3 = "CREATE TABLE $touches (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			phone_hash char(64) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_ids longtext NOT NULL,
			first_sent_at datetime DEFAULT NULL,
			second_sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY phone_first (phone_hash,first_sent_at),
			KEY customer_first (customer_id,first_sent_at)
		) $collate;";
		$sql4 = "CREATE TABLE $recoveries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recovered_order_id bigint(20) unsigned NOT NULL,
			job_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage tinyint(3) unsigned NOT NULL DEFAULT 1,
			matched_product_ids longtext NOT NULL,
			matched_revenue decimal(20,2) NOT NULL DEFAULT 0,
			order_total decimal(20,2) NOT NULL DEFAULT 0,
			currency varchar(10) NOT NULL DEFAULT '',
			coupon_used tinyint(1) unsigned NOT NULL DEFAULT 0,
			paid_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recovered_order_id (recovered_order_id),
			UNIQUE KEY job_id (job_id),
			KEY paid_at (paid_at)
		) $collate;";
		dbDelta( $sql );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
		if ( false === get_option( 'mcar_settings', false ) ) { add_option( 'mcar_settings', self::defaults(), '', false ); }
		// Retain original tracking start for ordinary live-order behaviour.
		// Historical unpaid orders are scanned separately and only in bounded batches.
		add_option( 'mcar_tracking_started_at', time(), '', false );
		// Prior releases inserted phone-less carts into the queue. Remove those only.
		$purged = $wpdb->query( "DELETE FROM $table WHERE status='awaiting_phone' OR (status='active' AND (phone='' OR phone IS NULL))" );
		if ( is_int( $purged ) && $purged > 0 ) {
			self::log( 'empty_phone_purged', 'رکوردهای بدون موبایل معتبر هنگام ارتقا از صف حذف شدند: ' . $purged );
		}
		update_option( 'mcar_db_version', MCAR_VERSION, false );
		self::ensure_schedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'mcar_db_version' ) !== MCAR_VERSION ) { self::install(); }
	}

	public static function ensure_schedule() {
		// Upgrade the existing schedule in place. Never install a second cron event.
		$event = wp_get_scheduled_event( 'mcar_scan_queue' );
		if ( $event && 'mcar_one_minute' !== $event->schedule ) {
			wp_clear_scheduled_hook( 'mcar_scan_queue' );
			$event = false;
		}
		if ( ! $event && ! wp_next_scheduled( 'mcar_scan_queue' ) ) {
			wp_schedule_event( time() + 60, 'mcar_one_minute', 'mcar_scan_queue' );
		}
		if ( ! wp_next_scheduled( 'mcar_reconcile_reports' ) ) {
			wp_schedule_event( time() + 120, 'mcar_ten_minutes', 'mcar_reconcile_reports' );
		}
	}
	public static function deactivate() { wp_clear_scheduled_hook( 'mcar_scan_queue' ); wp_clear_scheduled_hook( 'mcar_reconcile_reports' ); }

	public static function log( $event, $details = '', $level = 'info', $job_id = 0 ) {
		global $wpdb;
		// No full phone numbers, complete SMS texts, discount codes or passwords in logs.
		$wpdb->insert( self::logs_table(), array(
			'job_id' => absint( $job_id ), 'level' => substr( sanitize_key( $level ), 0, 12 ),
			'event' => substr( sanitize_key( $event ), 0, 60 ),
			'details' => sanitize_text_field( (string) $details ),
			'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s' ) );
	}

	public static function cleanup() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::logs_table() . ' WHERE created_at < %s', $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE updated_at < %s AND status NOT IN ('active','processing')", $cutoff ) );
		// Store only hashed recipient identity; do not retain conversion data indefinitely.
		$privacy_cutoff = gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::recoveries_table() . ' WHERE paid_at < %s', $privacy_cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::touch_table() . ' WHERE created_at < %s', $privacy_cutoff ) );
	}
	public static function mask_phone( $phone ) {
		return strlen( (string) $phone ) >= 7 ? substr( $phone, 0, 4 ) . '***' . substr( $phone, -3 ) : '***';
	}
}
