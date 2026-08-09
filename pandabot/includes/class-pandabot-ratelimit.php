<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP, per-session, and global-daily caps, enforced BEFORE any provider
 * call (plan §7 — the real abuse surface is a public endpoint calling a
 * paid API). Uses WP transients as counters, per the plan's own suggested
 * approach; see bump() for the one known tradeoff of that approach.
 */
class Pandabot_Ratelimit {

	/**
	 * @return array{answer:string, guardrail_action:string}|null Null = allowed.
	 */
	public function check( $ip_hash, $session_id, array $settings ) {
		if ( $this->over_ip_limit( $ip_hash, 'min', 60, (int) $settings['rate_limit_per_ip_minute'] ) ) {
			return $this->blocked( $settings );
		}
		if ( $this->over_ip_limit( $ip_hash, 'hr', HOUR_IN_SECONDS, (int) $settings['rate_limit_per_ip_hour'] ) ) {
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
		$this->bump( 'pandabot_rl_ip_min_' . $ip_hash, 60 );
		$this->bump( 'pandabot_rl_ip_hr_' . $ip_hash, HOUR_IN_SECONDS );
		$this->bump( 'pandabot_rl_day_' . gmdate( 'Y-m-d' ), DAY_IN_SECONDS );
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

	private function over_ip_limit( $ip_hash, $label, $ttl, $limit ) {
		if ( $limit <= 0 || empty( $ip_hash ) ) {
			return false;
		}
		return ( (int) get_transient( 'pandabot_rl_ip_' . $label . '_' . $ip_hash ) ) >= $limit;
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
		return ( (int) get_transient( 'pandabot_rl_day_' . gmdate( 'Y-m-d' ) ) ) >= $limit;
	}

	/**
	 * Resets the window's TTL on every bump rather than a true fixed
	 * window (WP's Transients API has no "increment without touching
	 * expiry" primitive without hand-rolling raw option updates). In
	 * practice this only ever makes the cap MORE conservative — a
	 * continuous slow trickle keeps the window alive longer than the
	 * nominal period — never less safe, and it matches the plan's own
	 * suggested approach ("transients keyed by ip_hash as counters with
	 * TTL"). Not worth the fragility of raw SQL increments at this scale.
	 */
	private function bump( $key, $ttl ) {
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, $ttl );
	}
}
