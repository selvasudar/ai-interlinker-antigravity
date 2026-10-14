<?php
/**
 * Estimated monthly budget cap with atomic reservations.
 *
 * @package AILSUG
 */

namespace AILSUG\Cost;

use AILSUG\Activator;
use AILSUG\Queue\Queue;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Before each AI call the worst-case cost is reserved with one conditional UPDATE
 * (reserved + r WHERE spent + reserved + r <= cap). No row updated = no call.
 * After the call, the reservation is released and the actual estimated cost is added to spent.
 *
 * On failure: the reservation is released immediately, except for network/timeout/upstream
 * errors where the provider may still bill. Those are held for HOLD_MINUTES and then released
 * by the cleanup job, because the real cost of such a call is never reported back.
 *
 * This caps *estimated* spend. It cannot guarantee the provider's invoice.
 */
final class Budget {

	const HOLD_MINUTES  = 15;
	const STALE_MINUTES = 15;

	/**
	 * Current period key (UTC month).
	 *
	 * @return string
	 */
	public static function period() {
		return gmdate( 'Y-m' );
	}

	/**
	 * Cap from settings (0 = no cap).
	 *
	 * @return int
	 */
	public static function cap() {
		return max( 0, (int) Settings::get( 'budget_cap_micros', 0 ) );
	}

	/**
	 * Make sure the period row exists and carries the current cap.
	 *
	 * @param string|null $period Period.
	 * @return void
	 */
	public static function ensure_row( $period = null ) {
		global $wpdb;
		$t      = Activator::tables();
		$period = $period ? $period : self::period();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, atomic upsert.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (period, cap_micros, spent_micros, reserved_micros, updated_at) VALUES (%s, %d, 0, 0, %s) ON DUPLICATE KEY UPDATE cap_micros = VALUES(cap_micros)', $t['budget'], $period, self::cap(), current_time( 'mysql', true ) ) );
	}

	/**
	 * Atomically reserve an amount. Returns a reservation ID, or 0 when the cap would be exceeded.
	 *
	 * @param int $amount Micro-USD (worst case).
	 * @return int
	 */
	public static function reserve( $amount ) {
		global $wpdb;
		$t      = Activator::tables();
		$amount = max( 0, (int) $amount );
		$period = self::period();
		self::ensure_row( $period );

		if ( self::cap() > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional reservation.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET reserved_micros = reserved_micros + %d, updated_at = %s WHERE period = %s AND cap_micros > 0 AND spent_micros + reserved_micros + %d <= cap_micros', $t['budget'], $amount, current_time( 'mysql', true ), $period, $amount ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No cap: still track reservations.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET reserved_micros = reserved_micros + %d, updated_at = %s WHERE period = %s', $t['budget'], $amount, current_time( 'mysql', true ), $period ) );
		}
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$wpdb->insert(
			$t['reservations'],
			array(
				'period'        => $period,
				'amount_micros' => $amount,
				'status'        => 'held',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Release a reservation and add the actual cost to spent. Safe to call once only.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @param int $actual         Actual estimated cost (micros).
	 * @return bool
	 */
	public static function reconcile( $reservation_id, $actual ) {
		return self::close( $reservation_id, 'reconciled', max( 0, (int) $actual ) );
	}

	/**
	 * Release a reservation without spending (call failed, not billed).
	 *
	 * @param int $reservation_id Reservation ID.
	 * @return bool
	 */
	public static function release( $reservation_id ) {
		return self::close( $reservation_id, 'released', 0 );
	}

	/**
	 * Keep a reservation for a while (provider may still bill), then the cleanup job releases it.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @return void
	 */
	public static function hold( $reservation_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET release_after = %s WHERE id = %d AND status = 'held'", $t['reservations'], gmdate( 'Y-m-d H:i:s', time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS ), (int) $reservation_id ) );
	}

	/**
	 * Close a reservation atomically (guarded so it can only be closed once).
	 *
	 * @param int    $reservation_id ID.
	 * @param string $status         reconciled|released.
	 * @param int    $spent          Amount to add to spent.
	 * @return bool
	 */
	private static function close( $reservation_id, $status, $spent ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['reservations'], (int) $reservation_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded state change.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s WHERE id = %d AND status = 'held'", $t['reservations'], $status, (int) $reservation_id ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counters.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET reserved_micros = GREATEST(CAST(reserved_micros AS SIGNED) - %d, 0), spent_micros = spent_micros + %d, updated_at = %s WHERE period = %s', $t['budget'], (int) $row['amount_micros'], (int) $spent, current_time( 'mysql', true ), $row['period'] ) );
		return true;
	}

	/**
	 * Add spend that had no reservation (e.g. nothing to release). Rarely used.
	 *
	 * @param int $amount Micros.
	 * @return void
	 */
	public static function add_spent( $amount ) {
		global $wpdb;
		$t = Activator::tables();
		self::ensure_row();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET spent_micros = spent_micros + %d WHERE period = %s', $t['budget'], max( 0, (int) $amount ), self::period() ) );
	}

	/**
	 * Release stale or expired held reservations (cleanup job).
	 *
	 * @return int Released count.
	 */
	public static function release_stale() {
		global $wpdb;
		$t     = Activator::tables();
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'held' AND ( ( release_after IS NOT NULL AND release_after < %s ) OR ( release_after IS NULL AND created_at < %s ) )", $t['reservations'], $now, $stale ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += self::release( (int) $id ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Current period numbers.
	 *
	 * @return array{period:string,cap:int,spent:int,reserved:int,remaining:int|null,percent:float,alert:bool,reached:bool}
	 */
	public static function status() {
		global $wpdb;
		$t = Activator::tables();
		self::ensure_row();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, live numbers.
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE period = %s', $t['budget'], self::period() ), ARRAY_A );
		$cap      = (int) ( $row['cap_micros'] ?? 0 );
		$spent    = (int) ( $row['spent_micros'] ?? 0 );
		$reserved = (int) ( $row['reserved_micros'] ?? 0 );
		$percent  = $cap > 0 ? min( 100.0, ( $spent + $reserved ) * 100 / $cap ) : 0.0;
		return array(
			'period'    => self::period(),
			'cap'       => $cap,
			'spent'     => $spent,
			'reserved'  => $reserved,
			'remaining' => $cap > 0 ? max( 0, $cap - $spent - $reserved ) : null,
			'percent'   => $percent,
			'alert'     => $cap > 0 && $percent >= (int) Settings::get( 'alert_threshold', 80 ),
			'reached'   => $cap > 0 && $spent + $reserved >= $cap,
		);
	}

	/**
	 * Reset this month's spent counter (admin action, confirmed in UI).
	 *
	 * @return void
	 */
	public static function reset_period() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET spent_micros = 0 WHERE period = %s', $t['budget'], self::period() ) );
		Queue::release_held();
	}

	/**
	 * After cap/price changes or a new month, let parked jobs try again.
	 *
	 * @return void
	 */
	public static function on_change() {
		self::ensure_row();
		Queue::release_held();
		\AILSUG\Queue\Worker::schedule();
	}
}
