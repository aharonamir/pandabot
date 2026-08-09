<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only aggregation over the conversations/messages/events tables
 * (plan §8). Plain $wpdb — no ORM, no caching layer; at this site's volume
 * these are all indexed lookups over a few thousand rows.
 *
 * Every method is scoped to the instance's date window. Rows are stored in
 * UTC, so day bucketing shifts each timestamp by the site's UTC offset
 * before taking DATE() — CONVERT_TZ would be cleaner but needs the MySQL
 * timezone tables, which shared hosts frequently don't load.
 */
class Pandabot_Analytics {

	/** @var int Days back from now; 0 means all time. */
	private $days;

	/** @var int Site UTC offset in seconds. */
	private $offset;

	public function __construct( $days = 30 ) {
		$this->days   = max( 0, (int) $days );
		$this->offset = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	public function days() {
		return $this->days;
	}

	/**
	 * Window start as a UTC datetime string, or null for all time.
	 */
	private function since() {
		if ( 0 === $this->days ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', time() - ( $this->days * DAY_IN_SECONDS ) );
	}

	/**
	 * " AND col >= '...'" fragment, or '' for all time.
	 *
	 * Escaped with esc_sql rather than built via prepare(), because callers
	 * concatenate this into queries that then go through prepare() for their
	 * own LIMIT/argument placeholders — feeding an already-prepared string
	 * back into prepare() is the kind of thing that works until one day the
	 * value contains a % and it doesn't. The value here is always gmdate()
	 * output, and the column name is a class-internal literal.
	 */
	private function window( $column ) {
		$since = $this->since();
		if ( null === $since ) {
			return '';
		}
		return " AND {$column} >= '" . esc_sql( $since ) . "'";
	}

	private function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pandabot_' . $name;
	}

	/* ---------------------------------------------------------------- */
	/* Headline numbers                                                  */
	/* ---------------------------------------------------------------- */

	public function totals() {
		global $wpdb;

		$conversations = $this->table( 'conversations' );
		$messages      = $this->table( 'messages' );

		$convo_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conversations} WHERE 1=1" . $this->window( 'started_at' ) ); // phpcs:ignore WordPress.DB
		$sessions    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$conversations} WHERE 1=1" . $this->window( 'started_at' ) ); // phpcs:ignore WordPress.DB

		$user_msgs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages} WHERE role = 'user'" . $this->window( 'created_at' ) ); // phpcs:ignore WordPress.DB
		$bot_msgs  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages} WHERE role = 'assistant'" . $this->window( 'created_at' ) ); // phpcs:ignore WordPress.DB

		return array(
			'conversations' => $convo_count,
			'sessions'      => $sessions,
			'user_messages' => $user_msgs,
			'bot_messages'  => $bot_msgs,
			'avg_messages'  => $convo_count > 0 ? round( ( $user_msgs + $bot_msgs ) / $convo_count, 1 ) : 0,
		);
	}

	/**
	 * The headline metric (plan §8): opens → first message → CTA click.
	 * Counted in distinct sessions, not raw events — one visitor clicking
	 * the booking button three times is one conversion, not three.
	 */
	public function funnel() {
		global $wpdb;
		$events = $this->table( 'events' );
		$window = $this->window( 'created_at' );

		$distinct = function ( $where ) use ( $wpdb, $events, $window ) {
			return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$events} WHERE {$where}" . $window ); // phpcs:ignore WordPress.DB
		};

		$by_type = array();
		foreach ( array( 'cta_click_booking', 'cta_click_phone', 'cta_click_whatsapp' ) as $type ) {
			$by_type[ $type ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE event_type = %s", $type ) . $window // phpcs:ignore WordPress.DB
			);
		}

		return array(
			'opens'          => $distinct( "event_type = 'open'" ),
			'first_messages' => $distinct( "event_type = 'first_message'" ),
			'cta_sessions'   => $distinct( "event_type LIKE 'cta\\_click\\_%'" ),
			'cta_by_type'    => $by_type,
			'prompt_clicks'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE event_type = 'suggested_prompt_click'" . $window ), // phpcs:ignore WordPress.DB
			'rate_limited'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE event_type = 'rate_limited'" . $window ), // phpcs:ignore WordPress.DB
		);
	}

	/* ---------------------------------------------------------------- */
	/* Time series                                                       */
	/* ---------------------------------------------------------------- */

	/**
	 * Daily counts with empty days filled in, so the chart's x-axis is a
	 * real calendar rather than "days that happened to have traffic".
	 *
	 * @param string $what 'messages' | 'cta' | 'tokens'
	 * @return array<string,int> date (Y-m-d, site time) => value
	 */
	public function daily( $what ) {
		global $wpdb;

		$offset = $this->offset;

		if ( 'cta' === $what ) {
			$table  = $this->table( 'events' );
			$select = 'COUNT(*)';
			$where  = "event_type LIKE 'cta\\_click\\_%'";
		} elseif ( 'tokens' === $what ) {
			$table  = $this->table( 'messages' );
			$select = 'COALESCE(SUM(tokens_prompt),0) + COALESCE(SUM(tokens_completion),0)';
			$where  = '1=1';
		} else {
			$table  = $this->table( 'messages' );
			$select = 'COUNT(*)';
			$where  = "role = 'user'";
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, {$select} AS v
				 FROM {$table} WHERE {$where}" . $this->window( 'created_at' ) . ' GROUP BY d', // phpcs:ignore WordPress.DB
				$offset
			),
			ARRAY_A
		);

		$found = array();
		foreach ( $rows as $row ) {
			$found[ $row['d'] ] = (int) $row['v'];
		}

		return $this->fill_days( $found );
	}

	/**
	 * @param array<string,int> $found
	 * @return array<string,int>
	 */
	private function fill_days( array $found ) {
		$span = ( 0 === $this->days ) ? 30 : $this->days;
		$span = min( $span, 120 ); // a 365-day bar chart is unreadable; cap the axis.

		$series = array();
		$today  = (int) floor( ( time() + $this->offset ) / DAY_IN_SECONDS );

		for ( $i = $span - 1; $i >= 0; $i-- ) {
			$date            = gmdate( 'Y-m-d', ( $today - $i ) * DAY_IN_SECONDS );
			$series[ $date ] = isset( $found[ $date ] ) ? $found[ $date ] : 0;
		}

		return $series;
	}

	/* ---------------------------------------------------------------- */
	/* Quality signals                                                   */
	/* ---------------------------------------------------------------- */

	/**
	 * How often each guardrail path fired, as a share of all answers.
	 */
	public function guardrails() {
		global $wpdb;
		$messages = $this->table( 'messages' );

		$rows = $wpdb->get_results(
			"SELECT COALESCE(guardrail_action,'none') AS action, COUNT(*) AS c
			 FROM {$messages} WHERE role = 'assistant'" . $this->window( 'created_at' ) . ' GROUP BY action ORDER BY c DESC', // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$out   = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$out[ $row['action'] ] = (int) $row['c'];
			$total                += (int) $row['c'];
		}

		return array(
			'counts' => $out,
			'total'  => $total,
		);
	}

	/**
	 * The content-gap list (plan §8: "this is gold"). Each row is a question
	 * the bot could not answer from the site, paired with the question that
	 * actually triggered it — that's the article the clinic should write.
	 */
	public function fallback_questions( $limit = 25 ) {
		global $wpdb;
		$messages = $this->table( 'messages' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.created_at, a.conversation_id,
					(SELECT u.content FROM {$messages} u
					 WHERE u.conversation_id = a.conversation_id AND u.role = 'user' AND u.id < a.id
					 ORDER BY u.id DESC LIMIT 1) AS question
				 FROM {$messages} a
				 WHERE a.role = 'assistant' AND a.guardrail_action = 'fallback_no_context'" . $this->window( 'a.created_at' ) . '
				 ORDER BY a.id DESC LIMIT %d', // phpcs:ignore WordPress.DB
				$limit
			),
			ARRAY_A
		);
	}

	public function recent_questions( $limit = 40 ) {
		global $wpdb;
		$messages = $this->table( 'messages' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, conversation_id, content, created_at FROM {$messages}
				 WHERE role = 'user'" . $this->window( 'created_at' ) . '
				 ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Crude term frequency over visitor questions — enough to see themes
	 * ("מינון", "החזר", "טיקים") without pretending to be topic modelling.
	 */
	public function top_terms( $limit = 20 ) {
		global $wpdb;
		$messages = $this->table( 'messages' );

		$contents = $wpdb->get_col(
			"SELECT content FROM {$messages} WHERE role = 'user'" . $this->window( 'created_at' ) . ' ORDER BY id DESC LIMIT 500' // phpcs:ignore WordPress.DB
		);

		$stop = array(
			'את', 'אני', 'הוא', 'היא', 'זה', 'זו', 'של', 'עם', 'על', 'אל', 'לא', 'כן', 'גם', 'רק', 'אם',
			'כי', 'אבל', 'או', 'יש', 'אין', 'מה', 'מי', 'איך', 'למה', 'מתי', 'איפה', 'האם', 'כמה',
			'לי', 'לו', 'לה', 'לנו', 'להם', 'שלי', 'שלו', 'שלה', 'הם', 'הן', 'אתם', 'אנחנו',
			'אפשר', 'צריך', 'יכול', 'רוצה', 'עושה', 'להיות', 'שיש', 'שאני', 'בבקשה', 'תודה',
			'the', 'and', 'for', 'you', 'are', 'with', 'that', 'this', 'have', 'can', 'what', 'how',
		);

		$counts = array();
		foreach ( $contents as $text ) {
			$words = preg_split( '/[^\p{L}\p{N}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $words as $word ) {
				$word = mb_strtolower( $word );
				if ( mb_strlen( $word ) < 3 || in_array( $word, $stop, true ) ) {
					continue;
				}
				$counts[ $word ] = isset( $counts[ $word ] ) ? $counts[ $word ] + 1 : 1;
			}
		}

		arsort( $counts );

		return array_slice( $counts, 0, $limit, true );
	}

	/* ---------------------------------------------------------------- */
	/* Cost                                                              */
	/* ---------------------------------------------------------------- */

	public function tokens() {
		global $wpdb;
		$messages = $this->table( 'messages' );

		$row = $wpdb->get_row(
			"SELECT COALESCE(SUM(tokens_prompt),0) AS prompt, COALESCE(SUM(tokens_completion),0) AS completion
			 FROM {$messages} WHERE 1=1" . $this->window( 'created_at' ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return array(
			'prompt'     => (int) $row['prompt'],
			'completion' => (int) $row['completion'],
		);
	}

	/**
	 * Cost is an estimate, not a bill: it only covers what the plugin
	 * itself logged, and providers that omit usage from their responses
	 * contribute zero. Embedding spend is not included — it happens at
	 * index time, not per conversation.
	 */
	public function cost() {
		$tokens = $this->tokens();
		$prices = Pandabot_Settings::get( 'cost' );

		$in  = ( $tokens['prompt'] / 1000 ) * (float) $prices['chat_input_per_1k'];
		$out = ( $tokens['completion'] / 1000 ) * (float) $prices['chat_output_per_1k'];

		return array(
			'input'  => $in,
			'output' => $out,
			'total'  => $in + $out,
			'priced' => ( (float) $prices['chat_input_per_1k'] > 0 || (float) $prices['chat_output_per_1k'] > 0 ),
		);
	}

	/**
	 * Today's message count against the configured global daily cap, for
	 * the spend-cap warning banner.
	 */
	public function global_usage_today() {
		global $wpdb;
		$messages = $this->table( 'messages' );

		$start = gmdate( 'Y-m-d H:i:s', ( (int) floor( ( time() + $this->offset ) / DAY_IN_SECONDS ) ) * DAY_IN_SECONDS - $this->offset );

		$used = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE role = 'user' AND created_at >= %s", $start ) // phpcs:ignore WordPress.DB
		);

		$cap = (int) Pandabot_Settings::get( 'rate_limit_global_day' );

		return array(
			'used'    => $used,
			'cap'     => $cap,
			'percent' => $cap > 0 ? min( 100, (int) round( ( $used / $cap ) * 100 ) ) : 0,
		);
	}

	/* ---------------------------------------------------------------- */
	/* Conversations browser                                             */
	/* ---------------------------------------------------------------- */

	public function conversation_count() {
		global $wpdb;
		$conversations = $this->table( 'conversations' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conversations} WHERE 1=1" . $this->window( 'started_at' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One page of conversations, newest activity first. ip_hash is never
	 * selected — nothing in the admin UI should be able to display it.
	 */
	public function conversation_page( $paged = 1, $per_page = 20 ) {
		global $wpdb;
		$conversations = $this->table( 'conversations' );
		$messages      = $this->table( 'messages' );

		$offset = max( 0, ( (int) $paged - 1 ) * (int) $per_page );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.session_id, c.started_at, c.last_at, c.message_count, c.lang,
					(SELECT u.content FROM {$messages} u WHERE u.conversation_id = c.id AND u.role = 'user' ORDER BY u.id ASC LIMIT 1) AS first_question,
					(SELECT COUNT(*) FROM {$messages} g WHERE g.conversation_id = c.id AND g.guardrail_action NOT IN ('none','')) AS guardrail_hits
				 FROM {$conversations} c
				 WHERE 1=1" . $this->window( 'c.started_at' ) . '
				 ORDER BY c.last_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	public function conversation( $id ) {
		global $wpdb;
		$conversations = $this->table( 'conversations' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, session_id, started_at, last_at, message_count, lang FROM {$conversations} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
	}

	public function transcript( $conversation_id ) {
		global $wpdb;
		$messages = $this->table( 'messages' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, content, tokens_prompt, tokens_completion, retrieved_ids, guardrail_action, latency_ms, created_at
				 FROM {$messages} WHERE conversation_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB
				$conversation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Resolve a message's retrieved_ids into readable chunk titles, so the
	 * transcript shows which page an answer was grounded in.
	 *
	 * @param string $ids Comma-separated embedding row ids.
	 */
	public function chunk_labels( $ids ) {
		global $wpdb;

		$list = array_filter( array_map( 'intval', explode( ',', (string) $ids ) ) );
		if ( empty( $list ) ) {
			return array();
		}

		$embeddings   = $this->table( 'embeddings' );
		$placeholders = implode( ',', array_fill( 0, count( $list ), '%d' ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, source_url, chunk_index FROM {$embeddings} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB
				$list
			),
			ARRAY_A
		);
	}
}
