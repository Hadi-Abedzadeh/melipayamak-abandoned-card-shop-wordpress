<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** REST client for ordinary SMS and approved Meli Payamak bodyId patterns. */
final class MCAR_Meli {
	public static function normalize_phone( $phone ) {
		$phone = strtr( (string) $phone, array(
			'۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
			'٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
		) );
		$phone = preg_replace( '/[^0-9+]/', '', $phone );
		if ( strpos( $phone, '+98' ) === 0 ) { $phone = '0' . substr( $phone, 3 ); }
		elseif ( strpos( $phone, '0098' ) === 0 ) { $phone = '0' . substr( $phone, 4 ); }
		elseif ( strpos( $phone, '98' ) === 0 && strlen( $phone ) === 12 ) { $phone = '0' . substr( $phone, 2 ); }
		elseif ( strpos( $phone, '9' ) === 0 && strlen( $phone ) === 10 ) { $phone = '0' . $phone; }
		return preg_match( '/^09[0-9]{9}$/D', $phone ) ? $phone : '';
	}

	/** @return array{provider_id:string}|WP_Error */
	public static function send( $phone, $message ) {
		$s = MCAR_DB::settings();
		if ( empty( $s['sender'] ) ) {
			return new WP_Error( 'mcar_config', 'برای ارسال عادی، شماره خط فرستنده را تنظیم کنید.' );
		}
		if ( '' === trim( (string) $message ) ) {
			return new WP_Error( 'mcar_empty', 'متن پیامک عادی خالی است.' );
		}
		return self::request( 'SendSMS', $phone, array(
			'from' => $s['sender'],
			'text' => (string) $message,
			'isflash' => 'false',
		) );
	}

	/**
	 * Send an already-approved bodyId pattern via the documented REST endpoint.
	 * REST API parameter `text` contains ONLY positional variable VALUES joined by `;`.
	 * No rendered SMS body is built or transmitted in Pattern mode.
	 * No sender/URL/recovery code is required by this endpoint.
	 * @param string[] $values
	 * @return array{provider_id:string}|WP_Error
	 */
	public static function send_pattern( $phone, $body_id, $values ) {
		$body_id = absint( $body_id );
		if ( ! $body_id ) {
			return new WP_Error( 'mcar_pattern_id', 'شناسه الگو (bodyId) معتبر نیست.' );
		}
		if ( ! is_array( $values ) || ! count( $values ) || count( $values ) > 8 ) {
			return new WP_Error( 'mcar_pattern_variables', 'تعداد متغیرهای الگو نامعتبر است.' );
		}
		$clean = array();
		foreach ( $values as $value ) {
			// Semicolons delimit API arguments; reject them instead of changing their positions.
			$value = trim( preg_replace( '/[\r\n\t]+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
			if ( '' === $value || strpos( $value, ';' ) !== false ) {
				return new WP_Error( 'mcar_pattern_value', 'یک متغیر الگو خالی است یا جداکننده غیرمجاز دارد.' );
			}
			if ( preg_match( '~(?:https?://|www\.|ftp://)~iu', $value ) ) {
				return new WP_Error( 'mcar_pattern_link', 'مقدار متغیر الگو نباید شامل لینک باشد.' );
			}
			$clean[] = $value;
		}
		return self::request( 'BaseServiceNumber', $phone, array(
			'bodyId' => (string) $body_id,
			'text' => implode( ';', $clean ), // API field name is `text`; content is values only.
		) );
	}

	/**
	 * Query monetary wallet balance in RIAL using the documented GetUserCredit2
	 * ASP.NET SOAP operation. The similar SendSMS/GetCredit endpoint is in SMS
	 * units and MUST NOT be used for a 2,000-toman threshold.
	 * Fail closed on transport, authentication, or ambiguous responses.
	 * @return float|WP_Error Balance in rials.
	 */
	public static function get_balance_rials() {
		$s = MCAR_DB::settings();
		if ( empty( $s['username'] ) || empty( $s['password'] ) ) {
			return new WP_Error( 'mcar_credit_config', 'اطلاعات اتصال ملی پیامک تنظیم نشده است.' );
		}
		// Never send credentials over plaintext HTTP. SSL validation remains enabled.
		$xml = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
			. 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
			. 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><GetUserCredit2 xmlns="http://tempuri.org/">'
			. '<username>' . htmlspecialchars( (string) $s['username'], ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</username>'
			. '<password>' . htmlspecialchars( (string) $s['password'], ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</password>'
			. '</GetUserCredit2></soap:Body></soap:Envelope>';
		$response = wp_remote_post( 'https://api.payamak-panel.com/post/Users.asmx', array(
			'timeout'=>12, 'redirection'=>0, 'sslverify'=>true,
			'headers'=>array(
				'Content-Type'=>'text/xml; charset=utf-8',
				'SOAPAction'=>'"http://tempuri.org/GetUserCredit2"',
			),
			'body'=>$xml,
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mcar_credit_transport', 'استعلام اعتبار ملی پیامک برقرار نشد (خطای ارتباطی/SSL).' );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http ) {
			return new WP_Error( 'mcar_credit_http', 'سرویس موجودی ملی پیامک پاسخ HTTP ' . $http . ' داد.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		// Extract only the exact SOAP result, never assume an HTTP 200 means success.
		if ( ! preg_match( '~<(?:[A-Za-z_][\\w.-]*:)?GetUserCredit2Result(?:\\s[^>]*)?>([^<]{1,100})</(?:[A-Za-z_][\\w.-]*:)?GetUserCredit2Result\\s*>~', $body, $matches ) ) {
			return new WP_Error( 'mcar_credit_response', 'پاسخ استعلام اعتبار معتبر نیست؛ ارسال برای حفظ موجودی متوقف شد.' );
		}
		$value = trim( html_entity_decode( $matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		// The documented authentication error is -1, not a usable balance.
		if ( ! preg_match( '/^(?:0|[0-9]+)(?:\\.[0-9]+)?$/D', $value ) ) {
			return new WP_Error( 'mcar_credit_invalid', 'اعتبار پنل دریافت نشد (احتمال مشکل احراز هویت یا پاسخ سرویس).' );
		}
		$balance = (float) $value;
		if ( ! is_finite( $balance ) ) {
			return new WP_Error( 'mcar_credit_invalid', 'مقدار اعتبار معتبر نیست.' );
		}
		return $balance;
	}

	/** Fetch the actual configured pattern body from the provider for ADMIN preview only.
	 * The preview text is NEVER used as an SMS payload in pattern mode.
	 */
	public static function pattern_details( $body_id ) {
		$s = MCAR_DB::settings();
		$body_id = absint( $body_id );
		if ( ! $body_id || empty( $s['username'] ) || empty( $s['password'] ) ) {
			return new WP_Error( 'mcar_pattern_lookup_config', 'شناسه الگو یا مشخصات وب‌سرویس معتبر نیست.' );
		}
		$response = wp_remote_post( 'https://rest.payamak-panel.com/api/ShareService/GetBodies', array(
			'timeout'=>15, 'redirection'=>0, 'sslverify'=>true,
			'headers'=>array( 'Content-Type'=>'application/json; charset=utf-8', 'Accept'=>'application/json' ),
			'body'=>wp_json_encode( array( 'username'=>$s['username'], 'password'=>$s['password'], 'keyword'=>(string)$body_id, 'status'=>-1 ), JSON_UNESCAPED_UNICODE ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mcar_pattern_lookup_http', 'ارتباط با سرویس فهرست الگوها برقرار نشد.' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'mcar_pattern_lookup_http', 'سرویس فهرست الگوها پاسخ موفق نداد.' );
		}
		$data = json_decode( (string)wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || (int)( $data['MyBase']['RetStatus'] ?? -1 ) !== 1 || ! isset( $data['Data'] ) || ! is_array( $data['Data'] ) ) {
			return new WP_Error( 'mcar_pattern_lookup_error', 'دریافت الگو موفق نبود؛ احتمالاً سرویس به API Key یا مجوز دسترسی دیگری نیاز دارد.' );
		}
		foreach ( $data['Data'] as $pattern ) {
			if ( is_array( $pattern ) && (int)( $pattern['BodyID'] ?? 0 ) === $body_id ) {
				return array(
					'body'=>sanitize_textarea_field( (string)( $pattern['Body'] ?? '' ) ),
					'title'=>sanitize_text_field( (string)( $pattern['Title'] ?? '' ) ),
					'body_status'=>(int)( $pattern['BodyStatus'] ?? -1 ),
				);
			}
		}
		return new WP_Error( 'mcar_pattern_not_found', 'الگویی با این شناسه در حساب ملی پیامک پیدا نشد.' );
	}

	private static function request( $method, $phone, $params ) {
		$s = MCAR_DB::settings();
		if ( empty( $s['username'] ) || empty( $s['password'] ) ) {
			return new WP_Error( 'mcar_config', 'نام کاربری و رمز وب‌سرویس ملی پیامک را تکمیل کنید.' );
		}
		$phone = self::normalize_phone( $phone );
		if ( ! $phone ) { return new WP_Error( 'mcar_phone', 'شماره موبایل معتبر نیست.' ); }
		$response = wp_remote_post( 'https://rest.payamak-panel.com/api/SendSMS/' . $method, array(
			'timeout' => 15,
			'redirection' => 0,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ),
			'body' => array_merge( array(
				'username' => $s['username'], 'password' => $s['password'], 'to' => $phone,
			), $params ),
		) );
		if ( is_wp_error( $response ) ) {
			// Provider may have accepted it before timeout: never silently auto-retry.
			return new WP_Error( 'mcar_uncertain', 'ارتباط با درگاه قطع شد یا به پایان رسید؛ وضعیت ارسال نامشخص است.' );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( $http < 200 || $http >= 300 ) {
			return new WP_Error( 'mcar_uncertain', 'HTTP ' . $http . ' از درگاه؛ وضعیت ارسال نامشخص است.' );
		}
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		$data = json_decode( $body, true );
		$value = is_array( $data ) ? (string) ( $data['Value'] ?? $data['value'] ?? '' ) : trim( $body, "\" \t\n\r" );
		$value = trim( $value );
		// Official provider docs: a numeric receipt ID longer than 15 digits indicates acceptance.
		if ( preg_match( '/^[0-9]{16,40}$/D', $value ) ) {
			return array( 'provider_id' => $value );
		}
		if ( preg_match( '/^-?[0-9]{1,8}$/D', $value ) ) {
			return new WP_Error( 'mcar_rejected', 'درگاه درخواست را رد کرد. کد پاسخ: ' . $value );
		}
		$status = is_array( $data ) ? (string) ( $data['RetStatus'] ?? $data['retStatus'] ?? 'نامشخص' ) : 'نامشخص';
		// Unrecognized answer is ambiguous; never retry automatically to avoid duplicate SMS.
		return new WP_Error( 'mcar_uncertain', 'پاسخ نامشخص از درگاه (وضعیت: ' . substr( sanitize_text_field( $status ), 0, 25 ) . '). ارسال مجدد خودکار انجام نمی‌شود.' );
	}
}
