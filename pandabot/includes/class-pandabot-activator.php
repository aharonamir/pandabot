<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation and deactivation. No destructive behavior lives here —
 * that's uninstall.php's job, and only when the user opted into it.
 */
class Pandabot_Activator {

	/**
	 * Table suffixes this plugin owns, without the $wpdb prefix.
	 */
	public static function table_names() {
		global $wpdb;
		return array(
			'embeddings'    => $wpdb->prefix . 'pandabot_embeddings',
			'conversations' => $wpdb->prefix . 'pandabot_conversations',
			'messages'      => $wpdb->prefix . 'pandabot_messages',
			'events'        => $wpdb->prefix . 'pandabot_events',
		);
	}

	public static function activate() {
		if ( version_compare( PHP_VERSION, PANDABOT_MIN_PHP, '<' ) ) {
			deactivate_plugins( PANDABOT_PLUGIN_BASENAME );
			wp_die(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					esc_html__( 'PandaBot requires PHP %1$s or higher. This server is running PHP %2$s. Please contact your host to upgrade, then reactivate the plugin.', 'pandabot' ),
					esc_html( PANDABOT_MIN_PHP ),
					esc_html( PHP_VERSION )
				),
				esc_html__( 'PandaBot activation error', 'pandabot' ),
				array( 'back_link' => true )
			);
		}

		self::create_tables();

		Pandabot_Settings::seed_defaults();
		Pandabot_Settings::get_ip_salt(); // generates on first activation if missing.

		Pandabot_Privacy::schedule();

		update_option( Pandabot_Settings::DB_VERSION_OPT, PANDABOT_DB_VERSION, false );
		update_option( 'pandabot_activated_at', gmdate( 'Y-m-d H:i:s' ), false );
	}

	public static function deactivate() {
		// Intentionally no data or table removal on deactivate — only on
		// explicit uninstall, and only if the user opted in.
		Pandabot_Privacy::unschedule();
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables           = self::table_names();

		$sql = array();

		$sql[] = "CREATE TABLE {$tables['embeddings']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(20) NOT NULL DEFAULT 'post',
			source_id bigint(20) unsigned DEFAULT NULL,
			source_url text,
			title text,
			chunk_index int(11) NOT NULL DEFAULT 0,
			content longtext,
			embedding longblob,
			token_estimate int(11) DEFAULT NULL,
			content_hash char(40) DEFAULT NULL,
			lang varchar(10) NOT NULL DEFAULT 'he',
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_type_id (source_type, source_id),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['conversations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id char(36) NOT NULL,
			ip_hash char(64) DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			last_at datetime DEFAULT NULL,
			message_count int(11) NOT NULL DEFAULT 0,
			resolved_flag tinyint(1) DEFAULT NULL,
			lang varchar(10) NOT NULL DEFAULT 'he',
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY last_at (last_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['messages']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(10) NOT NULL,
			content longtext,
			tokens_prompt int(11) DEFAULT NULL,
			tokens_completion int(11) DEFAULT NULL,
			retrieved_ids text,
			guardrail_action varchar(30) DEFAULT NULL,
			latency_ms int(11) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY guardrail_action (guardrail_action)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id char(36) DEFAULT NULL,
			event_type varchar(40) NOT NULL,
			meta text,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY event_type (event_type)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
