<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP, per-session, and global-daily caps, enforced BEFORE any provider
 * call (plan §7 — the real abuse surface is a public endpoint calling a
 * paid API). Uses WP transients as counters, per the plan's own suggested
 * approach, in fixed windows carried by the key — see key().
 */
class Pandabot_Ratelimit {

	const EPOCH_OPTION = 'pandabot_rl_epoch';

	/**
	 * Counter key for a window. Built in one place so check() and record()
	 * cannot drift apart — they previously spelled the same key twice.
	 *
	 * The window stamp makes these FIXED windows: the hour bucket changes at
	 * the top of the hour whether or not anyone is active. They used to be
	 * rolling — set_transient() restarts the expiry on every write, so a
	 * visitor asking a question every few minutes kept their hourly counter
	 * alive indefinitely, turning "40 per hour" into "40 ever, until you stop
	 * for a full hour". A fixed window lets up to 2x the limit through across
	 * a boundary, which is the accepted trade: this exists to cap spend, not
	 * to be exact.
	 *
	 * The epoch lets an admin void every counter at once by bumping one
	 * option — which works even where transients live in a persistent object
	 * cache and cannot be enumerated to delete.
	 */
	private static function key( $scope, $ip_hash ) {
		$epoch = (int) get_option( self::EPOCH_OPTION, 0 );

		switch ( $scope ) {
			case 'min':
				return 'pandabot_rl_min_' . $epoch . '_' . gmdate( 'YmdHi' ) . '_' . $ip_hash;
			case 'hr':
				return 'pandabot_rl_hr_' . $epoch . '_' . gmdate( 'YmdH' ) . '_' . $ip_hash;
			case 'day':
			default:
				return 'pandabot_rl_day_' . $epoch . '_' . gmdate( 'Ymd' );
		}
	}

	/**
	 * Voids every per-IP and global counter immediately. Old transients are
	 * simply orphaned and expire on their own.
	 */
	public static function reset_counters() {
		update_option( self::EPOCH_OPTION, time(), false );
	}

	/**
	 * @return array{answer:string, guardrail_action:string}|null Null = allowed.
	 */
	public function check( $ip_hash, $session_id, array $settings ) {
		if ( $this->over_ip_limit( $ip_hash, 'min', (int) $settings['rate_limit_per_ip_minute'] ) ) {
			return $this->blocked( $settings );
		}
		if ( $this->over_ip_limit( $ip_hash, 'hr', (int) $settings['rate_limit_per_ip_hour'] ) ) {
			return $this->blocked( $settings );
		}
		if ( $this->over_session_limit( $session_id, (int) $settings['rate_limit_per_session'] ) ) {
			return $this->blocked( $settings );
		}
		if ( $this->over_global_day_limit( (int) $settings['rate_limit_global_day'] ) ) {
			return $this->blocked( $settings );
		}
		return null;
	}

	/**
	 * Call only once a message is confirmed allowed and is being
	 * processed — blocked attempts must not consume quota themselves.
	 */
	public function record( $ip_hash ) {
		// TTLs are only garbage collection now that the key carries the
		// window: double the window so a counter always outlives the window
		// it belongs to, even though each write restarts its expiry.
		$this->bump( self::key( 'min', $ip_hash ), 2 * MINUTE_IN_SECONDS );
		$this->bump( self::key( 'hr', $ip_hash ), 2 * HOUR_IN_SECONDS );
		$this->bump( self::key( 'day', $ip_hash ), 2 * DAY_IN_SECONDS );
	}

	public static function hash_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return '';
		}
		return hash( 'sha256', $ip . Pandabot_Settings::get_ip_salt() );
	}

	private function blocked( array $settings ) {
		return array(
			'answer'           => $settings['rate_limit_message'],
			'guardrail_action' => 'rate_limited',
		);
	}

	private function over_ip_limit( $ip_hash, $scope, $limit ) {
		if ( $limit <= 0 || empty( $ip_hash ) ) {
			return false;
		}
		return ( (int) get_transient( self::key( $scope, $ip_hash ) ) ) >= $limit;
	}

	private function over_session_limit( $session_id, $limit ) {
		if ( $limit <= 0 || empty( $session_id ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_conversations';
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT message_count FROM {$table} WHERE session_id = %s", $session_id ) );
		return ( (int) $count ) >= $limit;
	}

	private function over_global_day_limit( $limit ) {
		if ( $limit <= 0 ) {
			return false;
		}
		return ( (int) get_transient( self::key( 'day', '' ) ) ) >= $limit;
	}

	/**
	 * set_transient() restarts the expiry on every write, and the Transients
	 * API has no "increment without touching expiry" primitive. That no
	 * longer matters: the window now lives in the key (see key()), so the
	 * counter a bump touches is the one for the current window and the TTL
	 * is only there to sweep old buckets away.
	 */
	private function bump( $key, $ttl ) {
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, $ttl );
	}
}
