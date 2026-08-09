<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes conversations/messages/events to the DB. No raw IPs handled here —
 * callers pass an already-hashed ip_hash (or null); hashing itself lives
 * wherever the caller has access to the raw request IP (plan §9).
 */
class Pandabot_Logger {

	public function get_or_create_conversation( $session_id, $ip_hash = null, $lang = 'he' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_conversations';

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_id = %s", $session_id ) );

		if ( $id ) {
			$wpdb->update(
				$table,
				array( 'last_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => $id )
			);
			return (int) $id;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'    => $session_id,
				'ip_hash'       => $ip_hash,
				'started_at'    => gmdate( 'Y-m-d H:i:s' ),
				'last_at'       => gmdate( 'Y-m-d H:i:s' ),
				'message_count' => 0,
				'lang'          => $lang,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function log_message( $conversation_id, $role, $content, array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_messages';

		$args = wp_parse_args(
			$args,
			array(
				'tokens_prompt'     => null,
				'tokens_completion' => null,
				'retrieved_ids'     => null,
				'guardrail_action'  => null,
				'latency_ms'        => null,
			)
		);

		$wpdb->insert(
			$table,
			array(
				'conversation_id'   => $conversation_id,
				'role'              => $role,
				'content'           => $content,
				'tokens_prompt'     => $args['tokens_prompt'],
				'tokens_completion' => $args['tokens_completion'],
				'retrieved_ids'     => $args['retrieved_ids'],
				'guardrail_action'  => $args['guardrail_action'],
				'latency_ms'        => $args['latency_ms'],
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}pandabot_conversations SET message_count = message_count + 1, last_at = %s WHERE id = %d",
				gmdate( 'Y-m-d H:i:s' ),
				$conversation_id
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function log_event( $session_id, $event_type, $meta = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_events';

		$wpdb->insert(
			$table,
			array(
				'session_id' => $session_id,
				'event_type' => $event_type,
				'meta'       => is_array( $meta ) ? wp_json_encode( $meta ) : $meta,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
