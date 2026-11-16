<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MCAR_Core {
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_intervals' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 );
		add_action( 'mcar_scan_queue', array( __CLASS__, 'process_queue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout' ) );
		add_action( 'wp_ajax_mcar_capture', array( __CLASS__, 'capture_request' ) );
		add_action( 'wp_ajax_nopriv_mcar_capture', array( __CLASS__, 'capture_request' ) );
		add_action( 'wp_ajax_mcar_diagnose_cart', array( __CLASS__, 'diagnose_cart_request' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'touch_cart' ), 20 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'touch_cart' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'touch_cart' ), 20 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'touch_cart' ), 20 );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'cart_emptied' ), 20 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'checkout_order' ), 30, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'checkout_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'checkout_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status' ), 20, 3 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_completed' ), 20, 1 );
	}
	public static function cron_intervals( $schedules ) {
		// Keep the legacy slug valid until old installs are rescheduled.
		$schedules['mcar_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'هر ۵ دقیقه (قدیمی)' );
		$schedules['mcar_one_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'هر ۱ دقیقه' );
		$schedules['mcar_ten_minutes'] = array( 'interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'هر ۱۰ دقیقه (گزارش خرید)' );
		return $schedules;
	}
	public static function ensure_cron() {
		MCAR_DB::ensure_schedule();
	}
	private static function session() {
		if ( ! function_exists( 'WC' ) ) { return null; }
		if ( ( ! WC()->session || ! WC()->cart ) && function_exists( 'wc_load_cart' ) && ( ! is_admin() || wp_doing_ajax() ) ) {
			wc_load_cart();
		}
		return WC()->session;
	}
	private static function session_hash() {
		$session = self::session();
		if ( ! $session || ! method_exists( $session, 'get_customer_id' ) ) { return ''; }
		$id = (string) $session->get_customer_id();
		return $id ? hash_hmac( 'sha256', $id, wp_salt( 'auth' ) ) : '';
	}
	private static function cart_key( $create = true ) {
		$session = self::session();
		$hash = self::session_hash();
		if ( ! $session || ! $hash ) { return ''; }
		$episode = (string) $session->get( 'mcar_episode', '' );
		if ( ! $episode && $create ) {
			$episode = wp_generate_uuid4();
			$session->set( 'mcar_episode', $episode );
		}
		return $episode ? 'cart:' . substr( $hash, 0, 48 ) . ':' . $episode : '';
	}
	private static function limit_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100, 'UTF-8' ) : substr( $name, 0, 100 );
	}

	public static function enqueue_checkout() {
		if ( '1' !== (string) MCAR_DB::settings()['enabled'] || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) { return; }
		wp_enqueue_script( 'mcar-checkout', MCAR_URL . 'assets/checkout.js', array(), MCAR_VERSION, true );
		wp_localize_script( 'mcar-checkout', 'MCARCheckout', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'mcar_checkout' ) ) );
	}

	/** An existing phone typed into classic OR block checkout is stored, without a consent checkbox. */
	public static function capture_request() {
		check_ajax_referer( 'mcar_checkout', 'nonce' );
		if ( '1' !== (string) MCAR_DB::settings()['enabled'] ) { wp_send_json_error( array( 'message' => 'غیرفعال' ), 400 ); }
		$session = self::session();
		if ( ! $session || ! WC()->cart || WC()->cart->is_empty() ) { wp_send_json_error( array( 'message' => 'سبد خالی یا نشست ناموجود' ), 422 ); }
		$phone = MCAR_Meli::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( ! $phone ) { wp_send_json_error( array( 'message' => 'شماره نامعتبر' ), 422 ); }
		$name = self::limit_name( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );
		$session->set( 'mcar_phone', $phone );
		$session->set( 'mcar_name', $name );
		self::upsert_cart( $phone, $name );
		wp_send_json_success( array( 'state' => 'captured' ) );
	}

	/** Locate contact data only from WooCommerce, the explicitly configured meta key, or a phone-shaped login. */
	private static function cart_contact( $session ) {
		$phone = $session ? MCAR_Meli::normalize_phone( (string) $session->get( 'mcar_phone', '' ) ) : '';
		$name = $session ? (string) $session->get( 'mcar_name', '' ) : '';
		$source = $phone ? 'checkout' : '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			if ( ! $phone ) {
				$phone = MCAR_Meli::normalize_phone( WC()->customer->get_billing_phone() );
				if ( $phone ) { $source = 'woocommerce_billing_phone'; }
			}
			$name = $name ?: (string) WC()->customer->get_billing_first_name();
		}
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			if ( ! $phone ) {
				$phone = MCAR_Meli::normalize_phone( get_user_meta( $user_id, 'billing_phone', true ) );
				if ( $phone ) { $source = 'user_billing_phone'; }
			}
			$custom_key = sanitize_key( (string) ( MCAR_DB::settings()['phone_meta_key'] ?? '' ) );
			if ( ! $phone && $custom_key ) {
				$phone = MCAR_Meli::normalize_phone( get_user_meta( $user_id, $custom_key, true ) );
				if ( $phone ) { $source = 'custom_meta:' . $custom_key; }
			}
			if ( ! $phone && '1' === (string) ( MCAR_DB::settings()['phone_login_fallback'] ?? '1' ) ) {
				$user = wp_get_current_user();
				$phone = MCAR_Meli::normalize_phone( $user->user_login );
				if ( $phone ) { $source = 'mobile_username'; }
			}
			$name = $name ?: (string) get_user_meta( $user_id, 'billing_first_name', true );
			$name = $name ?: (string) get_user_meta( $user_id, 'first_name', true );
		}
		return array( $phone, self::limit_name( $name ), $source );
	}

	public static function touch_cart() {
		if ( '1' !== (string) MCAR_DB::settings()['enabled'] ) { return; }
		$session = self::session();
		if ( ! $session || ! WC()->cart || WC()->cart->is_empty() ) { return; }
		list( $phone, $name ) = self::cart_contact( $session );
		self::upsert_cart( $phone, $name );
	}

	/** Queue only carts with a validated mobile number. Never create phone-less jobs. */
	private static function upsert_cart( $phone, $name ) {
		global $wpdb;
		$phone = MCAR_Meli::normalize_phone( $phone );
		if ( ! $phone ) { return; }
		$key = self::cart_key();
		$hash = self::session_hash();
		if ( ! $key || ! WC()->cart || WC()->cart->is_empty() ) { return; }
		$settings = MCAR_DB::settings();
		$now = current_time( 'mysql', true );
		$due = gmdate( 'Y-m-d H:i:s', time() + absint( $settings['delay'] ) * MINUTE_IN_SECONDS );
		$name = self::limit_name( $name );
		$cart_hash = substr( (string) WC()->cart->get_cart_hash(), 0, 64 );
		$data = array(
			'cart_hash' => $cart_hash,
			'total' => (float) WC()->cart->get_total( 'edit' ),
			'currency' => get_woocommerce_currency(),
			'updated_at' => $now,
		);
		$data['phone'] = $phone;
		if ( $name ) { $data['customer_name'] = $name; }
		$table = MCAR_DB::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,stage,kind,cart_hash,phone FROM $table WHERE source_key=%s", $key ), ARRAY_A );
		if ( $row ) {
			if ( 'cart' !== $row['kind'] || 'active' !== $row['status'] ) { return; }
			if ( 1 === (int) $row['stage'] ) {
				// Reading a page is not new activity. Reset only for real cart changes.
				if ( $cart_hash !== $row['cart_hash'] ) { $data['due_at'] = $due; }
			}
			$updated = $wpdb->update( $table, $data, array( 'id'=>(int)$row['id'] ) );
			if ( false !== $updated ) { MCAR_Attribution::capture_cart( (int)$row['id'], $phone ); }
				if ( false === $updated ) { MCAR_DB::log( 'cart_update_failed', 'به‌روزرسانی صف سبد با خطای پایگاه داده مواجه شد.', 'error', $row['id'] ); }
			return;
		}
		$ok = $wpdb->insert( $table, array_merge( array(
			'source_key' => $key, 'kind' => 'cart', 'session_hash' => $hash, 'order_id' => 0,
			'stage' => 1, 'status' => 'active',
			'phone' => $phone, 'customer_name' => $name,
			'created_at' => $now, 'due_at' => $due,
		), $data ) );
		if ( $ok ) {
			MCAR_Attribution::capture_cart( (int)$wpdb->insert_id, $phone );
				MCAR_DB::log( 'cart_captured', 'سبد دارای موبایل معتبر وارد صف شد.', 'info', $wpdb->insert_id );
		} else {
			MCAR_DB::log( 'cart_insert_failed', 'ثبت سبد در پایگاه داده انجام نشد؛ وجود جدول صف و مجوزهای دیتابیس را بررسی کنید.', 'error' );
		}
	}

	/** Admin-only self-service diagnosis of the current WooCommerce browser session. */
	public static function diagnose_cart_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message'=>'دسترسی غیرمجاز' ), 403 ); }
		check_ajax_referer( 'mcar_diagnose_cart', 'nonce' );
		$session = self::session();
		if ( $session && ! WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
		if ( ! empty( $_POST['capture'] ) ) { self::touch_cart(); }
		global $wpdb;
		$table = MCAR_DB::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		$has_cart = function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty();
		$contact = $session ? self::cart_contact( $session ) : array( '', '', '' );
		$key = $session ? self::cart_key( false ) : '';
		$row = $exists && $key ? $wpdb->get_row( $wpdb->prepare( "SELECT id,status,stage,due_at FROM $table WHERE source_key=%s", $key ), ARRAY_A ) : null;
		wp_send_json_success( array(
			'enabled' => '1' === (string) MCAR_DB::settings()['enabled'],
			'table_exists' => $exists,
			'session' => (bool) $session,
			'cart_items' => $has_cart ? (int) WC()->cart->get_cart_contents_count() : 0,
			'session_key' => (bool) $key,
			'phone_found' => (bool) $contact[0],
			'phone_masked' => $contact[0] ? MCAR_DB::mask_phone( $contact[0] ) : '',
			'phone_source' => $contact[2],
			'queued' => (bool) $row,
			'job_id' => $row ? (int) $row['id'] : 0,
			'job_status' => $row ? (string) $row['status'] : '',
			'job_stage' => $row ? (int) $row['stage'] : 0,
			'next_send' => $row ? get_date_from_gmt( $row['due_at'], 'Y-m-d H:i:s' ) : '',
			'cron_scheduled' => (bool) wp_next_scheduled( 'mcar_scan_queue' ),
		) );
	}

	public static function cart_emptied() {
		global $wpdb;
		$key = self::cart_key( false );
		if ( $key ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::table() . " SET status='cancelled', updated_at=%s WHERE source_key=%s AND kind='cart' AND status IN ('active','processing')", current_time( 'mysql', true ), $key ) );
		}
		$session = self::session();
		if ( $session ) {
			$session->set( 'mcar_episode', wp_generate_uuid4() );
			$session->set( 'mcar_phone', '' );
			$session->set( 'mcar_name', '' );
		}
	}

	/** Replace the cart source with the order, preserving stage and coupons (no double SMS). */
	public static function checkout_order( $id_or_order ) {
		global $wpdb;
		if ( '1' !== (string) MCAR_DB::settings()['enabled'] ) { return; }
		$order = is_object( $id_or_order ) && is_a( $id_or_order, 'WC_Order' ) ? $id_or_order : wc_get_order( absint( $id_or_order ) );
		if ( ! $order || ! self::is_new_order( $order ) ) { return; }
		$key = self::cart_key( false );
		$hash = self::session_hash();
		$phone = MCAR_Meli::normalize_phone( $order->get_billing_phone() );
		$session = self::session();
		if ( ! $phone && $session ) { $phone = MCAR_Meli::normalize_phone( $session->get( 'mcar_phone', '' ) ); }
		if ( ! $phone ) { return; }
		$order->update_meta_data( '_mcar_session_hash', $hash );
		$order->save();
		$table = MCAR_DB::table();
		$known_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id=%d LIMIT 1", $order->get_id() ), ARRAY_A );
		$cart = $key ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE source_key=%s", $key ), ARRAY_A ) : null;
		// Some checkouts generate the order before the WooCommerce session is available.
		// Find the existing cart from the same shopping episode before creating a
		// second independent reminder row. Do not match historical orders.
		if ( ! $cart ) {
			$created = $order->get_date_created();
			$probe = array(
				'kind'=>'order', 'is_historical'=>0, 'phone'=>$phone,
				'session_hash'=>$hash, 'created_at'=>$created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : current_time( 'mysql', true ),
			);
			$candidates = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $table WHERE kind='cart' AND is_historical=0 AND phone=%s AND status IN ('active','processing','sent') ORDER BY id DESC LIMIT 15",
				$phone
			), ARRAY_A );
			if ( is_array( $candidates ) ) {
				foreach ( $candidates as $candidate ) {
					if ( self::same_checkout_episode( $candidate, $probe ) ) { $cart = $candidate; break; }
				}
			}
		}
		if ( $known_order ) {
			// An explicit admin cancellation must not re-queue the same checkout episode.
			MCAR_Attribution::capture_order( (int)$known_order['id'], $order, $phone );
			if ( $cart && 'cancelled' === $cart['status'] ) {
				$wpdb->update( $table, array( 'status'=>'cancelled', 'updated_at'=>current_time('mysql', true) ), array( 'id'=>$known_order['id'] ) );
				return;
			}
			if ( $cart && (int) $cart['id'] !== (int) $known_order['id'] ) {
				// Order status can fire before the checkout callback. If cart reminder 1
				// has already been accepted, keep that job as the canonical record.
				if ( 2 === (int) $cart['stage'] || ! empty( $cart['first_sent_at'] ) ) {
					$wpdb->update( $table, array( 'order_id'=>0, 'status'=>'cancelled', 'updated_at'=>current_time('mysql',true) ), array( 'id'=>$known_order['id'] ) );
					$wpdb->update( $table, array( 'kind'=>'order', 'order_id'=>$order->get_id(), 'phone'=>$phone,
						'customer_name'=>self::limit_name($order->get_billing_first_name()),
						'total'=>(float)$order->get_total(), 'currency'=>$order->get_currency(),
						'updated_at'=>current_time('mysql',true) ), array( 'id'=>$cart['id'] ) );
				} else {
					$wpdb->update( $table, array( 'status'=>'cancelled', 'updated_at'=>current_time('mysql',true) ), array( 'id'=>$cart['id'] ) );
				}
			}
			return;
		}
		if ( $cart ) {
			if ( ! self::order_eligible( $order ) ) {
				$wpdb->update( $table, array( 'kind'=>'order', 'order_id'=>$order->get_id(), 'status'=>'converted', 'updated_at'=>current_time('mysql', true) ), array( 'id'=>$cart['id'] ) );
				return;
			}
			$data = array(
				'kind'=>'order', 'order_id'=>$order->get_id(), 'phone'=>$phone,
				'customer_name'=>self::limit_name($order->get_billing_first_name()),
				'total'=>(float)$order->get_total(), 'currency'=>$order->get_currency(),
				'updated_at'=>current_time('mysql', true),
			);
			// If the first SMS was not sent, start a fresh 30-minute window on order placement.
			if ( 'active' === $cart['status'] && 1 === (int) $cart['stage'] ) {
				$data['due_at'] = gmdate('Y-m-d H:i:s', time() + absint( MCAR_DB::settings()['delay'] ) * MINUTE_IN_SECONDS );
			}
			$wpdb->update( $table, $data, array( 'id'=>$cart['id'] ) );
			MCAR_Attribution::capture_order( (int)$cart['id'], $order, $phone );
			MCAR_DB::log( 'cart_to_order', 'سبد به سفارش تبدیل شد؛ مراحل قبلی حفظ شدند.', 'info', $cart['id'] );
			return;
		}
		self::create_order_job( $order, $hash );
	}

	/** Prevent existing customers' historical orders from being queued on later status changes. */
	private static function is_new_order( $order ) {
		$created = $order->get_date_created();
		$tracking_started = (int) get_option( 'mcar_tracking_started_at', 0 );
		return $created && $tracking_started > 0 && $created->getTimestamp() >= $tracking_started;
	}

	/** Find order mobile, falling back to a registered customer's verified-format WooCommerce billing field. */
	private static function order_phone( $order ) {
		$phone = MCAR_Meli::normalize_phone( $order->get_billing_phone() );
		$user_id = absint( $order->get_customer_id() );
		if ( ! $phone && $user_id ) {
			$phone = MCAR_Meli::normalize_phone( get_user_meta( $user_id, 'billing_phone', true ) );
			$custom_key = sanitize_key( (string) ( MCAR_DB::settings()['phone_meta_key'] ?? '' ) );
			if ( ! $phone && $custom_key ) {
				$phone = MCAR_Meli::normalize_phone( get_user_meta( $user_id, $custom_key, true ) );
			}
			if ( ! $phone && '1' === (string) ( MCAR_DB::settings()['phone_login_fallback'] ?? '0' ) ) {
				$user = get_userdata( $user_id );
				if ( $user ) { $phone = MCAR_Meli::normalize_phone( $user->user_login ); }
			}
		}
		return $phone;
	}

	private static function order_eligible( $order ) {
		$s = MCAR_DB::settings();
		$allowed = array( 'pending', 'failed' );
		if ( '1' === (string) $s['include_on_hold'] ) { $allowed[] = 'on-hold'; }
		return ! $order->is_paid() && in_array( $order->get_status(), $allowed, true );
	}

	private static function create_order_job( $order, $hash = '', $historical = false ) {
		global $wpdb;
		if ( '1' !== (string) MCAR_DB::settings()['enabled'] || ! self::order_eligible( $order ) || ( ! $historical && ! self::is_new_order( $order ) ) ) { return false; }
		$phone = self::order_phone( $order );
		if ( ! $phone || $order->get_item_count() < 1 ) { return false; }
		$table = MCAR_DB::table();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE order_id=%d OR source_key=%s LIMIT 1", $order->get_id(), 'order:' . $order->get_id() ) ) ) { return false; }
		if ( $historical ) {
			// One historical recovery series per mobile; also respect existing live cart/order reminders.
			if ( self::blocked( $phone, MCAR_DB::settings() ) ||
				$wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE phone=%s LIMIT 1", $phone ) ) ) { return false; }
		}
		$key = 'order:' . $order->get_id();
		$created = $order->get_date_created();
		$baseline = $created ? $created->getTimestamp() : time();
		// Historical orders enter a throttled recovery queue rather than blasting immediately.
		$due = $historical
			? gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS )
			: gmdate( 'Y-m-d H:i:s', max( time() - 5 * MINUTE_IN_SECONDS, $baseline ) + absint( MCAR_DB::settings()['delay'] ) * MINUTE_IN_SECONDS );
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( $table, array(
			'source_key'=>$key, 'kind'=>'order', 'session_hash'=>(string)$hash,
			'order_id'=>$order->get_id(), 'is_historical'=>$historical ? 1 : 0, 'phone'=>$phone,
			'customer_name'=>self::limit_name($order->get_billing_first_name()),
			'total'=>(float)$order->get_total(), 'currency'=>(string)$order->get_currency(),
			'stage'=>1, 'status'=>'active', 'due_at'=>$due,
			'created_at'=>$now, 'updated_at'=>$now,
		) );
		if ( $ok ) { MCAR_Attribution::capture_order( (int)$wpdb->insert_id, $order, $phone ); }
		if ( $ok ) { MCAR_DB::log( $historical ? 'historical_queued' : 'order_captured', $historical ? 'سفارش پرداخت‌نشده قبلی پس از اعتبارسنجی و محدودسازی سرعت وارد صف شد.' : 'سفارش پرداخت‌نشده وارد صف شد.', 'info', $wpdb->insert_id ); }
		return (bool) $ok;
	}

	public static function order_status( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if ( ! self::order_eligible( $order ) ) { self::payment_completed( $order_id ); return; }
		self::create_order_job( $order, (string) $order->get_meta( '_mcar_session_hash', true ) );
	}
	public static function payment_completed( $order_id ) {
		global $wpdb;
		$table = MCAR_DB::table();
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='converted', updated_at=%s WHERE order_id=%d AND status IN ('active','processing')", $now, absint( $order_id ) ) );
		// Payment can finish without the normal checkout linking hook. Suppress
		// matching cart reminders, but never touch unrelated historical orders.
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) { return; }
		$phone = self::order_phone( $order );
		if ( ! $phone ) { return; }
		$created = $order->get_date_created();
		$probe = array(
			'kind'=>'order', 'is_historical'=>0, 'phone'=>$phone,
			'session_hash'=>(string) $order->get_meta( '_mcar_session_hash', true ),
			'created_at'=>$created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : $now,
		);
		$carts = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE kind='cart' AND is_historical=0 AND phone=%s AND status IN ('active','processing') ORDER BY id DESC LIMIT 20",
			$phone
		), ARRAY_A );
		if ( ! is_array( $carts ) ) { return; }
		foreach ( $carts as $cart ) {
			if ( self::same_checkout_episode( $cart, $probe ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='converted', updated_at=%s WHERE id=%d AND status IN ('active','processing')", $now, (int) $cart['id'] ) );
				MCAR_DB::log( 'linked_cart_stopped', 'خرید تکمیل یا متوقف شد؛ یادآوری سبد مرتبط لغو شد.', 'info', $cart['id'] );
			}
		}
	}
	private static function blocked( $phone, $settings ) {
		foreach ( preg_split( '/\r\n|\r|\n|[,;]/', (string) $settings['blocked_numbers'] ) as $item ) {
			if ( $phone === MCAR_Meli::normalize_phone( $item ) ) { return true; }
		}
		return false;
	}

	/** Create a one-use WooCommerce coupon, exactly two hours from generation. */
	private static function make_coupon( $job, $settings ) {
		global $wpdb;
		if ( ! class_exists( 'WC_Coupon' ) ) { return new WP_Error( 'mcar_coupon_lib', 'سامانه کوپن ووکامرس در دسترس نیست.' ); }
		try {
			if ( ! empty( $job['coupon_code'] ) && ! empty( $job['coupon_expires_at'] ) && strtotime( $job['coupon_expires_at'] . ' UTC' ) > time() + 5 * MINUTE_IN_SECONDS ) {
				return $job['coupon_code'];
			}
			$code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$candidate = 'MCR-' . strtoupper( wp_generate_password( 9, false, false ) );
				if ( ! wc_get_coupon_id_by_code( $candidate ) ) { $code = $candidate; break; }
			}
			if ( ! $code ) { return new WP_Error( 'mcar_coupon_unique', 'امکان ساخت کد یکتای تخفیف نبود.' ); }
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $settings['coupon_type'] );
			$coupon->set_amount( (float) $settings['coupon_amount'] );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_date_expires( time() + 2 * HOUR_IN_SECONDS );
			$coupon->set_description( 'Meli Cart Reminder (2 hours), job #' . absint( $job['id'] ) );
			$coupon->save();
			if ( ! $coupon->get_id() ) { return new WP_Error( 'mcar_coupon_save', 'کوپن در ووکامرس ذخیره نشد.' ); }
			$wpdb->update( MCAR_DB::table(), array(
				'coupon_code'=>$code, 'coupon_id'=>$coupon->get_id(),
				'coupon_expires_at'=>gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
			), array( 'id'=>$job['id'] ) );
			return $code;
		} catch ( Throwable $e ) {
			return new WP_Error( 'mcar_coupon_exception', 'خطا در ساخت کوپن؛ گزارش وردپرس را بررسی کنید.' );
		}
	}

	/** Permitted positional variables for Meli Payamak patterns; no URL or recovery fields. */
	public static function pattern_fields( $stage = 2 ) {
		$fields = array( 'name'=>'نام مشتری', 'shop_name'=>'نام فروشگاه', 'cart_total'=>'جمع خرید', 'order_number'=>'شماره سفارش' );
		if ( 2 === (int) $stage ) {
			$fields['discount_amount'] = 'مقدار تخفیف';
			$fields['coupon_code'] = 'کد تخفیف';
		}
		return $fields;
	}

	public static function pattern_keys( $settings, $stage ) {
		$key = 1 === (int) $stage ? 'pattern_first_vars' : 'pattern_second_vars';
		$raw = $settings[ $key ] ?? '';
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$allowed = self::pattern_fields( $stage );
		$result = array();
		foreach ( $raw as $v ) {
			$v = sanitize_key( trim( (string) $v ) );
			if ( isset( $allowed[ $v ] ) ) { $result[] = $v; }
		}
		return array_slice( $result, 0, 8 );
	}

	public static function replacement_values( $job, $settings, $coupon_code = '' ) {
		$stage = (int) $job['stage'];
		$discount = '';
		if ( 2 === $stage ) {
			$discount = 'percent' === $settings['coupon_type']
				? wc_format_decimal( $settings['coupon_amount'], 2 ) . '٪'
				: html_entity_decode( wp_strip_all_tags( wc_price( $settings['coupon_amount'], array( 'currency'=>$job['currency'] ?: get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		}
		return array(
			'name' => $job['customer_name'] ?: 'مشتری',
			'shop_name' => get_bloginfo( 'name' ),
			'cart_total' => html_entity_decode( wp_strip_all_tags( wc_price( $job['total'], array( 'currency'=>$job['currency'] ?: get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			'discount_amount' => $discount,
			'coupon_code' => 2 === $stage ? $coupon_code : '',
			'order_number' => 'order' === $job['kind'] ? (string) $job['order_id'] : '',
		);
	}

	public static function render_message( $job, $settings, $coupon_code = '' ) {
		$template = (string) ( 1 === (int) $job['stage'] ? $settings['message_first'] : $settings['message_second'] );
		$values = self::replacement_values( $job, $settings, $coupon_code );
		$replacements = array();
		foreach ( $values as $key=>$value ) { $replacements['{' . $key . '}'] = $value; }
		return strtr( $template, $replacements );
	}

	/** Dispatch by stage, never fall back to the other method after a failure. */
	public static function dispatch_sms( $phone, $job, $settings, $coupon_code = '' ) {
		$stage = (int) $job['stage'];
		$suffix = 1 === $stage ? 'first' : 'second';
		if ( 'pattern' !== (string) ( $settings[ 'mode_' . $suffix ] ?? 'plain' ) ) {
			return MCAR_Meli::send( $phone, self::render_message( $job, $settings, $coupon_code ) );
		}
		$keys = self::pattern_keys( $settings, $stage );
		if ( ! $keys ) { return new WP_Error( 'mcar_pattern_config', 'متغیرهای الگوی مرحله ' . $stage . ' تنظیم نشده‌اند.' ); }
		if ( 2 === $stage && ! in_array( 'coupon_code', $keys, true ) ) {
			return new WP_Error( 'mcar_pattern_coupon', 'کد تخفیف باید از متغیرهای پیامک دوم باشد.' );
		}
		$values = self::replacement_values( $job, $settings, $coupon_code );
		$ordered = array();
		foreach ( $keys as $key ) {
			if ( '' === trim( (string) $values[ $key ] ) ) {
				return new WP_Error( 'mcar_pattern_missing', 'مقدار متغیر «' . self::pattern_fields( $stage )[ $key ] . '» برای این خرید خالی است.' );
			}
			$ordered[] = $values[ $key ];
		}
		return MCAR_Meli::send_pattern( $phone, $settings[ 'pattern_' . $suffix . '_id' ] ?? '', $ordered );
	}

	/**
	 * Link a live cart to its checkout order even when a gateway bypasses the normal
	 * checkout callback. Prefer exact session identifiers; use a bounded time match
	 * only when one side has no session identifier. Never compare historic backfills.
	 */
	private static function same_checkout_episode( $cart, $order ) {
		if ( 'cart' !== $cart['kind'] || 'order' !== $order['kind'] ||
			! empty( $cart['is_historical'] ) || ! empty( $order['is_historical'] ) ||
			! $cart['phone'] || $cart['phone'] !== $order['phone'] ) { return false; }
		$cart_session = (string) ( $cart['session_hash'] ?? '' );
		$order_session = (string) ( $order['session_hash'] ?? '' );
		$cart_time = strtotime( $cart['created_at'] . ' UTC' );
		$order_time = strtotime( $order['created_at'] . ' UTC' );
		if ( $cart_session && $order_session ) {
			return $cart_time && $order_time && $order_time >= $cart_time - MINUTE_IN_SECONDS && $order_time <= $cart_time + DAY_IN_SECONDS && hash_equals( $cart_session, $order_session );
		}
		// Account for gateway hooks running slightly before checkout session capture.
		return $cart_time && $order_time && $order_time >= $cart_time - MINUTE_IN_SECONDS &&
			$order_time <= $cart_time + 2 * HOUR_IN_SECONDS;
	}

	/**
	 * One canonical order row per checkout. A previously accepted cart first stage
	 * carries over, so its first SMS is not sent again by the order row.
	 */
	private static function reconcile_live_cart_order( $job ) {
		global $wpdb;
		if ( ! in_array( $job['kind'], array( 'cart','order' ), true ) || ! empty( $job['is_historical'] ) ) { return false; }
		$table = MCAR_DB::table();
		$other_kind = 'cart' === $job['kind'] ? 'order' : 'cart';
		$near = gmdate( 'Y-m-d H:i:s', strtotime( $job['created_at'] . ' UTC' ) - 2 * HOUR_IN_SECONDS - 5 * MINUTE_IN_SECONDS );
		$far = gmdate( 'Y-m-d H:i:s', strtotime( $job['created_at'] . ' UTC' ) + 2 * HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS );
		$others = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE phone=%s AND kind=%s AND is_historical=0 AND id<>%d AND created_at BETWEEN %s AND %s AND status NOT IN ('cancelled','failed') ORDER BY id DESC LIMIT 20",
			$job['phone'], $other_kind, (int) $job['id'], $near, $far
		), ARRAY_A );
		if ( ! is_array( $others ) ) { return false; }
		foreach ( $others as $other ) {
			$cart = 'cart' === $job['kind'] ? $job : $other;
			$order = 'order' === $job['kind'] ? $job : $other;
			if ( ! self::same_checkout_episode( $cart, $order ) ) { continue; }
			$now = current_time( 'mysql', true );
			// The cart series already ended (or its delivery is uncertain). Do not
			// start a second series for the same checkout from an order record.
			if ( 'sent' === $cart['status'] || 'uncertain' === $cart['status'] || ! empty( $cart['second_sent_at'] ) ) {
				$wpdb->update( $table, array( 'status'=>'cancelled', 'updated_at'=>$now ),
					array( 'id'=>$order['id'] ) );
				MCAR_DB::log( 'order_duplicate_stopped', 'یادآوری همین سبد قبلاً تکمیل شده یا وضعیت ارسال آن نامشخص است؛ سفارش مجدداً ارسال نمی‌شود.', 'info', $order['id'] );
				return (int) $job['id'] === (int) $order['id'];
			}
			// If the order itself is completed/closed, its old cart cannot send reminders.
			if ( 'converted' === $order['status'] || 'sent' === $order['status'] || 'uncertain' === $order['status'] ) {
				$wpdb->update( $table, array( 'status'=>'cancelled','updated_at'=>$now ),
					array( 'id'=>$cart['id'] ) );
				MCAR_DB::log( 'cart_duplicate_stopped', 'سبد با سفارش همان خرید تطبیق یافت؛ ارسال تکراری متوقف شد.', 'info', $cart['id'] );
				return (int) $job['id'] === (int) $cart['id'];
			}
			// If a cart already sent its first reminder, carry stage two into the order.
			if ( ! empty( $cart['first_sent_at'] ) && empty( $order['first_sent_at'] ) &&
				1 === (int) $order['stage'] ) {
				$wpdb->update( $table, array(
					'stage'=>2, 'status'=>'active', 'first_sent_at'=>$cart['first_sent_at'],
					'provider_id_first'=>$cart['provider_id_first'], 'due_at'=>$cart['due_at'],
					'updated_at'=>$now,
				), array( 'id'=>$order['id'] ) );
				MCAR_DB::log( 'cart_stage_transferred', 'یادآوری اول سبد به سفارش منتقل شد؛ ارسال مجدد مرحله اول انجام نمی‌شود.', 'info', $order['id'] );
			}
			$wpdb->update( $table, array( 'status'=>'cancelled', 'updated_at'=>$now ), array( 'id'=>$cart['id'] ) );
			MCAR_DB::log( 'cart_duplicate_stopped', 'سبد و سفارش همان خرید ادغام شدند؛ مسیر سفارش باقی ماند.', 'info', $cart['id'] );
			// When the claimed row itself is the order, it is currently 'processing'.
			// Return it to the due queue after reconciliation rather than stranding it
			// as 'processing'. If the cart first stage was transferred, the order is
			// already 'active' at stage 2; the conditional UPDATE does nothing then.
			if ( (int) $job['id'] === (int) $order['id'] ) {
				$wpdb->update( $table, array( 'status'=>'active', 'updated_at'=>$now ), array( 'id'=>$order['id'], 'status'=>'processing' ) );
				MCAR_DB::log( 'order_requeued_after_merge', 'سفارش پس از ادغام سبد به صف بازگشت؛ ارسال تکراری انجام نشد.', 'info', $order['id'] );
			}
			// Retry on a later tick with the canonical order/stage and a fresh status check.
			return true;
		}
		return false;
	}

	private static function log_finish( $job, $status, $event, $details, $level = 'info' ) {
		global $wpdb;
		$wpdb->update( MCAR_DB::table(), array( 'status'=>$status, 'updated_at'=>current_time('mysql',true) ), array( 'id'=>$job['id'], 'status'=>'processing' ) );
		MCAR_DB::log( $event, $details, $level, $job['id'] );
	}

	private static function process_job( $job, $settings ) {
		global $wpdb;
		$table = MCAR_DB::table();
		if ( self::reconcile_live_cart_order( $job ) ) { return; }
		if ( 'order' === $job['kind'] ) {
			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order || ! self::order_eligible( $order ) || self::order_phone( $order ) !== $job['phone'] ) {
				self::log_finish( $job, 'converted', 'order_stopped', 'سفارش دیگر واجد شرایط ارسال نیست.' );
				return;
			}
		} elseif ( $job['session_hash'] ) {
			// Defensive fallback if an order was created without the ordinary checkout callback.
			$sibling = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE session_hash=%s AND kind='order' AND created_at >= %s AND id<>%d LIMIT 1", $job['session_hash'], $job['created_at'], $job['id'] ) );
			if ( $sibling ) {
				self::log_finish( $job, 'converted', 'cart_superseded', 'سفارش جایگزین سبد شد.' );
				return;
			}
		}
		if ( self::blocked( $job['phone'], $settings ) ) {
			self::log_finish( $job, 'cancelled', 'blocked', 'شماره در لیست عدم ارسال قرار دارد.' );
			return;
		}
		if ( 2 === (int) $job['stage'] ) {
			if ( function_exists( 'wc_coupons_enabled' ) && ! wc_coupons_enabled() ) {
				self::log_finish( $job, 'failed', 'coupons_disabled', 'استفاده از کدهای تخفیف در تنظیمات ووکامرس غیرفعال است.', 'error' );
				return;
			}
			$coupon_code = self::make_coupon( $job, $settings );
			if ( is_wp_error( $coupon_code ) ) {
				self::log_finish( $job, 'failed', 'coupon_error', $coupon_code->get_error_message(), 'error' );
				return;
			}
		} else { $coupon_code = ''; }
		// Close most pay/complete races after coupon generation; no arbitrary retries.
		if ( 'order' === $job['kind'] ) {
			$fresh = wc_get_order( (int) $job['order_id'] );
			if ( ! $fresh || ! self::order_eligible( $fresh ) ) {
				self::log_finish( $job, 'converted', 'order_paid_before_send', 'پرداخت پیش از ارسال ثبت شد.' );
				return;
			}
		}
		$current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id=%d", $job['id'] ) );
		if ( 'processing' !== $current_status ) { return; }
		$result = self::dispatch_sms( $job['phone'], $job, $settings, $coupon_code );
		if ( is_wp_error( $result ) ) {
			$uncertain = 'mcar_uncertain' === $result->get_error_code();
			self::log_finish( $job, $uncertain ? 'uncertain' : 'failed', 'sms_error',
				'مرحله ' . (int)$job['stage'] . ' (' . ( 'pattern' === ( $settings[ 'mode_' . ( 1 === (int) $job['stage'] ? 'first' : 'second' ) ] ?? '' ) ? 'الگو' : 'عادی' ) . '): ' . $result->get_error_message() . ' | ' . MCAR_DB::mask_phone( $job['phone'] ), 'error' );
			return;
		}
		$now = current_time( 'mysql', true );
		if ( 1 === (int) $job['stage'] ) {
			$wpdb->update( $table, array(
				'stage'=>2, 'status'=>'active', 'first_sent_at'=>$now,
				'provider_id_first'=>$result['provider_id'],
				'due_at'=>gmdate( 'Y-m-d H:i:s', time() + absint( $settings['second_delay'] ) * MINUTE_IN_SECONDS ),
				'updated_at'=>$now,
			), array( 'id'=>$job['id'], 'status'=>'processing', 'stage'=>1 ) );
			MCAR_Attribution::accepted( (int)$job['id'], 1, $now );
			MCAR_DB::log( 'first_accepted', 'یادآوری اول توسط درگاه پذیرفته شد؛ مرحله دوم زمان‌بندی شد (تحویل قطعی تأیید نشده است).', 'info', $job['id'] );
		} else {
			$wpdb->update( $table, array(
				'status'=>'sent', 'second_sent_at'=>$now, 'provider_id_second'=>$result['provider_id'], 'updated_at'=>$now,
			), array( 'id'=>$job['id'], 'status'=>'processing', 'stage'=>2 ) );
			MCAR_Attribution::accepted( (int)$job['id'], 2, $now );
			MCAR_DB::log( 'second_accepted', 'پیامک کد تخفیف دو ساعته توسط درگاه پذیرفته شد (تحویل قطعی تأیید نشده است).', 'info', $job['id'] );
		}
	}

	/**
	 * Backfill unpaid orders already in WooCommerce before this version was installed.
	 * Uses WC_Order_Query (HPOS + legacy data stores), small persistent batches, and
	 * one historical recovery per mobile. Never searches all registered customers.
	 */
	private static function scan_historical_orders( $settings ) {
		$empty = array( 'examined'=>0, 'queued'=>0, 'done'=>false );
		if ( '1' !== (string) ( $settings['history_enabled'] ?? '1' ) ) { return $empty; }
		$days = max( 1, min( 365, absint( $settings['history_days'] ?? 30 ) ) );
		$state = get_option( 'mcar_history_scan_state', array() );
		$state = is_array( $state ) ? $state : array();
		if ( (int) ( $state['days'] ?? 0 ) !== $days ) {
			$state = array(
				'days'=>$days,
				'after'=>time() - $days * DAY_IN_SECONDS,
				'before'=>time() - max( 30, absint( $settings['delay'] ) ) * MINUTE_IN_SECONDS,
				'offset'=>0, 'seen'=>0, 'queued'=>0, 'done'=>false,
			);
			update_option( 'mcar_history_scan_state', $state, false );
		}
		if ( ! empty( $state['done'] ) ) { return array( 'examined'=>0, 'queued'=>0, 'done'=>true ); }
		global $wpdb;
		$pending_historical = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . MCAR_DB::table() . " WHERE is_historical=1 AND status IN ('active','processing')" );
		if ( $pending_historical >= 6 ) { return $empty; }
		// Bounds are frozen on first run; newer unpaid orders are tracked by normal WooCommerce hooks.
		if ( (int) $state['before'] <= (int) $state['after'] ) {
			$state['done'] = true;
			update_option( 'mcar_history_scan_state', $state, false );
			return array( 'examined'=>0, 'queued'=>0, 'done'=>true );
		}
		$statuses = array( 'pending', 'failed' );
		if ( '1' === (string) $settings['include_on_hold'] ) { $statuses[] = 'on-hold'; }
		try {
			$orders = wc_get_orders( array(
				'type'=>'shop_order', 'status'=>$statuses,
				'date_created'=>(int)$state['after'] . '...' . (int)$state['before'],
				'orderby'=>'date', 'order'=>'DESC', 'limit'=>30,
				'offset'=>absint( $state['offset'] ), 'return'=>'objects',
			) );
			if ( ! is_array( $orders ) ) { throw new RuntimeException( 'Historical order query failed.' ); }
			$queued = 0;
			$examined = 0;
			foreach ( $orders as $order ) {
				$examined++;
				if ( $order instanceof WC_Order && self::create_order_job( $order, '', true ) ) { $queued++; }
				// Admit historical orders gradually; recent purchases have priority.
				if ( $queued >= 1 ) { break; }
			}
			$state['offset'] = absint( $state['offset'] ) + $examined;
			$state['seen'] = absint( $state['seen'] ) + $examined;
			$state['queued'] = absint( $state['queued'] ) + $queued;
			if ( $examined === count( $orders ) && count( $orders ) < 30 ) { $state['done'] = true; }
			if ( ! $orders ) { $state['done'] = true; }
			update_option( 'mcar_history_scan_state', $state, false );
			if ( $queued || ! empty( $state['done'] ) ) {
				MCAR_DB::log( 'historical_scan', 'اسکن سفارش‌های قبلی: بررسی ' . $examined . '، ورود به صف ' . $queued . '، مجموع بررسی ' . $state['seen'] . '، وضعیت ' . ( ! empty( $state['done'] ) ? 'پایان' : 'ادامه دارد' ) . '.' );
			}
			return array( 'examined'=>$examined, 'queued'=>$queued, 'done'=>! empty( $state['done'] ) );
		} catch ( Throwable $e ) {
			MCAR_DB::log( 'historical_scan_error', 'اسکن سفارش‌های قبلی با خطای داخلی روبه‌رو شد: ' . substr( get_class( $e ), 0, 60 ), 'error' );
			return $empty;
		}
	}

	/** Process due jobs (also callable by an authorized admin from the dashboard). */
	public static function process_queue() {
		global $wpdb;
		$settings = MCAR_DB::settings();
		if ( '1' !== (string) $settings['enabled'] ) {
			return array( 'status'=>'disabled', 'due'=>0, 'processed'=>0 );
		}
		$old_lock = (int) get_option( 'mcar_scan_lock', 0 );
		if ( $old_lock && $old_lock > time() - 5 * MINUTE_IN_SECONDS ) {
			return array( 'status'=>'locked', 'due'=>0, 'processed'=>0 );
		}
		if ( ! add_option( 'mcar_scan_lock', time(), '', false ) ) {
			if ( $old_lock >= time() - 5 * MINUTE_IN_SECONDS ) {
				return array( 'status'=>'locked', 'due'=>0, 'processed'=>0 );
			}
			delete_option( 'mcar_scan_lock' );
			if ( ! add_option( 'mcar_scan_lock', time(), '', false ) ) {
				return array( 'status'=>'locked', 'due'=>0, 'processed'=>0 );
			}
		}
		$due_count = 0;
		$processed_count = 0;
		$scan_started = time();
		try {
			// Exactly one monetary credit check at the beginning of each queue run.
			// 2,000 toman = 20,000 rial. If the API fails, do not claim jobs,
			// create coupons, advance stages, or initiate any provider SMS call.
			$credit = MCAR_Meli::get_balance_rials();
			if ( is_wp_error( $credit ) || (float) $credit <= 20000.0 ) {
				$reason = is_wp_error( $credit ) ? 'credit_error' : 'credit_low';
				$detail = is_wp_error( $credit )
					? $credit->get_error_message()
					: 'موجودی پنل ' . number_format( (float) $credit / 10, 0 ) . ' تومان است؛ حداقل باید بیش از ۲٬۰۰۰ تومان باشد.';
				$summary = array( 'status'=>$reason, 'time'=>$scan_started, 'due'=>0, 'processed'=>0,
					'credit_rials'=>is_wp_error( $credit ) ? null : (float)$credit );
				update_option( 'mcar_last_scan', $summary, false );
				$old_state = get_option( 'mcar_credit_gate_state', array() );
				if ( ! is_array( $old_state ) || ( $old_state['status'] ?? '' ) !== $reason || time() - (int)( $old_state['logged_at'] ?? 0 ) >= HOUR_IN_SECONDS ) {
					MCAR_DB::log( $reason, 'اجرای صف بدون هیچ ارسال متوقف شد: ' . $detail, 'warning' );
					update_option( 'mcar_credit_gate_state', array( 'status'=>$reason, 'logged_at'=>time() ), false );
				}
				return $summary;
			}
			$old_gate = get_option( 'mcar_credit_gate_state', array() );
			if ( is_array( $old_gate ) && ! empty( $old_gate['status'] ) && $old_gate['status'] !== 'ok' ) {
				MCAR_DB::log( 'credit_restored', 'اعتبار ملی پیامک بیش از ۲٬۰۰۰ تومان شد؛ صف بدون تکرار مراحل پذیرفته‌شده ادامه پیدا می‌کند.' );
			}
			update_option( 'mcar_credit_gate_state', array( 'status'=>'ok', 'logged_at'=>time() ), false );
			$history = self::scan_historical_orders( $settings );
			$table = MCAR_DB::table();
			$now = current_time( 'mysql', true );
			// Default: 10 jobs/minute (configurable 3..50); historical work is
			// throttled to one job/minute so new customers cannot be starved.
			$max_jobs = max( 3, min( 50, absint( $settings['max_per_run'] ?? 10 ) ) );
			$historic_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $table WHERE status='active' AND is_historical=1 AND due_at<=%s ORDER BY due_at ASC LIMIT 1", $now
			), ARRAY_A );
			if ( ! is_array( $historic_rows ) ) { throw new RuntimeException( 'Historical queue query failed' ); }
			$live_limit = $max_jobs - ( $historic_rows ? 1 : 0 );
			$fresh_cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
			// Protect reminders that became due within the past ten minutes from
			// an old backlog. Reserve about one quarter for older overdue jobs.
			$fresh_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $table WHERE status='active' AND is_historical=0 AND due_at BETWEEN %s AND %s ORDER BY due_at ASC LIMIT %d",
				$fresh_cutoff, $now, $live_limit
			), ARRAY_A );
			$old_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $table WHERE status='active' AND is_historical=0 AND due_at<%s ORDER BY due_at ASC LIMIT %d",
				$fresh_cutoff, $live_limit
			), ARRAY_A );
			if ( ! is_array( $fresh_rows ) || ! is_array( $old_rows ) ) { throw new RuntimeException( 'Live queue query failed' ); }
			$fresh_quota = (int) ceil( $live_limit * 0.75 );
			$selected_fresh = array_slice( $fresh_rows, 0, $fresh_quota );
			$selected_old = array_slice( $old_rows, 0, $live_limit - count( $selected_fresh ) );
			$remaining = $live_limit - count( $selected_fresh ) - count( $selected_old );
			if ( $remaining > 0 ) {
				$selected_fresh = array_merge( $selected_fresh, array_slice( $fresh_rows, count( $selected_fresh ), $remaining ) );
			}
			$rows = array_merge( $selected_fresh, $selected_old, $historic_rows );
			$due_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE status='active' AND due_at<=%s", $now
			) );
			// Do not hold a single cron request open for a large/slow provider batch.
			// A later one-minute tick continues any unprocessed jobs.
			$worker_started = microtime( true );
			$time_budget_exceeded = false;
			foreach ( $rows as $job ) {
				if ( microtime( true ) - $worker_started >= 12 ) {
					$time_budget_exceeded = true;
					break;
				}
				$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='processing',updated_at=%s WHERE id=%d AND status='active' AND stage=%d AND due_at<=%s", $now, $job['id'], $job['stage'], $now ) );
				if ( 1 !== (int) $claimed ) { continue; }
				$processed_count++;
				try { self::process_job( $job, $settings ); }
				catch ( Throwable $e ) {
					self::log_finish( $job, 'failed', 'worker_exception', 'خطای داخلی مرحله ' . (int)$job['stage'] . ': ' . substr( get_class( $e ), 0, 60 ), 'error' );
				}
			}
			if ( (int) get_option( 'mcar_last_cleanup', 0 ) < time() - DAY_IN_SECONDS ) {
				MCAR_DB::cleanup();
				update_option( 'mcar_last_cleanup', time(), false );
			}
			$summary = array( 'status'=>'ok', 'time'=>$scan_started, 'due'=>$due_count, 'processed'=>$processed_count, 'history_queued'=>$history['queued'], 'history_examined'=>$history['examined'], 'batch_limit'=>$max_jobs, 'time_limited'=>$time_budget_exceeded, 'credit_rials'=>(float)$credit );
			update_option( 'mcar_last_scan', $summary, false );
			if ( $due_count ) {
				MCAR_DB::log( 'queue_scan', 'بررسی صف: ' . $due_count . ' مورد موعددار؛ ' . $processed_count . ' پردازش؛ سقف ' . $max_jobs . ( $time_budget_exceeded ? '؛ توقف به علت محدودیت زمان پردازش' : '' ) . '.' );
			}
			return $summary;
		} catch ( Throwable $e ) {
			$summary = array( 'status'=>'error', 'time'=>$scan_started, 'due'=>$due_count, 'processed'=>$processed_count );
			update_option( 'mcar_last_scan', $summary, false );
			MCAR_DB::log( 'scan_exception', 'صف به‌دلیل خطای داخلی بررسی نشد: ' . substr( get_class( $e ), 0, 60 ), 'error' );
			return $summary;
		} finally { delete_option( 'mcar_scan_lock' ); }
	}

}
