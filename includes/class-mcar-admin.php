<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MCAR_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_mcar_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_mcar_pattern_preview', array( __CLASS__, 'pattern_preview' ) );
		add_action( 'wp_ajax_mcar_credit_check', array( __CLASS__, 'credit_check' ) );
		add_action( 'admin_post_mcar_test', array( __CLASS__, 'test_sms' ) );
		add_action( 'admin_post_mcar_retry', array( __CLASS__, 'retry' ) );
		add_action( 'admin_post_mcar_retry_all', array( __CLASS__, 'retry_all' ) );
		add_action( 'admin_post_mcar_rebuild_report', array( __CLASS__, 'rebuild_report' ) );
		add_action( 'admin_post_mcar_run_now', array( __CLASS__, 'run_now' ) );
		add_action( 'admin_post_mcar_cancel_pending', array( __CLASS__, 'cancel_pending' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
	}
	public static function menu() {
		add_submenu_page( 'woocommerce', 'یادآور سبد رهاشده', 'یادآور ملی پیامک', 'manage_woocommerce', 'mcar-sms', array( __CLASS__, 'page' ) );
	}
	private static function auth( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی غیرمجاز.' ); }
		check_admin_referer( $action );
	}
	private static function redirect( $notice, $tab = 'settings' ) {
		wp_safe_redirect( add_query_arg( array( 'page'=>'mcar-sms', 'tab'=>$tab, 'mcar_notice'=>$notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save() {
		self::auth( 'mcar_save' );
		$old = MCAR_DB::settings();
		$p = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$amount = sanitize_text_field( $p['coupon_amount'] ?? '' );
		$amount = strtr( str_replace( array( '٬', ',', '،' ), '', $amount ), array(
			'۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
			'٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
		) );
		$type = isset( $p['coupon_type'] ) && 'fixed_cart' === $p['coupon_type'] ? 'fixed_cart' : 'percent';
		$amount = is_numeric( $amount ) ? max( 0, (float) $amount ) : 0;
		if ( 'percent' === $type ) { $amount = min( 100, $amount ); }
		$numbers = array();
		foreach ( preg_split( '/\r\n|\r|\n|[,;]/', sanitize_textarea_field( $p['blocked_numbers'] ?? '' ) ) as $raw ) {
			$number = MCAR_Meli::normalize_phone( $raw );
			if ( $number ) { $numbers[$number] = $number; }
		}
		// bodyId and positional variables are configured separately for each stage.
		$pattern_options = array();
		foreach ( array( 1=>'first', 2=>'second' ) as $stage=>$suffix ) {
			$pattern_options[ 'mode_' . $suffix ] = ( isset( $p[ 'mode_' . $suffix ] ) && 'pattern' === $p[ 'mode_' . $suffix ] ) ? 'pattern' : 'plain';
			$pattern_options[ 'pattern_' . $suffix . '_id' ] = (string) absint( $p[ 'pattern_' . $suffix . '_id' ] ?? 0 );
			$fields = MCAR_Core::pattern_fields( $stage );
			$raw = $p[ 'pattern_' . $suffix . '_vars' ] ?? array();
			if ( ! is_array( $raw ) ) { $raw = explode( ',', (string) $raw ); }
			$chosen = array();
			$found_gap = false;
			foreach ( array_slice( $raw, 0, 8 ) as $var ) {
				$var = sanitize_key( (string) $var );
				if ( '' === $var ) { $found_gap = true; continue; }
				if ( ! isset( $fields[ $var ] ) || $found_gap ) { self::redirect( 'invalid_pattern_vars' ); }
				$chosen[] = $var;
			}
			$pattern_options[ 'pattern_' . $suffix . '_vars' ] = implode( ',', $chosen );
			$pattern_options[ 'pattern_' . $suffix . '_preview' ] = sanitize_textarea_field( $p[ 'pattern_' . $suffix . '_preview' ] ?? ( $old[ 'pattern_' . $suffix . '_preview' ] ?? '' ) );
			if ( 'pattern' === $pattern_options[ 'mode_' . $suffix ] ) {
				if ( ! absint( $pattern_options[ 'pattern_' . $suffix . '_id' ] ) ) { self::redirect( 'missing_pattern_id' ); }
				if ( ! count( $chosen ) ) { self::redirect( 'invalid_pattern_vars' ); }
				if ( 2 === $stage && ! in_array( 'coupon_code', $chosen, true ) ) { self::redirect( 'need_pattern_code' ); }
			}
		}
		$options = array(
			'enabled' => ! empty( $p['enabled'] ) ? '1' : '0',
			'username' => sanitize_text_field( $p['username'] ?? '' ),
			'password' => ! empty( $p['clear_password'] ) ? '' : ( ! empty( $p['password'] ) ? (string) $p['password'] : $old['password'] ),
			'sender' => preg_replace( '/[^0-9+]/', '', sanitize_text_field( $p['sender'] ?? '' ) ),
			'delay' => max( 5, min( 10080, absint( $p['delay'] ?? 30 ) ) ),
			'second_delay' => max( 5, min( 10080, absint( $p['second_delay'] ?? 60 ) ) ),
			'message_first' => sanitize_textarea_field( $p['message_first'] ?? '' ),
			'message_second' => sanitize_textarea_field( $p['message_second'] ?? '' ),
			'coupon_type' => $type,
			'coupon_amount' => (string) $amount,
			'include_on_hold' => ! empty( $p['include_on_hold'] ) ? '1' : '0',
			'blocked_numbers' => implode( "\n", $numbers ),
			'phone_meta_key' => sanitize_key( (string) ( $p['phone_meta_key'] ?? '' ) ),
			'phone_login_fallback' => ! empty( $p['phone_login_fallback'] ) ? '1' : '0',
			'history_enabled' => ! empty( $p['history_enabled'] ) ? '1' : '0',
			'history_days' => max( 1, min( 365, absint( $p['history_days'] ?? 30 ) ) ),
			'max_per_run' => max( 3, min( 50, absint( $p['max_per_run'] ?? ( $old['max_per_run'] ?? 10 ) ) ) ),
			'attribution_days' => max( 1, min( 365, absint( $p['attribution_days'] ?? ( $old['attribution_days'] ?? 90 ) ) ) ),
			'mode_first' => $pattern_options['mode_first'],
			'mode_second' => $pattern_options['mode_second'],
			'pattern_first_id' => $pattern_options['pattern_first_id'],
			'pattern_second_id' => $pattern_options['pattern_second_id'],
			'pattern_first_vars' => $pattern_options['pattern_first_vars'],
			'pattern_second_vars' => $pattern_options['pattern_second_vars'],
			'pattern_first_preview' => $pattern_options['pattern_first_preview'],
			'pattern_second_preview' => $pattern_options['pattern_second_preview'],
		);
		if ( ( 'plain' === $options['mode_first'] && ! trim( $options['message_first'] ) ) || ( 'plain' === $options['mode_second'] && ! trim( $options['message_second'] ) ) ) { self::redirect( 'empty_message' ); }
		if ( 'plain' === $options['mode_second'] && false === strpos( $options['message_second'], '{coupon_code}' ) ) { self::redirect( 'need_code' ); }
		if ( $amount <= 0 ) { self::redirect( 'invalid_discount' ); }
		if ( '1' === $options['enabled'] && ( ! $options['username'] || ! $options['password'] ) ) { self::redirect( 'missing_credentials' ); }
		if ( '1' === $options['enabled'] && ( 'plain' === $options['mode_first'] || 'plain' === $options['mode_second'] ) && ! $options['sender'] ) { self::redirect( 'missing_sender' ); }
		update_option( 'mcar_settings', $options, false );
		if ( $options['history_days'] != ( $old['history_days'] ?? 30 ) ||
			( $options['history_enabled'] === '1' && ( $old['history_enabled'] ?? '1' ) !== '1' ) ) {
			delete_option( 'mcar_history_scan_state' );
		}
		if ( (int)$options['attribution_days'] !== (int)( $old['attribution_days'] ?? 90 ) ) { delete_option( 'mcar_report_scan_state' ); }
		MCAR_DB::log( 'settings_updated', 'تنظیمات توسط مدیر به‌روز شد.' );
		self::redirect( 'saved' );
	}

	/** Manual credential-protected balance diagnostic; it never sends SMS. */
	public static function credit_check() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message'=>'دسترسی غیرمجاز.' ), 403 );
		}
		check_ajax_referer( 'mcar_credit_check', 'nonce' );
		$balance = MCAR_Meli::get_balance_rials();
		if ( is_wp_error( $balance ) ) {
			wp_send_json_error( array( 'message'=>$balance->get_error_message() ), 422 );
		}
		wp_send_json_success( array( 'toman'=>(float)$balance / 10, 'allowed'=>(float)$balance > 20000.0 ) );
	}

	/** AJAX preview lookup; never dispatches an SMS or creates a coupon. */
	public static function pattern_preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message'=>'دسترسی غیرمجاز.' ), 403 );
		}
		check_ajax_referer( 'mcar_pattern_preview', 'nonce' );
		$id = absint( $_POST['body_id'] ?? 0 );
		if ( ! $id ) { wp_send_json_error( array( 'message'=>'شناسه الگوی معتبر وارد کنید.' ), 400 ); }
		$result = MCAR_Meli::pattern_details( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message'=>$result->get_error_message() ), 422 );
		}
		wp_send_json_success( $result );
	}

	public static function test_sms() {
		self::auth( 'mcar_test' );
		$phone = MCAR_Meli::normalize_phone( isset( $_POST['test_phone'] ) ? wp_unslash( $_POST['test_phone'] ) : '' );
		if ( ! $phone ) { self::redirect( 'bad_test_phone' ); }
		$stage = 2 === absint( $_POST['test_stage'] ?? 1 ) ? 2 : 1;
		$key = 'mcar_test_' . get_current_user_id();
		if ( get_transient( $key ) ) { self::redirect( 'test_limit' ); }
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		// Test sends are actual billable requests. Apply the same safety gate.
		$credit = MCAR_Meli::get_balance_rials();
		if ( is_wp_error( $credit ) ) {
			MCAR_DB::log( 'test_credit_error', $credit->get_error_message(), 'warning' );
			self::redirect( 'test_credit_error' );
		}
		if ( (float)$credit <= 20000.0 ) {
			MCAR_DB::log( 'test_credit_low', 'ارسال تست به دلیل موجودی حداکثر ۲٬۰۰۰ تومان متوقف شد.', 'warning' );
			self::redirect( 'test_credit_low' );
		}
		// An explicitly marked fake coupon: API/variable test only, not redeemable.
		$job = array( 'stage'=>$stage, 'customer_name'=>'کاربر تست', 'kind'=>'order',
			'order_id'=>12345, 'total'=>250000, 'currency'=>get_woocommerce_currency() );
		$code = 2 === $stage ? 'MCR-TEST12345' : '';
		$settings = MCAR_DB::settings();
		$result = MCAR_Core::dispatch_sms( $phone, $job, $settings, $code );
		if ( is_wp_error( $result ) ) {
			MCAR_DB::log( 'test_failed', 'مرحله ' . $stage . ': ' . $result->get_error_message(), 'error' );
			self::redirect( 'test_failed' );
		}
		MCAR_DB::log( 'test_accepted', 'مرحله ' . $stage . ' (' . ( 'pattern' === $settings[ 'mode_' . ( 1 === $stage ? 'first' : 'second' ) ] ? 'الگو' : 'عادی' ) . ')، پذیرش درگاه؛ شماره ' . MCAR_DB::mask_phone( $phone ) );
		self::redirect( 'test_sent' );
	}

	public static function retry() {
		global $wpdb;
		self::auth( 'mcar_retry' );
		$id = absint( $_POST['job_id'] ?? 0 );
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::table() . " SET status='active',due_at=%s,updated_at=%s WHERE id=%d AND status='failed'", $now, $now, $id ) );
		if ( 1 === (int) $updated ) { MCAR_DB::log( 'manual_retry', 'ارسال دارای خطای قطعی به درخواست مدیر دوباره در صف قرار گرفت.', 'info', $id ); }
		self::redirect( $updated ? 'retry_ok' : 'retry_no', 'jobs' );
	}


	/** One-click bulk retry; queues only definitively failed attempts, never uncertain ones. */
	public static function retry_all() {
		global $wpdb;
		self::auth( 'mcar_retry_all' );
		if ( empty( $_POST['confirm_retry_all'] ) ) { self::redirect( 'retry_all_confirm', 'jobs' ); }
		$s = MCAR_DB::settings();
		if ( '1' !== (string)$s['enabled'] ) { self::redirect( 'retry_all_disabled', 'jobs' ); }
		// No pointless requeues when sending cannot actually proceed.
		$credit = MCAR_Meli::get_balance_rials();
		if ( is_wp_error( $credit ) ) { self::redirect( 'retry_all_credit_error', 'jobs' ); }
		if ( (float)$credit <= 20000 ) { self::redirect( 'retry_all_credit_low', 'jobs' ); }
		$now = current_time( 'mysql', true );
		// Atomic status condition: repeated clicks cannot requeue an active/sent job.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::table() . " SET status='active',due_at=%s,updated_at=%s WHERE status='failed'", $now, $now ) );
		if ( false === $updated ) { self::redirect( 'retry_all_error', 'jobs' ); }
		MCAR_DB::log( 'bulk_retry_queued', 'مدیر ' . (int)$updated . ' مورد دارای خطای قطعی را دوباره در صف قرار داد. مورد نامشخص/ارسال‌شده تغییر نکرد.' );
		self::redirect( $updated ? 'retry_all_ok' : 'retry_all_empty', 'jobs' );
	}

	/** Manual report sync doesn't send SMS or generate coupons. */
	public static function rebuild_report() {
		self::auth( 'mcar_rebuild_report' );
		MCAR_Attribution::reconcile();
		self::redirect( 'report_rebuilt', 'reports' );
	}

	/** Explicit manual run, permission and nonce protected; may actually send due SMS. */
	public static function run_now() {
		self::auth( 'mcar_run_now' );
		$result = MCAR_Core::process_queue();
		$status = is_array( $result ) ? (string) ( $result['status'] ?? 'error' ) : 'error';
		$due = is_array( $result ) ? absint( $result['due'] ?? 0 ) : 0;
		$processed = is_array( $result ) ? absint( $result['processed'] ?? 0 ) : 0;
		MCAR_DB::log( 'manual_scan', 'اجرای دستی پردازشگر: وضعیت ' . sanitize_key( $status ) . '، موعددار ' . $due . '، بررسی‌شده ' . $processed . '.' );
		self::redirect( in_array( $status, array( 'ok','disabled','locked','error','credit_low','credit_error' ), true ) ? 'run_' . $status : 'run_error', 'jobs' );
	}

	/** Cancel only not-yet-dispatched queue stages. Do not delete logs or re-send completed stages. */
	public static function cancel_pending() {
		global $wpdb;
		self::auth( 'mcar_cancel_pending' );
		if ( empty( $_POST['confirm_cancel_pending'] ) ) { self::redirect( 'cancel_not_confirmed', 'jobs' ); }
		$now = current_time( 'mysql', true );
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::table() . " SET status='cancelled',updated_at=%s WHERE status='active'", $now ) );
		if ( false === $affected ) { self::redirect( 'cancel_error', 'jobs' ); }
		// A bulk cancellation should not silently refill the queue from historical orders.
		$s = MCAR_DB::settings();
		$s['history_enabled'] = '0';
		update_option( 'mcar_settings', $s, false );
		MCAR_DB::log( 'manual_cancel_pending', 'مدیر ادامه ارسال برای ' . (int) $affected . ' رکورد فعال را متوقف کرد و اسکن سفارش‌های تاریخی را غیرفعال نمود.' );
		self::redirect( 'cancel_ok', 'jobs' );
	}

	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'یادآور سبد رهاشده - ملی پیامک',
				'<p>این فروشگاه ممکن است شماره تلفن، نام و اطلاعات کلی سبد/سفارش پرداخت‌نشده، از جمله سفارش‌های پرداخت‌نشده قبلی در بازه تعیین‌شده، را برای ارسال حداکثر دو پیامک یادآوری به سرویس ملی پیامک انتقال دهد. پیامک دوم ممکن است شامل کد تخفیف اختصاصی با اعتبار دو ساعت باشد. لاگ‌ها و رکوردهای غیرفعال صف معمولاً پس از ۴۵ روز پاک می‌شوند. تطبیق محصولات و سفارش‌های پس از پیامک، با شناسه ناشناس‌شده گیرنده، حداکثر ۳۶۵ روز نگهداری می‌شود. برای لغو دریافت یادآوری به پشتیبانی فروشگاه اطلاع دهید.</p>' );
		}
	}
	private static function status_label( $status ) {
		$labels = array( 'awaiting_phone'=>'منتظر شماره موبایل', 'active'=>'در صف', 'processing'=>'در حال پردازش', 'sent'=>'هر دو مرحله پذیرفته‌شد', 'converted'=>'خرید تکمیل/متوقف شد', 'cancelled'=>'لغو شده', 'failed'=>'خطای قطعی', 'uncertain'=>'وضعیت ارسال نامشخص' );
		return $labels[ $status ] ?? $status;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$s = MCAR_DB::settings();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		if ( ! in_array( $tab, array( 'settings','jobs','logs','reports' ), true ) ) { $tab = 'settings'; }
		$notices = array(
			'saved'=>'تنظیمات با موفقیت ذخیره شد.',
			'empty_message'=>'برای روش ارسال عادی، متن پیامک هر مرحله نباید خالی باشد.',
			'need_code'=>'پیامک دوم باید دارای متغیر {coupon_code} باشد.',
			'invalid_discount'=>'مقدار تخفیف باید بیشتر از صفر باشد.',
			'missing_credentials'=>'نام کاربری و رمز وب‌سرویس را وارد کنید.',
			'missing_sender'=>'برای مرحله‌ای که از ارسال عادی استفاده می‌کند، خط فرستنده را تنظیم کنید.',
			'missing_pattern_id'=>'شناسه عددی الگو (bodyId) را برای مرحله الگویی وارد کنید.',
			'invalid_pattern_vars'=>'متغیرهای الگو را از اولین ردیف، پشت سر هم و فقط از موارد مجاز انتخاب کنید.',
			'need_pattern_code'=>'در متغیرهای الگوی پیامک دوم باید کد تخفیف وجود داشته باشد.',
			'bad_test_phone'=>'شماره آزمایشی معتبر نیست.',
			'test_limit'=>'بین دو پیامک آزمایشی یک دقیقه فاصله بگذارید.',
			'test_credit_low'=>'موجودی پنل حداکثر ۲٬۰۰۰ تومان است؛ ارسال تست متوقف شد.',
			'test_credit_error'=>'استعلام اعتبار ریالی ناموفق بود؛ ارسال تست برای جلوگیری از خطا انجام نشد.',
			'test_failed'=>'ارسال آزمایشی موفق نبود؛ گزارش خطا را بررسی کنید.',
			'test_sent'=>'درگاه پیامک آزمایشی را پذیرفته است؛ تحویل قطعی گوشی هنوز معلوم نیست.',
			'retry_ok'=>'مورد خطادار به صف بازگشت.',
			'retry_no'=>'این مورد در وضعیت خطای قطعی نیست و قابل تکرار نیست.',
			'retry_all_ok'=>'همه موارد دارای خطای قطعی به صف بازگشتند؛ ارسال تدریجی بر اساس ظرفیت هر دقیقه انجام می‌شود.',
			'retry_all_empty'=>'هیچ رکورد خطای قطعی برای تلاش مجدد وجود ندارد.',
			'retry_all_confirm'=>'تأیید تلاش مجدد گروهی انتخاب نشده است.',
			'retry_all_disabled'=>'برای تلاش مجدد گروهی، ابتدا ارسال خودکار را فعال کنید.',
			'retry_all_credit_error'=>'استعلام اعتبار ملی پیامک ناموفق بود؛ هیچ رکوردی تغییر نکرد.',
			'retry_all_credit_low'=>'موجودی پنل کافی نیست؛ هیچ رکوردی تغییر نکرد.',
			'retry_all_error'=>'خطا در تغییر وضعیت رکوردهای خطادار.',
			'report_rebuilt'=>'یک دسته از سفارش‌های پرداخت‌شده بررسی و آمار گزارش به‌روزرسانی شد.',
			'run_ok'=>'صف به‌صورت دستی بررسی شد. وضعیت هر مورد و گزارش ارسال را در همین صفحه و لاگ‌ها بررسی کنید.',
			'run_disabled'=>'ارسال خودکار خاموش است؛ صف اجرا نشد.',
			'run_locked'=>'پردازش دیگری در حال اجراست یا قفل تازه‌ای وجود دارد؛ چند دقیقه بعد بررسی کنید.',
			'run_error'=>'پردازش صف با خطای داخلی مواجه شد؛ لاگ‌ها را بررسی کنید.',
			'run_credit_low'=>'موجودی کافی نیست؛ صف بدون ارسال پیامک حفظ شد.',
			'run_credit_error'=>'استعلام اعتبار ریالی موفق نبود؛ صف بدون تغییر حفظ شد.',
			'cancel_not_confirmed'=>'برای لغو صف باید تأیید مشخص‌شده را انتخاب کنید.',
			'cancel_error'=>'لغو صف با خطای پایگاه داده مواجه شد.',
			'cancel_ok'=>'تمام موارد فعال صف برای مراحل باقی‌مانده لغو شدند؛ پیامک‌های قبلاً ارسال‌شده قابل برگشت نیستند.',
		);
		$notice = isset( $_GET['mcar_notice'] ) ? sanitize_key( $_GET['mcar_notice'] ) : '';
		echo '<div class="wrap" dir="rtl" style="max-width:1170px"><h1>یادآور دو مرحله‌ای سبد رهاشده — ملی پیامک</h1>';
		if ( isset( $notices[ $notice ] ) ) {
			$bad = in_array( $notice, array('empty_message','need_code','invalid_discount','missing_credentials','missing_sender','missing_pattern_id','invalid_pattern_vars','need_pattern_code','bad_test_phone','test_limit','test_credit_low','test_credit_error','test_failed','retry_no','retry_all_confirm','retry_all_disabled','retry_all_credit_error','retry_all_credit_low','retry_all_error','run_disabled','run_locked','run_error','run_credit_low','run_credit_error','cancel_not_confirmed','cancel_error'), true );
			echo '<div class="notice notice-' . ( $bad ? 'error' : 'success' ) . ' inline"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
		}
		echo '<p>ارسال بدون تیک رضایت در تسویه‌حساب است. فقط برای سبدهای دارای موبایل معتبر و سفارش‌های پرداخت‌نشده؛ برای هر خرید یک صف دو مرحله‌ای ثبت می‌شود.</p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array('settings'=>'تنظیمات و تست','jobs'=>'صف و وضعیت‌ها','logs'=>'لاگ‌ها و خطاها','reports'=>'گزارش خرید پس از پیامک') as $slug => $label ) {
			echo '<a class="nav-tab ' . ( $slug === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array('page'=>'mcar-sms','tab'=>$slug), admin_url('admin.php') ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'settings' === $tab ) { self::settings_screen( $s ); }
		elseif ( 'jobs' === $tab ) { self::jobs_screen(); }
		elseif ( 'reports' === $tab ) { self::reports_screen(); }
		else { self::logs_screen(); }
		echo '</div>';
	}

	private static function settings_screen( $s ) {
		$next = wp_next_scheduled( 'mcar_scan_queue' );
		echo '<div class="notice notice-warning inline" style="margin-top:18px"><p><strong>مجوز و محدودیت:</strong> ارسال از طریق الگو فقط با bodyId تأییدشده و مجوز لازم در ملی پیامک انجام می‌شود. طبق محدودیت اعلامی ملی پیامک، الگوهای خدماتی اشتراکی لزوماً اجازه ارسال سبد رهاشده یا کد تخفیف ندارند. پیش از استفاده با پشتیبانی تأیید کنید؛ هیچ حالت fallback خودکار به ارسال عادی وجود ندارد. هیچ URL یا کد بازیابی اجباری نیست.</p></div>';
		echo '<p><strong>بررسی صف:</strong> حدود هر ۱ دقیقه با WP-Cron؛ اجرای برنامه‌ریزی‌شده بعدی: <code dir="ltr">' . esc_html( $next ? wp_date( 'Y-m-d H:i:s', $next ) : '—' ) . '</code>. در سایت کم‌ترافیک برای ارسال به‌موقع Cron سرور تنظیم کنید.</p>';
		$last = get_option( 'mcar_last_scan', array() );
		$last = is_array( $last ) ? $last : array();
		if ( isset( $last['credit_rials'] ) && is_numeric( $last['credit_rials'] ) ) {
			echo '<p><strong>آخرین موجودی استعلام‌شده:</strong> ' . esc_html( number_format( (float)$last['credit_rials'] / 10, 0 ) ) . ' تومان (برای اجرای صف باید بیش از ۲٬۰۰۰ تومان باشد).</p>';
		}
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		echo '<p><strong>وضعیت WP-Cron:</strong> ' . ( $cron_disabled ? '<strong style="color:#b32d2e">اجرای خودکار وردپرس غیرفعال است؛ باید Cron واقعی سرور تنظیم شود.</strong>' : 'در تنظیمات وردپرس غیرفعال نشده؛ اجرای واقعی همچنان وابسته به بازدید/هاست است.' ) . '</p>';
		if ( $next && $next < time() ) { echo '<p style="color:#b32d2e"><strong>هشدار:</strong> موعد رویداد کرون گذشته و هنوز اجرا نشده است.</p>'; }
		$hist = get_option( 'mcar_history_scan_state', array() );
		if ( is_array( $hist ) && $hist && '1' === (string) ( $s['history_enabled'] ?? '1' ) ) {
			echo '<p><strong>پایش خودکار سفارش‌های قدیمی:</strong> بررسی‌شده ' . absint( $hist['seen'] ?? 0 ) . ' | افزوده‌شده ' . absint( $hist['queued'] ?? 0 ) . ' | ' . ( ! empty( $hist['done'] ) ? 'تکمیل‌شده' : 'در حال بررسی تدریجی' ) . '</p>';
		}
		echo '<p><strong>آخرین اجرای ثبت‌شده پردازشگر:</strong> ' . ( ! empty( $last['time'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', (int)$last['time'] ) ) : 'هنوز ثبت نشده' ) . ' | وضعیت: ' . esc_html( $last['status'] ?? '—' ) . ' | موعددار: ' . (int)( $last['due'] ?? 0 ) . ' | وارد پردازش: ' . (int)( $last['processed'] ?? 0 ) . ( ! empty( $last['time_limited'] ) ? ' | <strong style="color:#b32d2e">محدودیت زمان اجرا؛ پردازش ناقص</strong>' : '' ) . '</p>';
		if ( in_array( (string)( $last['status'] ?? '' ), array( 'credit_low','credit_error' ), true ) ) {
			$credit_text = 'credit_low' === $last['status'] ? 'موجودی ملی پیامک حداکثر ۲٬۰۰۰ تومان است.' : 'سرویس استعلام ریالی ملی پیامک پاسخ معتبر نمی‌دهد.';
			echo '<div class="notice notice-warning inline"><p><strong>پردازش پیامک متوقف است:</strong> ' . esc_html( $credit_text ) . ' صف حفظ شده و پس از موفقیت استعلام و افزایش موجودی در اجرای بعدی ادامه می‌یابد.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mcar_save' );
		echo '<input type="hidden" name="action" value="mcar_save"><table class="form-table" role="presentation">';
		self::tr( 'فعال بودن افزونه', '<label><input type="checkbox" name="settings[enabled]" value="1" ' . checked( $s['enabled'], '1', false ) . '> ارسال خودکار فعال باشد</label><p class="description">پس از نصب، ارسال به‌صورت پیش‌فرض خاموش است.</p>' );
		self::tr( 'فاصله تا پیامک اول', '<input type="number" min="5" max="10080" name="settings[delay]" class="small-text" value="' . esc_attr( $s['delay'] ) . '"> دقیقه <p class="description">پیش‌فرض: ۳۰ دقیقه پس از آخرین فعالیت/ثبت سفارش پرداخت‌نشده.</p>' );
		self::tr( 'فاصله پیامک دوم', '<input type="number" min="5" max="10080" name="settings[second_delay]" class="small-text" value="' . esc_attr( $s['second_delay'] ) . '"> دقیقه <p class="description">پیش‌فرض: ۶۰ دقیقه پس از پذیرفته‌شدن پیامک اول؛ فقط اگر خرید تکمیل نشده باشد.</p>' );
		self::tr( 'حداکثر پردازش در هر نوبت', '<input type="number" min="3" max="50" name="settings[max_per_run]" class="small-text" value="' . esc_attr( $s['max_per_run'] ?? 10 ) . '"> رکورد در هر دقیقه <p class="description">پیش‌فرض ۱۰، قابل تنظیم از ۳ تا ۵۰. در هر نوبت حداکثر ۱ مورد برای سفارش تاریخی محفوظ است. یادآوری‌های جدیدِ موعددار در اولویت‌اند. سقف واقعی ممکن است با کندی پاسخ درگاه یا محدودیت زمان اجرای PHP کمتر باشد؛ زمان‌بندی WP-Cron به ترافیک سایت وابسته است.</p>' );
		self::tr( 'سفارش‌های پرداخت‌نشده قبلی', '<label><input type="checkbox" name="settings[history_enabled]" value="1" ' . checked( $s['history_enabled'] ?? '1', '1', false ) . '> سفارش‌های پرداخت‌نشده قبلی را به‌صورت خودکار در همین صف بررسی کن</label><p style="margin-top:9px">بازه جست‌وجو: <input type="number" min="1" max="365" class="small-text" name="settings[history_days]" value="' . esc_attr( $s['history_days'] ?? 30 ) . '"> روز گذشته</p><p class="description">پیش‌فرض ۳۰ روز؛ فقط pending و failed (و on-hold در صورت فعال‌بودن گزینه آن). در هر نوبت حداکثر ۱ سفارش تاریخی به صف افزوده و حداکثر ۱ مورد تاریخی پردازش می‌شود؛ پردازش عادی تا سقف تنظیم‌شده و با اولویت یادآوری‌های تازه انجام می‌شود. برای جلوگیری از انباشت، ورود سفارش‌های تاریخی با صف بزرگ‌تر متوقف می‌شود. برای هر شماره حداکثر یک مسیر تاریخی، بدون پیامک تکراری برای رکوردهای موجود. سبد قدیمی فقط در صورتی قابل بازیابی است که پیش‌تر ثبت شده و اطلاعاتش باقی مانده باشد. تا زمانی که ارسال خودکار خاموش است، این اسکن هم اجرا نمی‌شود.</p>' );
		self::tr( 'کنترل اعتبار قبل از هر پردازش', '<strong>فعال و الزامی: حداقل بیش از ۲٬۰۰۰ تومان (۲۰٬۰۰۰ ریال)</strong><p class="description">پیش از هر اجرای صف یک استعلام واقعی موجودی ریالی انجام می‌شود؛ اگر موجودی کمتر یا مساوی حد باشد یا استعلام خطا بدهد، هیچ رکوردی ارسال یا از صف خارج نمی‌شود. پس از افزایش اعتبار، در اجرای بعدی صف ادامه پیدا می‌کند. بررسی موجودی به پاسخ امن HTTPS سرویس GetUserCredit2 وابسته است.</p><p><button type="button" class="button" id="mcar-check-balance">استعلام موجودی همین حالا (بدون ارسال پیامک)</button> <span id="mcar-check-balance-result" role="status" aria-live="polite"></span></p>' );
		self::tr( 'نام کاربری ملی پیامک', '<input class="regular-text" dir="ltr" name="settings[username]" value="' . esc_attr( $s['username'] ) . '" autocomplete="off">' );
		self::tr( 'رمز وب‌سرویس ملی پیامک', '<input type="password" class="regular-text" name="settings[password]" autocomplete="new-password" placeholder="' . esc_attr( $s['password'] ? 'رمز قبلی ذخیره است؛ برای تغییر، رمز جدید وارد کنید.' : 'رمز را وارد کنید' ) . '"><p><label><input type="checkbox" name="settings[clear_password]" value="1"> حذف رمز ذخیره‌شده</label></p>' );
		self::tr( 'شماره خط فرستنده', '<input class="regular-text" dir="ltr" name="settings[sender]" value="' . esc_attr( $s['sender'] ) . '" placeholder="5000..."><p class="description">فقط در روش متن عادی لازم است؛ برای ارسال با الگو نیازی به شماره خط فرستنده نیست.</p>' );
		self::tr( 'تنظیمات پیامک اول', self::pattern_editor( 'first', 1, $s ) );
		self::tr( 'تنظیمات پیامک دوم', self::pattern_editor( 'second', 2, $s ) );
		$type = '<select name="settings[coupon_type]"><option value="percent" ' . selected( $s['coupon_type'], 'percent', false ) . '>درصدی</option><option value="fixed_cart" ' . selected( $s['coupon_type'], 'fixed_cart', false ) . '>مبلغ ثابت از کل سبد</option></select>';
		self::tr( 'مقدار تخفیف پیامک دوم', $type . ' <input name="settings[coupon_amount]" class="small-text" type="number" min="0.01" step="any" value="' . esc_attr( $s['coupon_amount'] ) . '"><p class="description">مبلغ ثابت به واحد پول تنظیم‌شده فروشگاه است (تومان/ریال/...).</p>' );
		self::tr( 'اعتبار کد تخفیف', '<strong>۲ ساعت از زمان ساخت کد، غیرقابل‌تغییر</strong><p class="description">هر کد یک‌بار قابلیت استفاده دارد و به‌صورت خودکار در ووکامرس ثبت می‌شود.</p>' );
		self::tr( 'شماره موبایل در حساب کاربران', '<p>ابتدا شماره ووکامرس <code>billing_phone</code> خوانده می‌شود. اگر افزونه ورود/ثبت‌نام شماره را در فیلد دیگری ذخیره می‌کند، کلید دقیق همان فیلد را اینجا وارد کنید.</p><label>کلید متای سفارشی: <input class="regular-text" dir="ltr" name="settings[phone_meta_key]" value="' . esc_attr( $s['phone_meta_key'] ?? '' ) . '" placeholder="mobile"></label><p><label><input type="checkbox" name="settings[phone_login_fallback]" value="1" ' . checked( $s['phone_login_fallback'] ?? '1', '1', false ) . '> اگر نام کاربری خود شماره موبایل معتبر است، از آن هم استفاده شود.</label></p>' );
		self::tr( 'وضعیت on-hold', '<label><input type="checkbox" name="settings[include_on_hold]" value="1" ' . checked( $s['include_on_hold'], '1', false ) . '> سفارش‌های «در انتظار بررسی» را هم شامل کن</label><p class="description">پیش‌فرض خاموش است؛ سفارش کارت‌به‌کارت ممکن است به اقدام فروشنده نیاز داشته باشد نه مشتری.</p>' );
		self::tr( 'بازه انتساب خرید به پیامک', '<input type="number" min="1" max="365" name="settings[attribution_days]" class="small-text" value="' . esc_attr( $s['attribution_days'] ?? 90 ) . '"> روز <p class="description">پیش‌فرض ۹۰ روز؛ خرید همان کالا با شماره یا حساب همان مشتری پس از پیامک، حتی یک هفته بعد، در گزارش ثبت می‌شود. این آمار رابطه زمانی را نشان می‌دهد و اثبات تأثیر پیامک نیست.</p>' );
		self::tr( 'شماره‌های عدم ارسال', '<textarea dir="ltr" rows="5" class="large-text" name="settings[blocked_numbers]" placeholder="0912...">' . esc_textarea( $s['blocked_numbers'] ) . '</textarea><p class="description">هر شماره در یک سطر. شماره انصراف‌داده را در اینجا ثبت کنید. دریافت خودکار پیامک لغو عضویت پیاده‌سازی نشده است.</p>' );
		echo '</table>';
		echo '<style>.mcar-stage-panel[hidden]{display:none!important}.mcar-stage-panel{margin:12px 0;padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#fff}</style>';
		wp_enqueue_script( 'mcar-admin-modes', MCAR_URL . 'assets/admin-modes.js', array(), MCAR_VERSION, true );
		wp_enqueue_script( 'mcar-pattern-preview', MCAR_URL . 'assets/admin-pattern-preview.js', array(), MCAR_VERSION, true );
		wp_localize_script( 'mcar-pattern-preview', 'MCARPatternPreview', array( 'ajax'=>admin_url( 'admin-ajax.php' ), 'nonce'=>wp_create_nonce( 'mcar_pattern_preview' ), 'credit_nonce'=>wp_create_nonce( 'mcar_credit_check' ), 'samples'=>self::preview_samples( $s ), 'currency_symbol'=>html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) );
		submit_button( 'ذخیره تنظیمات' );
		echo '</form><hr><h2>تشخیص سبد فعلی (مخصوص مدیر)</h2>';
		echo '<p>با همان مرورگری که محصول داخل سبد است، روی «بررسی و ثبت سبد فعلی» بزنید. این کار پیامک ارسال نمی‌کند. فقط سبد دارای موبایل معتبر ثبت می‌شود؛ سبد بدون شماره وارد صف نخواهد شد.</p>';
		echo '<button class="button button-secondary" type="button" id="mcar-diagnose-cart">بررسی و ثبت سبد فعلی</button><pre id="mcar-diagnose-result" style="max-width:740px;white-space:pre-wrap;direction:rtl;line-height:1.9;"></pre>';
		wp_enqueue_script( 'mcar-diagnostic', MCAR_URL . 'assets/admin-diagnostic.js', array(), MCAR_VERSION, true );
		wp_localize_script( 'mcar-diagnostic', 'MCARDiagnostic', array( 'ajax'=>admin_url( 'admin-ajax.php' ), 'nonce'=>wp_create_nonce( 'mcar_diagnose_cart' ) ) );
		echo '<hr><h2>آزمایش درگاه</h2><p>این تست طبق تنظیمات <strong>ذخیره‌شده</strong> مرحله انتخابی ارسال واقعی انجام می‌دهد و ممکن است هزینه داشته باشد. در تست مرحله دوم، کد <code>MCR-TEST12345</code> صرفاً آزمایشی است و در فروشگاه قابل استفاده نیست؛ کد واقعی فقط برای مشتریان صف ساخته می‌شود.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mcar_test' );
		echo '<input type="hidden" name="action" value="mcar_test"><label>شماره موبایل تست <input name="test_phone" type="tel" dir="ltr" required placeholder="09123456789"></label> &nbsp; <label>مرحله <select name="test_stage"><option value="1">اول</option><option value="2">دوم (کد تست نامعتبر)</option></select></label> ';
		submit_button( 'ارسال تست', 'secondary', 'submit', false );
		echo '</form>';
	}
	/**
	 * Only display the editor relevant to the chosen delivery method.
	 * Pattern mode sends the approved bodyId and positional values, never a free-form SMS.
	 */
	private static function preview_samples( $settings ) {
		$result = array();
		foreach ( array( 1, 2 ) as $stage ) {
			$job = array( 'stage'=>$stage, 'customer_name'=>'کاربر نمونه', 'kind'=>'order',
				'order_id'=>12345, 'total'=>250000, 'currency'=>get_woocommerce_currency() );
			$result[ $stage ] = MCAR_Core::replacement_values( $job, $settings, 2 === $stage ? 'MCR-TEST12345' : '' );
		}
		return $result;
	}

	private static function pattern_editor( $suffix, $stage, $settings ) {
		$mode = 'pattern' === ( $settings[ 'mode_' . $suffix ] ?? 'plain' ) ? 'pattern' : 'plain';
		$stage_id = 'mcar-stage-' . $suffix;
		$html = '<div class="mcar-stage-config" id="' . esc_attr( $stage_id ) . '">';
		$html .= '<label><strong>روش ارسال: </strong><select class="mcar-send-mode" name="settings[mode_' . esc_attr( $suffix ) . ']">'
			. '<option value="plain" ' . selected( $mode, 'plain', false ) . '>پیامک عادی</option>'
			. '<option value="pattern" ' . selected( $mode, 'pattern', false ) . '>الگو (Pattern)</option></select></label>';

		// A hidden panel remains in the DOM to preserve settings when switching modes.
		$html .= '<div class="mcar-stage-panel mcar-pattern-panel"' . ( 'pattern' === $mode ? '' : ' hidden' ) . '>';
		$html .= '<p><label><strong>شناسه الگوی تأییدشده (bodyId):</strong> <input type="number" min="1" max="2147483647" class="regular-text" style="max-width:180px" dir="ltr" name="settings[pattern_' . esc_attr( $suffix ) . '_id]" value="' . esc_attr( $settings[ 'pattern_' . $suffix . '_id' ] ?? '' ) . '" placeholder="مثلاً 123456"></label></p>';
		$html .= '<p class="description">متن کامل الگو در <strong>پنل ملی پیامک</strong> تعریف می‌شود. اینجا فقط متغیرها را به ترتیب همان الگو انتخاب کنید؛ افزونه صرفاً <code>bodyId</code> و مقادیر را به API می‌فرستد.</p>';
		$html .= '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">';
		$vars = MCAR_Core::pattern_keys( $settings, $stage );
		$labels = MCAR_Core::pattern_fields( $stage );
		for ( $i = 0; $i < 8; $i++ ) {
			$current = $vars[ $i ] ?? '';
			$html .= '<label style="display:flex;flex-direction:column;gap:3px">متغیر ' . ( $i+1 ) . '<select name="settings[pattern_' . esc_attr( $suffix ) . '_vars][]">';
			$html .= '<option value="">— ندارد —</option>';
			foreach ( $labels as $slug=>$name ) {
				$html .= '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '</select></label>';
		}
		$html .= '</div>';
		$html .= '<p class="description">ردیف‌ها را از شماره ۱ به‌ترتیب پر کنید؛ ردیف‌های اضافه را خالی بگذارید. لینک و کد بازیابی اجباری نیست.</p>';
		$html .= '<div class="mcar-preview-section" style="margin-top:14px;border-top:1px solid #ddd;padding-top:12px">';
		$html .= '<p><strong>پیش‌نمایش واقعی الگو (بدون ارسال پیامک)</strong></p>';
		$html .= '<p><button class="button mcar-preview-fetch" type="button">دریافت متن الگو از پنل ملی پیامک</button> <span class="mcar-preview-status" aria-live="polite"></span></p>';
		$html .= '<label>متن الگو، فقط برای پیش‌نمایش (اختیاری؛ در درخواست ارسال استفاده نمی‌شود):<br>';
		$html .= '<textarea class="large-text mcar-preview-template" rows="3" dir="rtl" name="settings[pattern_' . esc_attr( $suffix ) . '_preview]" placeholder="متن واقعی را از پنل دریافت یا اینجا جای‌گذاری کنید؛ مثل سلام {0} عزیز">' . esc_textarea( $settings[ 'pattern_' . $suffix . '_preview' ] ?? '' ) . '</textarea></label>';
		$html .= '<p class="description">دریافت از پنل ممکن است به API Key معتبر نیاز داشته باشد. متن بالا فقط برای نمایش در مدیریت ذخیره می‌شود و هنگام ارسال الگو، فقط مقادیر متغیرها و bodyId ارسال خواهند شد.</p>';
		$html .= '<div class="mcar-preview-render" role="status" dir="rtl" style="white-space:pre-wrap;line-height:1.9;background:#f6f7f7;padding:12px;border-radius:6px;min-height:52px"></div>';
		$html .= '</div>';

		if ( 2 === (int) $stage ) { $html .= '<p class="description">الگوی مرحله دوم باید حاوی جایگاهی برای <strong>کد تخفیف</strong> باشد. کوپن به‌صورت خودکار با اعتبار ۲ ساعت ساخته می‌شود.</p>'; }
		$html .= '</div>';

		$html .= '<div class="mcar-stage-panel mcar-plain-panel"' . ( 'plain' === $mode ? '' : ' hidden' ) . '>';
		$html .= '<p><label><strong>متن کامل پیامک عادی:</strong></label></p>';
		$html .= '<textarea class="large-text" rows="4" name="settings[message_' . esc_attr( $suffix ) . ']" dir="rtl">' . esc_textarea( $settings[ 'message_' . $suffix ] ?? '' ) . '</textarea>';
		$html .= '<p class="description">متغیرهای متن عادی: <code>{name}</code>، <code>{shop_name}</code>، <code>{cart_total}</code>، <code>{order_number}</code>';
		if ( 2 === (int) $stage ) { $html .= '، <code>{discount_amount}</code>، <code>{coupon_code}</code> (الزامی)'; }
		$html .= '. در حالت الگو، این متن ارسال نمی‌شود.</p></div></div>';
		return $html;
	}

	private static function tr( $label, $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // Escape controls at construction.
	}

	private static function jobs_screen() {
		global $wpdb;
		$table = MCAR_DB::table();
		$counts = $wpdb->get_results( "SELECT status,COUNT(*) AS count FROM $table GROUP BY status", ARRAY_A );
		echo '<h2>صف دو مرحله‌ای</h2><p>';
		foreach ( $counts as $item ) {
			echo '<strong>' . esc_html( self::status_label( $item['status'] ) ) . ':</strong> ' . (int)$item['count'] . ' &nbsp; ';
		}
		echo '</p><p>پذیرش درگاه به معنی تحویل قطعی گوشی نیست. موارد با «وضعیت نامشخص» خودکار دوباره ارسال نمی‌شوند تا پیامک تکراری نرود.</p>';
		$last = get_option( 'mcar_last_scan', array() );
		$last = is_array( $last ) ? $last : array();
		$due_now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status='active' AND due_at <= %s", current_time( 'mysql', true ) ) );
		echo '<p><strong>موارد موعددار در همین لحظه:</strong> ' . $due_now . ' | <strong>آخرین اجرای پردازشگر:</strong> ' . ( ! empty( $last['time'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', (int)$last['time'] ) ) : 'ثبت نشده' ) . ' | وضعیت: ' . esc_html( $last['status'] ?? '—' ) . '</p>';
		echo '<p class="description">فقط موارد فعال و موعددار پردازش می‌شوند. رکوردهای ارسال‌شده، لغوشده یا خطادار ممکن است برای گزارش‌دهی در فهرست بمانند و لزوماً پیامک تازه‌ای ندارند.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:15px 0">';
		wp_nonce_field( 'mcar_cancel_pending' );
		echo '<input type="hidden" name="action" value="mcar_cancel_pending">';
		echo '<label><input type="checkbox" name="confirm_cancel_pending" value="1" required> تأیید می‌کنم که پیامک‌های باقیمانده تمام رکوردهای «در صف» لغو شوند.</label> ';
		echo '<button class="button button-secondary" type="submit">لغو ارسال‌های باقی‌مانده صف</button> <span class="description">این کار اسکن تاریخی را هم متوقف می‌کند تا صف دوباره پر نشود؛ ارسال‌های قبلاً پذیرفته‌شده قابل بازگرداندن نیستند.</span></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:15px 0">';
		wp_nonce_field( 'mcar_run_now' );
		echo '<input type="hidden" name="action" value="mcar_run_now"><button type="submit" class="button button-primary">اجرای دستی صف همین حالا</button> <span class="description">این دکمه برای موارد موعددار درخواست ارسال پیامک واقعی می‌فرستد؛ فقط یک‌بار بزنید و سپس وضعیت و لاگ‌ها را بررسی کنید.</span></form>';
		$failed_count = (int)$wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='failed'" );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="padding:12px;margin:14px 0;border:1px solid #ccd0d4;background:#fff">';
		wp_nonce_field( 'mcar_retry_all' );
		echo '<input type="hidden" name="action" value="mcar_retry_all">';
		echo '<p><strong>تلاش مجدد همه خطاهای قطعی:</strong> ' . $failed_count . ' مورد</p>';
		echo '<p><label><input type="checkbox" name="confirm_retry_all" value="1" required> تأیید می‌کنم همه موارد دارای وضعیت «خطای قطعی» دوباره در صف قرار بگیرند.</label></p>';
		echo '<p><button type="submit" class="button button-secondary" ' . ( $failed_count ? '' : 'disabled' ) . '>تلاش مجدد همه</button> <span class="description">فقط رکوردهای failed؛ موارد uncertain، خرید تکمیل‌شده و پیامک‌های پذیرفته‌شده دوباره ارسال نمی‌شوند. ابتدا اعتبار پنل بررسی می‌شود؛ پردازش به‌صورت تدریجی با محدودیت تنظیم‌شده صف انجام می‌شود.</span></p></form>';
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per = 30;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
		$total = (int)$wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>نوع</th><th>مرحله</th><th>شماره</th><th>سفارش</th><th>زمان بعدی</th><th>وضعیت</th><th>اول</th><th>دوم</th><th>اقدام</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$order_html = '—';
			if ( $r['order_id'] ) {
				$order = wc_get_order( (int)$r['order_id'] );
				$order_html = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . (int)$r['order_id'] . '</a>' : '#' . (int)$r['order_id'];
			}
			echo '<tr><td>' . (int)$r['id'] . '</td><td>' . ( 'order' === $r['kind'] ? ( ! empty( $r['is_historical'] ) ? 'سفارش قدیمی' : 'سفارش' ) : 'سبد' ) . '</td><td>' . (int)$r['stage'] . ' از ۲</td>';
			echo '<td dir="ltr">' . esc_html( MCAR_DB::mask_phone( $r['phone'] ) ) . '</td><td>' . $order_html . '</td>';
			echo '<td dir="ltr">' . esc_html( get_date_from_gmt( $r['due_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $r['status'] ) ) . '</td>';
			echo '<td>' . ( $r['first_sent_at'] ? esc_html( get_date_from_gmt( $r['first_sent_at'], 'Y-m-d H:i' ) ) : '—' ) . '</td>';
			echo '<td>' . ( $r['second_sent_at'] ? esc_html( get_date_from_gmt( $r['second_sent_at'], 'Y-m-d H:i' ) ) : '—' ) . '</td><td>';
			if ( 'failed' === $r['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
				wp_nonce_field( 'mcar_retry' );
				echo '<input type="hidden" name="action" value="mcar_retry"><input type="hidden" name="job_id" value="' . (int)$r['id'] . '"><button class="button button-small" type="submit">تلاش مجدد</button></form>';
			} else { echo '—'; }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="10">هنوز موردی ثبت نشده است.</td></tr>'; }
		echo '</tbody></table>';
		self::pagination( $total, $per, $page, 'jobs' );
	}

	private static function reports_screen() {
		global $wpdb;
		$t = MCAR_DB::recoveries_table();
		$touch = MCAR_DB::touch_table();
		$days = absint( $_GET['days'] ?? 90 );
		if ( ! in_array( $days, array( 7,30,90,365 ), true ) ) { $days = 90; }
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		echo '<h2>خریدهای انجام‌شده بعد از پیامک</h2>';
		echo '<p>این گزارش، پرداخت <strong>همان محصول یا تنوع محصول</strong> را توسط همان مشتری (بر اساس شماره ناشناس‌شده یا حساب کاربری) حتی در روزهای بعد شناسایی می‌کند. این یک <strong>تطبیق زمانی</strong> است، نه اثبات اینکه پیامک علت خرید بوده یا پیامک به گوشی رسیده است.</p>';
		echo '<p>بازه گزارش: ';
		foreach ( array( 7=>'۷ روز',30=>'۳۰ روز',90=>'۹۰ روز',365=>'۳۶۵ روز' ) as $day=>$label ) {
			echo ' <a class="button ' . ( $days === $day ? 'button-primary' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page'=>'mcar-sms','tab'=>'reports','days'=>$day ), admin_url('admin.php') ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</p>';
		$stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS orders_count, COUNT(DISTINCT t.phone_hash) AS people_count, SUM(CASE WHEN r.stage=1 THEN 1 ELSE 0 END) AS after_first, SUM(CASE WHEN r.stage=2 THEN 1 ELSE 0 END) AS after_second, SUM(r.coupon_used) AS coupon_used FROM $t r LEFT JOIN $touch t ON r.job_id=t.job_id WHERE r.paid_at >= %s", $since ), ARRAY_A );
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:14px 0">';
		foreach ( array(
			'سفارش‌های مرتبط'=>absint($stats['orders_count'] ?? 0),
			'گیرندگان یکتا'=>absint($stats['people_count'] ?? 0),
			'پس از پیامک اول'=>absint($stats['after_first'] ?? 0),
			'پس از پیامک دوم'=>absint($stats['after_second'] ?? 0),
			'استفاده از کد اختصاصی'=>absint($stats['coupon_used'] ?? 0),
		) as $label=>$value ) {
			echo '<div style="padding:14px 18px;min-width:145px;border:1px solid #ddd;background:white;border-radius:6px"><div style="color:#646970">' . esc_html($label) . '</div><div style="font-size:24px;font-weight:bold;margin-top:6px">' . number_format_i18n($value) . '</div></div>';
		}
		echo '</div>';
		$money = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(matched_revenue) AS matched_total, SUM(order_total) AS order_total FROM $t WHERE paid_at >= %s GROUP BY currency ORDER BY currency", $since ), ARRAY_A );
		if ( $money ) {
			echo '<h3>مبلغ فروش مرتبط به تفکیک ارز</h3><table class="widefat striped" style="max-width:700px"><thead><tr><th>واحد پول</th><th>مبلغ کالاهای تطبیق‌یافته</th><th>کل سفارش‌های مرتبط</th></tr></thead><tbody>';
			foreach ( $money as $m ) {
				echo '<tr><td>' . esc_html($m['currency']) . '</td><td>' . wp_kses_post( wc_price( (float)$m['matched_total'], array( 'currency'=>$m['currency'] ) ) ) . '</td><td>' . wp_kses_post( wc_price( (float)$m['order_total'], array( 'currency'=>$m['currency'] ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">«مبلغ کالاهای تطبیق‌یافته» جمع سطر کالاهای مشترک با سبد/سفارش اولیه، پس از تخفیف و با مالیات آن سطر است؛ حمل‌ونقل شامل نمی‌شود. مبلغ کل سفارش جداگانه نمایش داده می‌شود. مقادیر ممکن است با مرجوعی جزئی تغییر نکنند. بازه انتساب قابل تنظیم در «تنظیمات و تست» است و مستقل از فیلتر زمانی همین صفحه است.</p>';
		echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin:16px 0">';
		wp_nonce_field( 'mcar_rebuild_report' );
		echo '<input type="hidden" name="action" value="mcar_rebuild_report"><button class="button" type="submit">بازسازی یک دسته از گزارش‌های قدیمی (بدون ارسال پیامک)</button></form>';
		$state = get_option( 'mcar_report_scan_state', array() );
		echo '<p class="description">بررسی خودکار سفارش‌های پرداخت‌شده حدود هر ۱۰ دقیقه هم انجام می‌شود. پیشرفت مرور تاریخی: ' . absint( is_array($state) ? ($state['offset']??0) : 0 ) . ' سفارش در دور جاری. سفارش‌های قدیمیِ مرتبط با پیامک تنها وقتی قابل تطبیق محصولی هستند که فهرست کالاهای اولیه در صف یا سفارش مرتبط قابل بازیابی باشد.</p>';
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per = 30;
		$total = (int)$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE paid_at >= %s", $since ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE paid_at >= %s ORDER BY paid_at DESC LIMIT %d OFFSET %d", $since, $per, ( $page-1 )*$per ), ARRAY_A );
		echo '<h3>لیست سفارش‌های شناسایی‌شده</h3><table class="widefat striped"><thead><tr><th>تاریخ پرداخت</th><th>سفارش</th><th>مشتری</th><th>محصول مشترک</th><th>مرحله پیامک</th><th>صف منبع</th><th>مبلغ کالاها</th><th>مبلغ سفارش</th><th>استفاده از کوپن</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$order = wc_get_order( (int)$r['recovered_order_id'] );
			$order_url = $order ? $order->get_edit_order_url() : '';
			$name = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '';
			$phone = $order ? MCAR_DB::mask_phone( (string)$order->get_billing_phone() ) : '***';
			$ids = json_decode( $r['matched_product_ids'], true );
			$product_names = array();
			foreach ( (array)$ids as $id ) {
				$product = wc_get_product( absint($id) );
				$product_names[] = $product ? $product->get_name() : '#' . absint($id);
			}
			echo '<tr><td dir="ltr">' . esc_html( get_date_from_gmt( $r['paid_at'], 'Y-m-d H:i' ) ) . '</td><td>';
			if ( $order_url ) { echo '<a href="' . esc_url($order_url) . '">#' . absint($r['recovered_order_id']) . '</a>'; }
			else { echo '#' . absint($r['recovered_order_id']); }
			echo '</td><td>' . esc_html( $name ?: 'مشتری' ) . ' <small dir="ltr">' . esc_html($phone) . '</small></td><td>' . esc_html( implode('، ', $product_names) ) . '</td><td>' . ( 2 === (int)$r['stage'] ? 'دوم' : 'اول' ) . '</td><td>#' . absint($r['job_id']) . '</td><td>' . wp_kses_post(wc_price((float)$r['matched_revenue'], array('currency'=>$r['currency']))) . '</td><td>' . wp_kses_post(wc_price((float)$r['order_total'], array('currency'=>$r['currency']))) . '</td><td>' . ( $r['coupon_used'] ? 'بله' : 'خیر' ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="9">هنوز خرید قابل تطبیقی شناسایی نشده است.</td></tr>'; }
		echo '</tbody></table>';
		self::pagination( $total, $per, $page, 'reports', array('days'=>$days) );
	}

	private static function logs_screen() {
		global $wpdb;
		$table = MCAR_DB::logs_table();
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per = 40;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page-1 )*$per ), ARRAY_A );
		$total = (int)$wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		echo '<h2>گزارش ارسال و خطا</h2><p>رمز، کد تخفیف، پیام کامل و شماره کامل گیرنده در لاگ ذخیره نمی‌شود. گزارش‌ها بعد از ۴۵ روز پاک می‌شوند.</p>';
		echo '<table class="widefat striped"><thead><tr><th>تاریخ</th><th>سطح</th><th>رویداد</th><th>شناسه صف</th><th>توضیح</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td dir="ltr">' . esc_html( get_date_from_gmt( $r['created_at'], 'Y-m-d H:i:s' ) ) . '</td><td>' . esc_html( $r['level'] ) . '</td><td><code>' . esc_html( $r['event'] ) . '</code></td><td>' . (int)$r['job_id'] . '</td><td>' . esc_html( $r['details'] ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="5">هنوز گزارشی ثبت نشده است.</td></tr>'; }
		echo '</tbody></table>';
		self::pagination( $total, $per, $page, 'logs' );
	}
	private static function pagination( $total, $per, $page, $tab, $extra = array() ) {
		$pages = (int)ceil( $total / $per );
		if ( $pages < 2 ) { return; }
		echo '<p style="margin-top:14px">صفحه ' . $page . ' از ' . $pages . ' &nbsp; ';
		if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array('page'=>'mcar-sms','tab'=>$tab,'paged'=>$page-1), $extra ), admin_url('admin.php') ) ) . '">قبلی</a> '; }
		if ( $page < $pages ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array('page'=>'mcar-sms','tab'=>$tab,'paged'=>$page+1), $extra ), admin_url('admin.php') ) ) . '">بعدی</a>'; }
		echo '</p>';
	}
}
