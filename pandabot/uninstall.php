<?php
/**
 * Fires only on actual plugin deletion via the WP admin (not on
 * deactivation). Wipes tables/options ONLY if the site owner opted in via
 * the "delete data on uninstall" setting — default is to leave data intact
 * so an accidental deactivate+delete doesn't destroy a clinic's chat logs.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Unconditional: the plugin files are about to disappear, so a scheduled
// event pointing at a handler that no longer exists must go regardless of
// whether the site owner opted into deleting their data.
$scheduled = wp_next_scheduled( 'pandabot_daily_maintenance' );
if ( $scheduled ) {
	wp_unschedule_event( $scheduled, 'pandabot_daily_maintenance' );
}

$settings = get_option( 'pandabot_settings', array() );
$should_delete = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

if ( ! $should_delete ) {
	return;
}

$tables = array(
	$wpdb->prefix . 'pandabot_embeddings',
	$wpdb->prefix . 'pandabot_conversations',
	$wpdb->prefix . 'pandabot_messages',
	$wpdb->prefix . 'pandabot_events',
);

foreach ( $tables as $table ) {
	// Table names are built from $wpdb->prefix + a hardcoded literal, never
	// from user input, so this is safe without further escaping.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'pandabot_settings',
	'pandabot_ip_salt',
	'pandabot_db_version',
	'pandabot_activated_at',
	'pandabot_manual_qa_next_id',
	'pandabot_last_purge_at',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Rate-limit and event counters are transients, so they carry the salted IP
// hashes in their option names — they expire on their own, but leaving them
// behind would defeat the point of a full wipe.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_pandabot\_%'
	    OR option_name LIKE '\_transient\_timeout\_pandabot\_%'"
);
