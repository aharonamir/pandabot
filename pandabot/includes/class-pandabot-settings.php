<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings model: single autoloaded option holding an associative array,
 * plus a couple of small standalone options (salt, db version) that don't
 * belong in the user-editable settings blob.
 *
 * Nothing in here renders UI — admin/views/settings.php owns that. This
 * class only owns defaults, read/write, and sanitization.
 */
class Pandabot_Settings {

	const OPTION_KEY     = 'pandabot_settings';
	const SALT_OPTION    = 'pandabot_ip_salt';
	const DB_VERSION_OPT = 'pandabot_db_version';

	/**
	 * Full default settings tree. Every key the admin screens will ever
	 * read must have a default here so get() never has to guess.
	 */
	public static function defaults() {
		return array(
			// Two independent OpenAI-compatible provider configs — see plan §4/§11.
			'chat_provider'       => array(
				'base_url' => '',
				'api_key'  => '',
				'model'    => '',
			),
			'embeddings_provider' => array(
				'base_url'  => '',
				'api_key'   => '',
				'model'     => '',
				'dimension' => 0,
			),

			// Indexing.
			'indexed_post_types'  => array( 'post', 'page' ),
			'excluded_ids'        => array(),
			'manual_qa'           => array(), // array of { question, answer }

			// Guardrails / prompting.
			'system_prompt'       => self::default_system_prompt(),
			'suggested_prompts'   => array(
				'מה זה PANS/PANDAS?',
				'איך מתנהל תהליך הטיפול?',
				'האם יש החזר מקופת החולים / ביטוח?',
				'איך קובעים שיחת היכרות?',
			),
			'fallback_message'    => 'אני לא בטוחה שיש לי מידע על כך באתר. הכי טוב לשוחח ישירות עם הקליניקה — אפשר להשאיר פרטים או להתקשר:',
			// Mockup's exact dosage-guardrail copy (mockup is authoritative
			// for tone/strings where the plan doesn't specify them).
			'medical_redirect_message' => 'אני לא יכולה לתת המלצות על מינון או טיפול תרופתי. מינונים ותוכניות טיפול נקבעים אישית על ידי ליאת בהתאם לבדיקות ולפרופיל של כל ילד, והרופא/ה המטפל/ת נשאר/ת האחראי/ת הבלעדי/ת. אשמח לעזור לתאם שיחה כדי לקבל מענה מקצועי.',
			'rate_limit_message'  => 'יש עומס רגעי על הצ׳אט. אפשר לנסות שוב בעוד דקה, או להתקשר אלינו ישירות.',
			'input_too_long_message' => 'ההודעה ארוכה מדי. אפשר לנסח אותה בקצרה יותר, או להתקשר אלינו ישירות.',
			// Mockup's exact copy (plan §5) for provider/connection failures
			// shown to the public — never the real provider error.
			'generic_error_message' => 'שגיאה זמנית, נסו שוב או התקשרו…',
			// The privacy-policy link is appended by the widget when a URL is
			// configured, so it is deliberately not part of this string.
			'consent_text'        => 'שיחה עם עוזרת אוטומטית · ההודעות עשויות להישמר לשיפור השירות',
			'guardrail_keywords'  => array( 'מינון', 'מנת יתר', 'להפסיק תרופה', 'לשנות תרופה', 'התאבדות', 'לפגוע בעצמי', 'מקרה חירום' ),
			// OpenAI-style embedding cosine similarities for genuinely
			// related-but-differently-worded text often land well below
			// intuition (0.3-0.6 is common) — 0.72 filtered out real page
			// content entirely in testing. This is a starting point, not
			// a universal constant: tune it per-provider using the chat
			// tester's candidate-score view on the Knowledge page.
			'similarity_floor'    => 0.3,
			'top_k'               => 5,

			// Rate limiting (§7).
			'rate_limit_per_ip_minute' => 8,
			'rate_limit_per_ip_hour'   => 40,
			'rate_limit_per_session'   => 30,
			'rate_limit_global_day'    => 500,
			// Hebrew commonly uses more tokens per character than English
			// in OpenAI-style tokenizers, so the plan's example value of
			// ~400 cut real answers off mid-sentence in testing. 700 is a
			// less aggressive starting point — still a real cap, tune per
			// provider/model via the Knowledge page tuning panel.
			'max_tokens'               => 700,
			'max_context_chars'        => 6000,
			'input_char_cap'           => 1000,

			// Appearance (§5/§10) — accent defaults to the mockup's teal-700.
			'appearance'          => array(
				'accent_color'    => '#0F6E56',
				'radius'          => 20,
				'position'        => 'left', // 'left' | 'right'
				'launcher_icon'   => 'chat',  // 'chat' | 'help' | 'leaf'
				'avatar'          => '',
				'header_title'    => 'העוזרת של הקליניקה',
				'header_status'   => 'בדרך כלל עונה מיד',
				'greeting'        => 'שלום 🌿 אני כאן לענות על שאלות על הקליניקה, הגישה הטיפולית ותהליך הטיפול. איך אפשר לעזור?',
				'teaser_text'     => 'יש שאלה? אני כאן 👋',
				// Seconds before the panel opens by itself. 0 = never, which
				// is the default on purpose: the mockup auto-opens to demo
				// itself, but doing that to every real visitor is intrusive.
				'auto_open_delay' => 0,
				// Distance from the screen corner. Themes and plugins park
				// their own floating buttons (back-to-top, accessibility,
				// cookie banners) in the same corner, so this has to be
				// movable rather than fixed at a "nice" value.
				'offset_bottom'   => 26,
				'offset_side'     => 26,
				// Extra space under the input on phones, where the panel goes
				// full-screen and a third-party floating button can otherwise
				// sit directly on top of the send button.
				'mobile_gap'      => 0,
			),

			// Contact facts (§6) — used both for the widget's CTA buttons and
			// for the fixed contact block appended to every system prompt, so
			// the two can never drift apart.
			'contact'             => array(
				'booking_url'  => '/make-appointments/',
				'phone'        => '054-6657207',
				'whatsapp'     => '972546657207',
				'email'        => 'info@pandakids-clinic.co.il',
				'address'      => 'בת-חן 31, מושב חרות',
				'privacy_url'  => '',
			),

			// Privacy (§9).
			'retention_days'            => 30,
			'delete_data_on_uninstall'  => false,

			// Cost estimation (§8/§10) — per-1K-token prices, in the site owner's currency.
			'cost' => array(
				'chat_input_per_1k'   => 0,
				'chat_output_per_1k'  => 0,
				'embeddings_per_1k'   => 0,
			),
		);
	}

	public static function default_system_prompt() {
		return "את/ה עוזרת וירטואלית של הקליניקה של ליאת אהרון, קלינית הרבליסטית, המטפלת בילדים עם PANS/PANDAS וטיקים/טוראט בגישה טבעית ומותאמת אישית.\n\n" .
			"ענה/י אך ורק על סמך המידע שסופק לך כהקשר מהאתר. אם ההקשר אינו מכיל תשובה, אמר/י זאת בפשטות והפנה/י ליצירת קשר עם הקליניקה — אל תמציא/י מידע רפואי.\n\n" .
			"לעולם אל תאבחן/י, אל תקבע/י אם ילד מסוים \"סובל\" ממצב מסוים, אל תיתן/י המלצות מינון, אל תייעץ/י להתחיל/להפסיק/לשנות תרופה או תוסף, ואל תפרש/י תוצאות בדיקות מעבדה.\n\n" .
			"כשמתקרבים לתחום קליני, הבהר/י שאת/ה עוזרת מידע על הקליניקה ואינך יכול/ה לתת ייעוץ רפואי, שהרופא/ה המטפל/ת נשאר/ת אחראי/ת, והצע/י לתאם שיחת היכרות.\n\n" .
			"הישאר/י בנושא (הקליניקה, הגישה הטיפולית, PANS/PANDAS, טיקים/טוראט, תהליך הטיפול, יצירת קשר ותיאום שיחות). סרב/י בנימוס לבקשות שאינן קשורות.\n\n" .
			"ענה/י בשפת המשתמש/ת, כברירת מחדל בעברית, בקצרה ובחום.";
	}

	/**
	 * Get the full settings array, merged over defaults so new keys added
	 * in later versions always have a sane value even for sites that
	 * activated an older version.
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::merge_defaults( self::defaults(), $stored );
	}

	/**
	 * Recursive merge so nested arrays (chat_provider, appearance, cost...)
	 * also get missing sub-keys filled in rather than being wholesale
	 * replaced by a partially-saved stored value.
	 */
	private static function merge_defaults( $defaults, $stored ) {
		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$stored[ $key ] = $default_value;
				continue;
			}
			if ( is_array( $default_value ) && is_array( $stored[ $key ] ) && self::is_assoc( $default_value ) ) {
				$stored[ $key ] = self::merge_defaults( $default_value, $stored[ $key ] );
			}
		}
		return $stored;
	}

	private static function is_assoc( $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Dot-path getter, e.g. Pandabot_Settings::get( 'chat_provider.model' ).
	 */
	public static function get( $key, $default = null ) {
		$all   = self::get_all();
		$parts = explode( '.', $key );
		$cur   = $all;
		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return $default;
			}
			$cur = $cur[ $part ];
		}
		return $cur;
	}

	/**
	 * Replace the whole settings array (already sanitized by the caller).
	 */
	public static function save_all( $settings ) {
		update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Seed the option with defaults on activation. Never overwrites an
	 * existing option — reactivating the plugin must not clobber a site's
	 * configured providers/prompts.
	 */
	public static function seed_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			update_option( self::OPTION_KEY, self::defaults(), false );
		}
	}

	/**
	 * Sanitize a raw $_POST-style settings submission and merge it onto the
	 * current stored settings, so a form that submits only some of the
	 * fields never wipes the ones it left out.
	 */
	public static function sanitize_settings( array $input ) {
		$current = self::get_all();

		if ( isset( $input['chat_provider'] ) && is_array( $input['chat_provider'] ) ) {
			$current['chat_provider'] = self::sanitize_provider( $input['chat_provider'], $current['chat_provider'] );
		}

		if ( isset( $input['embeddings_provider'] ) && is_array( $input['embeddings_provider'] ) ) {
			$current['embeddings_provider'] = self::sanitize_provider( $input['embeddings_provider'], $current['embeddings_provider'], true );
		}

		if ( isset( $input['indexed_post_types'] ) ) {
			$types = array_map( 'sanitize_key', (array) $input['indexed_post_types'] );
			$current['indexed_post_types'] = array_values( array_unique( array_filter( $types ) ) );
		}

		if ( isset( $input['excluded_ids'] ) ) {
			$current['excluded_ids'] = self::parse_id_list( $input['excluded_ids'] );
		}

		if ( isset( $input['system_prompt'] ) ) {
			$current['system_prompt'] = sanitize_textarea_field( wp_unslash( (string) $input['system_prompt'] ) );
		}

		if ( isset( $input['guardrail_keywords'] ) ) {
			$current['guardrail_keywords'] = self::parse_line_list( $input['guardrail_keywords'] );
		}

		if ( isset( $input['suggested_prompts'] ) ) {
			$current['suggested_prompts'] = array_slice( self::parse_line_list( $input['suggested_prompts'] ), 0, 6 );
		}

		if ( isset( $input['appearance'] ) && is_array( $input['appearance'] ) ) {
			$current['appearance'] = self::sanitize_appearance( $input['appearance'], $current['appearance'] );
		}

		if ( isset( $input['contact'] ) && is_array( $input['contact'] ) ) {
			$current['contact'] = self::sanitize_contact( $input['contact'], $current['contact'] );
		}

		if ( isset( $input['cost'] ) && is_array( $input['cost'] ) ) {
			foreach ( array( 'chat_input_per_1k', 'chat_output_per_1k', 'embeddings_per_1k' ) as $price_key ) {
				if ( isset( $input['cost'][ $price_key ] ) ) {
					$current['cost'][ $price_key ] = max( 0, (float) $input['cost'][ $price_key ] );
				}
			}
		}

		foreach ( array( 'fallback_message', 'medical_redirect_message', 'rate_limit_message', 'input_too_long_message', 'generic_error_message', 'consent_text' ) as $msg_key ) {
			if ( isset( $input[ $msg_key ] ) ) {
				$current[ $msg_key ] = sanitize_textarea_field( wp_unslash( (string) $input[ $msg_key ] ) );
			}
		}

		foreach ( array( 'rate_limit_per_ip_minute', 'rate_limit_per_ip_hour', 'rate_limit_per_session', 'rate_limit_global_day', 'max_tokens', 'max_context_chars', 'input_char_cap', 'top_k' ) as $int_key ) {
			if ( isset( $input[ $int_key ] ) ) {
				$current[ $int_key ] = max( 0, (int) $input[ $int_key ] );
			}
		}

		if ( isset( $input['retention_days'] ) ) {
			// 0 is a real choice ("keep forever"), not an empty field, so it
			// isn't coerced up to the default.
			$current['retention_days'] = max( 0, min( 3650, (int) $input['retention_days'] ) );
		}

		// An unchecked checkbox posts nothing at all, so its absence can't be
		// read as "false" — the hidden marker tells us the privacy section was
		// part of this submission and the box was therefore deliberately off.
		if ( ! empty( $input['privacy_section'] ) ) {
			$current['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		}

		if ( isset( $input['similarity_floor'] ) ) {
			$current['similarity_floor'] = max( -1.0, min( 1.0, (float) $input['similarity_floor'] ) );
		}

		return $current;
	}

	private static function sanitize_appearance( array $raw, array $existing ) {
		$out = $existing;

		if ( isset( $raw['accent_color'] ) ) {
			$color = sanitize_hex_color( trim( (string) $raw['accent_color'] ) );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}

		if ( isset( $raw['radius'] ) ) {
			$out['radius'] = max( 0, min( 40, (int) $raw['radius'] ) );
		}

		if ( isset( $raw['position'] ) ) {
			$out['position'] = ( 'right' === $raw['position'] ) ? 'right' : 'left';
		}

		if ( isset( $raw['launcher_icon'] ) ) {
			$icon = sanitize_key( $raw['launcher_icon'] );
			$out['launcher_icon'] = in_array( $icon, array( 'chat', 'help', 'leaf' ), true ) ? $icon : 'chat';
		}

		if ( isset( $raw['avatar'] ) ) {
			$avatar         = trim( (string) $raw['avatar'] );
			$out['avatar'] = ( '' === $avatar ) ? '' : esc_url_raw( $avatar );
		}

		foreach ( array( 'header_title', 'header_status', 'teaser_text' ) as $text_key ) {
			if ( isset( $raw[ $text_key ] ) ) {
				$out[ $text_key ] = sanitize_text_field( wp_unslash( (string) $raw[ $text_key ] ) );
			}
		}

		if ( isset( $raw['greeting'] ) ) {
			$out['greeting'] = sanitize_textarea_field( wp_unslash( (string) $raw['greeting'] ) );
		}

		if ( isset( $raw['auto_open_delay'] ) ) {
			$out['auto_open_delay'] = max( 0, min( 120, (int) $raw['auto_open_delay'] ) );
		}

		foreach ( array( 'offset_bottom', 'offset_side', 'mobile_gap' ) as $px_key ) {
			if ( isset( $raw[ $px_key ] ) ) {
				$out[ $px_key ] = max( 0, min( 400, (int) $raw[ $px_key ] ) );
			}
		}

		return $out;
	}

	private static function sanitize_contact( array $raw, array $existing ) {
		$out = $existing;

		foreach ( array( 'booking_url', 'privacy_url' ) as $url_key ) {
			if ( isset( $raw[ $url_key ] ) ) {
				$url = trim( (string) $raw[ $url_key ] );
				// A site-relative path like /make-appointments/ is the normal
				// case here and esc_url_raw would keep it intact, but it also
				// has to survive being stored and re-rendered as-is.
				$out[ $url_key ] = ( '' === $url ) ? '' : esc_url_raw( $url );
			}
		}

		foreach ( array( 'phone', 'whatsapp', 'email', 'address' ) as $text_key ) {
			if ( isset( $raw[ $text_key ] ) ) {
				$out[ $text_key ] = sanitize_text_field( wp_unslash( (string) $raw[ $text_key ] ) );
			}
		}

		return $out;
	}

	/**
	 * Digits-only form of the configured phone number, for tel: links.
	 */
	public static function tel_digits( $phone ) {
		return preg_replace( '/[^0-9+]/', '', (string) $phone );
	}

	public static function add_excluded_id( $id ) {
		$all = self::get_all();
		$id  = (int) $id;
		if ( ! in_array( $id, $all['excluded_ids'], true ) ) {
			$all['excluded_ids'][] = $id;
			self::save_all( $all );
		}
	}

	public static function remove_excluded_id( $id ) {
		$all                 = self::get_all();
		$id                  = (int) $id;
		$all['excluded_ids'] = array_values(
			array_filter(
				$all['excluded_ids'],
				function ( $existing ) use ( $id ) {
					return (int) $existing !== $id;
				}
			)
		);
		self::save_all( $all );
	}

	private static function parse_id_list( $raw ) {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids   = array_map( 'intval', $parts );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function parse_line_list( $raw ) {
		$raw   = wp_unslash( (string) $raw );
		$lines = preg_split( '/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$lines = array_map(
			function ( $l ) {
				return sanitize_text_field( trim( $l ) );
			},
			$lines
		);
		return array_values(
			array_filter(
				$lines,
				function ( $l ) {
					return '' !== $l;
				}
			)
		);
	}

	/**
	 * A blank submitted api_key means "leave it as-is" — the field is
	 * never pre-filled with the real key (see admin/views/settings.php),
	 * so an empty submission is the normal case, not an intent to clear it.
	 */
	private static function sanitize_provider( array $raw, array $existing, $with_dimension = false ) {
		$out = $existing;

		if ( isset( $raw['base_url'] ) ) {
			$url             = trim( (string) $raw['base_url'] );
			$out['base_url'] = ( '' === $url ) ? '' : untrailingslashit( esc_url_raw( $url ) );
		}

		if ( isset( $raw['model'] ) ) {
			$out['model'] = sanitize_text_field( $raw['model'] );
		}

		if ( isset( $raw['api_key'] ) && '' !== trim( (string) $raw['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $raw['api_key'] );
		}

		if ( $with_dimension && isset( $raw['dimension'] ) && '' !== $raw['dimension'] ) {
			$out['dimension'] = absint( $raw['dimension'] );
		}

		return $out;
	}

	/**
	 * Manual Q&A entries live inside the settings blob but need a stable
	 * numeric id (independent of array position) so the indexer can match
	 * pandabot_embeddings rows back to a specific entry across edits.
	 */
	public static function add_manual_qa( $question, $answer ) {
		$all = self::get_all();
		$id  = (int) get_option( 'pandabot_manual_qa_next_id', 1 );
		update_option( 'pandabot_manual_qa_next_id', $id + 1, false );

		$all['manual_qa'][] = array(
			'id'       => $id,
			'question' => sanitize_text_field( $question ),
			'answer'   => sanitize_textarea_field( $answer ),
		);
		self::save_all( $all );

		return $id;
	}

	public static function delete_manual_qa( $id ) {
		$all              = self::get_all();
		$all['manual_qa'] = array_values(
			array_filter(
				$all['manual_qa'],
				function ( $entry ) use ( $id ) {
					return (int) $entry['id'] !== (int) $id;
				}
			)
		);
		self::save_all( $all );
	}

	public static function get_ip_salt() {
		$salt = get_option( self::SALT_OPTION, '' );
		if ( empty( $salt ) ) {
			$salt = wp_generate_password( 32, false );
			update_option( self::SALT_OPTION, $salt, false );
		}
		return $salt;
	}

	public static function regenerate_ip_salt() {
		$salt = wp_generate_password( 32, false );
		update_option( self::SALT_OPTION, $salt, false );
		return $salt;
	}
}
