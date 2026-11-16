<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Product-level, post-SMS purchase matching. This measures correlation, not
 * causation or provider-confirmed delivery. Never stores full phone numbers.
 */
final class MCAR_Attribution {
	public static function register() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_completed' ), 50, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 50, 3 );
		add_action( 'mcar_reconcile_reports', array( __CLASS__, 'reconcile' ) );
	}

	private static function hash_phone( $phone ) {
		$normalized = MCAR_Meli::normalize_phone( $phone );
		return $normalized ? hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) ) : '';
	}

	private static function item_ids_from_cart( $cart ) {
		$ids = array();
		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) { return $ids; }
		foreach ( $cart->get_cart() as $line ) {
			$id = absint( ! empty( $line['variation_id'] ) ? $line['variation_id'] : ( $line['product_id'] ?? 0 ) );
			if ( $id ) { $ids[$id] = $id; }
		}
		return array_values( $ids );
	}

	private static function item_ids_from_order( $order ) {
		$ids = array();
		if ( ! $order instanceof WC_Order ) { return $ids; }
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$id = absint( $line->get_variation_id() ?: $line->get_product_id() );
			if ( $id ) { $ids[$id] = $id; }
		}
		return array_values( $ids );
	}

	private static function snapshot( $job_id, $phone, $customer_id, $product_ids, $source_order_id = 0, $sent_first = null, $sent_second = null ) {
		global $wpdb;
		$job_id = absint( $job_id );
		$phone_hash = self::hash_phone( $phone );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		if ( ! $job_id || ! $phone_hash || ! $ids ) { return false; }
		$table = MCAR_DB::touch_table();
		$now = current_time( 'mysql', true );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE job_id=%d", $job_id ), ARRAY_A );
		if ( $old ) {
			// Once a reminder has been accepted, freeze its original product selection.
			$data = array( 'updated_at'=>$now );
			if ( empty( $old['first_sent_at'] ) ) {
				$data['product_ids'] = wp_json_encode( $ids );
				$data['phone_hash'] = $phone_hash;
				$data['customer_id'] = absint( $customer_id );
			}
			if ( $source_order_id ) { $data['source_order_id'] = absint( $source_order_id ); }
			if ( $sent_first && empty( $old['first_sent_at'] ) ) { $data['first_sent_at'] = $sent_first; }
			if ( $sent_second && empty( $old['second_sent_at'] ) ) { $data['second_sent_at'] = $sent_second; }
			return false !== $wpdb->update( $table, $data, array( 'job_id'=>$job_id ) );
		}
		return (bool) $wpdb->insert( $table, array(
			'job_id'=>$job_id, 'phone_hash'=>$phone_hash, 'customer_id'=>absint( $customer_id ),
			'source_order_id'=>absint( $source_order_id ), 'coupon_id'=>0, 'product_ids'=>wp_json_encode( $ids ),
			'first_sent_at'=>$sent_first, 'second_sent_at'=>$sent_second,
			'created_at'=>$now, 'updated_at'=>$now,
		) );
	}

	public static function capture_cart( $job_id, $phone ) {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		return self::snapshot( $job_id, $phone, get_current_user_id(), self::item_ids_from_cart( $cart ) );
	}

	public static function capture_order( $job_id, $order, $phone_override = '' ) {
		if ( ! $order instanceof WC_Order ) { return false; }
		return self::snapshot( $job_id, $phone_override ?: $order->get_billing_phone(), $order->get_customer_id(),
			self::item_ids_from_order( $order ), $order->get_id() );
	}

	/** Backfill sent jobs with an order reference, or synchronize accepted timestamps. */
	public static function sync_job( $job_id ) {
		global $wpdb;
		$job_id = absint( $job_id );
		if ( ! $job_id ) { return false; }
		$table = MCAR_DB::table();
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $job_id ), ARRAY_A );
		if ( ! $job ) { return false; }
		$touch = MCAR_DB::touch_table();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $touch WHERE job_id=%d", $job_id ) );
		if ( ! $exists && ! empty( $job['order_id'] ) ) {
			$order = wc_get_order( (int) $job['order_id'] );
			if ( $order ) {
				self::snapshot( $job_id, $job['phone'], $order->get_customer_id(), self::item_ids_from_order( $order ),
				$order->get_id(), $job['first_sent_at'], $job['second_sent_at'] );
			}
		}
		if ( $exists || ! empty( $job['order_id'] ) ) {
			$data = array( 'updated_at'=>current_time( 'mysql', true ), 'coupon_id'=>absint( $job['coupon_id'] ) );
			if ( ! empty( $job['first_sent_at'] ) ) { $data['first_sent_at'] = $job['first_sent_at']; }
			if ( ! empty( $job['second_sent_at'] ) ) { $data['second_sent_at'] = $job['second_sent_at']; }
			if ( ! empty( $job['order_id'] ) ) { $data['source_order_id'] = absint( $job['order_id'] ); }
			$wpdb->update( $touch, $data, array( 'job_id'=>$job_id ) );
		}
		return true;
	}

	public static function accepted( $job_id, $stage, $accepted_at ) {
		self::sync_job( $job_id );
		global $wpdb;
		$col = 2 === (int)$stage ? 'second_sent_at' : 'first_sent_at';
		if ( 2 === (int)$stage ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::touch_table() . ' SET coupon_id=(SELECT coupon_id FROM ' . MCAR_DB::table() . ' WHERE id=%d) WHERE job_id=%d', absint($job_id), absint($job_id) ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . MCAR_DB::touch_table() . " SET $col=COALESCE($col,%s),updated_at=%s WHERE job_id=%d",
			$accepted_at, current_time( 'mysql', true ), absint( $job_id ) ) );
	}

	public static function payment_completed( $order_id ) {
		self::consider_order( wc_get_order( absint( $order_id ) ) );
	}

	public static function status_changed( $order_id, $from, $to ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) { return; }
		if ( $order->is_paid() ) { self::consider_order( $order ); }
		else {
			global $wpdb;
			$wpdb->delete( MCAR_DB::recoveries_table(), array( 'recovered_order_id'=>$order->get_id() ), array('%d') );
		}
	}

	/** A paid order must match the same customer AND at least one exact product/variation. */
	public static function consider_order( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->is_paid() ) { return false; }
		$paid = $order->get_date_paid();
		// A reliable payment timestamp is essential for temporal attribution.
		if ( ! $paid ) { return false; }
		$paid_at = gmdate( 'Y-m-d H:i:s', $paid->getTimestamp() );
		$paid_ts = $paid->getTimestamp();
		$customer_id = absint( $order->get_customer_id() );
		$phone_hash = self::hash_phone( $order->get_billing_phone() );
		if ( ! $phone_hash && ! $customer_id ) { return false; }
		global $wpdb;
		$conversions = MCAR_DB::recoveries_table();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $conversions WHERE recovered_order_id=%d", $order->get_id() ) ) ) { return false; }
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$id = absint( $item->get_variation_id() ?: $item->get_product_id() );
			if ( $id ) {
				$items[$id] = ( $items[$id] ?? 0 ) + (float)$item->get_total() + (float)$item->get_total_tax();
			}
		}
		if ( ! $items ) { return false; }
		$days = max( 1, min( 365, absint( MCAR_DB::settings()['attribution_days'] ?? 90 ) ) );
		$after = gmdate( 'Y-m-d H:i:s', $paid_ts - $days * DAY_IN_SECONDS );
		$touches = MCAR_DB::touch_table();
		$conditions = array();
		$params = array( $paid_at, $after );
		if ( $phone_hash ) { $conditions[] = 'phone_hash=%s'; $params[] = $phone_hash; }
		if ( $customer_id ) { $conditions[] = 'customer_id=%d'; $params[] = $customer_id; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $touches WHERE first_sent_at IS NOT NULL AND first_sent_at<=%s AND first_sent_at>=%s AND (" . implode( ' OR ', $conditions ) . ") ORDER BY first_sent_at DESC LIMIT 200", $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) { return false; }
		foreach ( $rows as $touch ) {
			// If both sides have a registered account, never attribute across accounts.
			if ( $customer_id && (int)$touch['customer_id'] && $customer_id !== (int)$touch['customer_id'] ) { continue; }
			$stored = json_decode( (string) $touch['product_ids'], true );
			if ( ! is_array( $stored ) ) { continue; }
			$matched = array_values( array_intersect( array_keys( $items ), array_map( 'absint', $stored ) ) );
			if ( ! $matched ) { continue; }
			// One recovery per abandoned shopping episode; never count later repeat buys.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $conversions WHERE job_id=%d", $touch['job_id'] ) ) ) { return false; }
			$matched_revenue = 0;
			foreach ( $matched as $id ) { $matched_revenue += $items[$id]; }
			$second = $touch['second_sent_at'] ? strtotime( $touch['second_sent_at'] . ' UTC' ) : 0;
			$stage = $second && $second <= $paid_ts ? 2 : 1;
			$coupon_used = 0;
			if ( absint( $touch['coupon_id'] ?? 0 ) ) {
				foreach ( $order->get_coupon_codes() as $coupon_code ) {
					if ( absint( wc_get_coupon_id_by_code( $coupon_code ) ) === absint( $touch['coupon_id'] ) ) { $coupon_used = 1; break; }
				}
			}
			$inserted = $wpdb->insert( $conversions, array(
				'recovered_order_id'=>$order->get_id(), 'job_id'=>(int)$touch['job_id'],
				'customer_id'=>$customer_id, 'stage'=>$stage,
				'matched_product_ids'=>wp_json_encode( $matched ),
				'matched_revenue'=>wc_format_decimal( $matched_revenue ),
				'order_total'=>wc_format_decimal( $order->get_total() ),
				'currency'=>substr( $order->get_currency(), 0, 10 ),
				'coupon_used'=>$coupon_used, 'paid_at'=>$paid_at,
				'created_at'=>current_time( 'mysql', true ),
			) );
			if ( $inserted ) { MCAR_DB::log( 'recovery_matched', 'خرید پس از پیامک با محصول یکسان شناسایی شد؛ سفارش #' . $order->get_id() . '، صف #' . $touch['job_id'] . '.' ); }
			return (bool) $inserted;
		}
		return false;
	}

	/** Bounded, resumable backfill: old order jobs and already-paid orders. */
	public static function reconcile() {
		if ( ! function_exists( 'wc_get_orders' ) ) { return; }
		global $wpdb;
		$jobs = MCAR_DB::table();
		$touches = MCAR_DB::touch_table();
		$unsynced = $wpdb->get_col( "SELECT j.id FROM $jobs j LEFT JOIN $touches t ON t.job_id=j.id WHERE j.order_id>0 AND j.first_sent_at IS NOT NULL AND t.job_id IS NULL ORDER BY j.id DESC LIMIT 60" );
		foreach ( (array)$unsynced as $job_id ) { self::sync_job( (int)$job_id ); }
		// Scan one bounded slice per run. Payment hooks handle all new orders instantly.
		$days = max( 1, min( 365, absint( MCAR_DB::settings()['attribution_days'] ?? 90 ) ) );
		$state = get_option( 'mcar_report_scan_state', array() );
		if ( ! is_array( $state ) || (int)( $state['days'] ?? 0 ) !== $days || empty( $state['started'] ) || time() - (int)$state['started'] > DAY_IN_SECONDS ) {
			$state = array( 'days'=>$days, 'started'=>time(), 'offset'=>0 );
		}
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$orders = wc_get_orders( array( 'type'=>'shop_order', 'status'=>wc_get_is_paid_statuses(),
			'date_paid'=>$from . '...' . gmdate( 'Y-m-d', time() ),
			'orderby'=>'date', 'order'=>'DESC', 'limit'=>75, 'offset'=>absint( $state['offset'] ), 'return'=>'objects' ) );
		foreach ( (array)$orders as $order ) { self::consider_order( $order ); }
		$state['offset'] += is_array( $orders ) ? count( $orders ) : 0;
		if ( ! is_array( $orders ) || count( $orders ) < 75 ) { $state['started'] = time(); $state['offset'] = 0; }
		update_option( 'mcar_report_scan_state', $state, false );
	}
}
