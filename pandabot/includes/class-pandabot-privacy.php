<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention and data-hygiene (plan §9). Chat logs are the only personal data
 * this plugin holds, so they get an expiry date rather than living forever:
 * a daily cron deletes conversations, their messages, and analytics events
 * older than the configured window.
 *
 * Deletion is by age of the conversation as a whole (its last activity), not
 * per message — a conversation is one record from the visitor's point of view
 * and half-expired transcripts would be worse than useless on the dashboard.
 */
class Pandabot_Privacy {

	const CRON_HOOK   = 'pandabot_daily_maintenance';
	const LAST_RUN_OPT = 'pandabot_last_purge_at';

	/**
	 * Conversations deleted per query, and how many of those batches one cron
	 * run will do. Shared hosting kills long queries, so this trades "finishes
	 * in one run" for "never holds a lock long enough to matter" — a backlog
	 * simply drains over consecutive days.
	 */
	const BATCH      = 500;
	const MAX_BATCHES = 20;

	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'run_maintenance' ) );
		// Self-heals the schedule when a site is updated by overwriting the
		// plugin folder, which never fires the activation hook.
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/**
	 * Offers ready-made wording in WP's own Privacy Policy Guide, so the
	 * clinic can paste an accurate description of what the bot stores instead
	 * of writing one from scratch.
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = (int) Pandabot_Settings::get( 'retention_days', 30 );

		$content = '<p>' . esc_html__( 'האתר מפעיל עוזרת אוטומטית (צ׳אט) המשיבה על שאלות על סמך תוכן האתר.', 'pandabot' ) . '</p>'
			. '<p><strong>' . esc_html__( 'מה נשמר:', 'pandabot' ) . '</strong> '
			. esc_html__( 'תוכן ההודעות שנשלחו בצ׳אט ותשובות המערכת, מזהה שיחה זמני, וחותמת זמן. אין איסוף של שם, אימייל או פרטי התקשרות, אלא אם בחרתם לכתוב אותם בעצמכם בגוף ההודעה.', 'pandabot' ) . '</p>'
			. '<p><strong>' . esc_html__( 'כתובות IP:', 'pandabot' ) . '</strong> '
			. esc_html__( 'כתובת ה-IP אינה נשמרת. נשמר ממנה רק ערך גיבוב (hash) חד-כיווני, לצורך הגבלת קצב ומניעת שימוש לרעה בלבד.', 'pandabot' ) . '</p>'
			. '<p><strong>' . esc_html__( 'העברה לצד שלישי:', 'pandabot' ) . '</strong> '
			. esc_html__( 'כדי לייצר תשובה, ההודעה נשלחת לספק מודל שפה חיצוני. אין לשלוח בצ׳אט מידע רפואי מזהה.', 'pandabot' ) . '</p>'
			. '<p><strong>' . esc_html__( 'משך שמירה:', 'pandabot' ) . '</strong> '
			. (
				$days > 0
					? sprintf(
						/* translators: %s: number of days */
						esc_html__( 'שיחות נמחקות אוטומטית לאחר %s ימים.', 'pandabot' ),
						esc_html( number_format_i18n( $days ) )
					)
					: esc_html__( 'שיחות נשמרות ללא מחיקה אוטומטית.', 'pandabot' )
			)
			. '</p>';

		wp_add_privacy_policy_content( __( 'PandaBot', 'pandabot' ), $content );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public function run_maintenance() {
		$days = (int) Pandabot_Settings::get( 'retention_days', 30 );
		self::purge_older_than( $days );
		update_option( self::LAST_RUN_OPT, gmdate( 'Y-m-d H:i:s' ), false );
	}

	/**
	 * @param int $days 0 = keep forever (an explicit opt-out, not a bug).
	 * @return array{conversations:int, messages:int, events:int}
	 */
	public static function purge_older_than( $days ) {
		$deleted = array(
			'conversations' => 0,
			'messages'      => 0,
			'events'        => 0,
		);

		$days = (int) $days;
		if ( $days <= 0 ) {
			return $deleted;
		}

		global $wpdb;

		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$conversations = $wpdb->prefix . 'pandabot_conversations';
		$messages      = $wpdb->prefix . 'pandabot_messages';
		$events        = $wpdb->prefix . 'pandabot_events';

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			// last_at is only written once a conversation has a reply, so an
			// abandoned row would otherwise never age out — fall back to
			// started_at for those.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$conversations} WHERE COALESCE(last_at, started_at) < %s LIMIT %d",
					$cutoff,
					self::BATCH
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			$ids         = array_map( 'intval', $ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$deleted['messages'] += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$messages} WHERE conversation_id IN ({$placeholders})", $ids )
			);
			$deleted['conversations'] += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$conversations} WHERE id IN ({$placeholders})", $ids )
			);

			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}

		// Events carry only a session id and an event name, but they're still
		// visitor traces, so they expire on the same clock.
		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$removed = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$events} WHERE created_at < %s LIMIT %d", $cutoff, self::BATCH )
			);
			$deleted['events'] += $removed;
			if ( $removed < self::BATCH ) {
				break;
			}
		}

		// Safety sweep: messages whose conversation vanished some other way
		// (a manual DB edit, an interrupted earlier purge) would otherwise sit
		// there unreachable and undeletable.
		$deleted['messages'] += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$messages} m LEFT JOIN {$conversations} c ON m.conversation_id = c.id
				 WHERE c.id IS NULL AND m.created_at < %s",
				$cutoff
			)
		);

		return $deleted;
	}

	/**
	 * Wipes every conversation, message and event immediately, regardless of
	 * age. The indexed site content (embeddings) is untouched — it isn't
	 * personal data and re-embedding it costs real money.
	 *
	 * @return array{conversations:int, messages:int, events:int}
	 */
	public static function purge_all() {
		global $wpdb;

		$conversations = $wpdb->prefix . 'pandabot_conversations';
		$messages      = $wpdb->prefix . 'pandabot_messages';
		$events        = $wpdb->prefix . 'pandabot_events';

		$counts = array(
			'messages'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages}" ),
			'conversations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conversations}" ),
			'events'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ),
		);

		// DELETE rather than TRUNCATE: TRUNCATE needs the DROP privilege,
		// which a locked-down shared-hosting DB user may not have.
		$wpdb->query( "DELETE FROM {$messages}" );
		$wpdb->query( "DELETE FROM {$conversations}" );
		$wpdb->query( "DELETE FROM {$events}" );

		return $counts;
	}

	/**
	 * What is actually stored right now — shown on the settings screen so the
	 * site owner can answer "what do you hold about me?" without a DB client.
	 */
	public static function data_summary() {
		global $wpdb;

		$conversations = $wpdb->prefix . 'pandabot_conversations';
		$messages      = $wpdb->prefix . 'pandabot_messages';
		$events        = $wpdb->prefix . 'pandabot_events';

		return array(
			'conversations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conversations}" ),
			'messages'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages}" ),
			'events'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ),
			'oldest'        => $wpdb->get_var( "SELECT MIN(COALESCE(started_at, last_at)) FROM {$conversations}" ),
			'last_purge'    => get_option( self::LAST_RUN_OPT, '' ),
			'next_purge'    => wp_next_scheduled( self::CRON_HOOK ),
		);
	}
}
