<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration: rate-limit check → guardrail pre-check → retrieve context
 * → assemble the grounded prompt → call the chat provider → guardrail
 * post-check → log → return the answer.
 *
 * Shared by the admin chat tester and the public /chat endpoint — the
 * difference is only in what each caller returns to its client, not in what
 * happens here.
 */
class Pandabot_Chat {

	/**
	 * @param string      $session_id
	 * @param string      $user_message
	 * @param string|null $ip_hash Pass null to derive it from the current
	 *        request (REMOTE_ADDR, hashed with the site's IP salt) — the
	 *        admin chat tester and the future public endpoint both do this
	 *        by default; tests can override to exercise rate limiting
	 *        deterministically.
	 * @return array{
	 *   success: bool,
	 *   answer?: string,
	 *   guardrail_action?: string,
	 *   chunks?: array,
	 *   candidates?: array,
	 *   floor?: float,
	 *   latency_ms?: int,
	 *   message?: string,
	 *   debug?: string
	 * }
	 */
	public function handle_message( $session_id, $user_message, $ip_hash = null ) {
		$settings = Pandabot_Settings::get_all();
		$start    = microtime( true );

		if ( null === $ip_hash ) {
			$ip_hash = Pandabot_Ratelimit::hash_ip();
		}

		$ratelimit = new Pandabot_Ratelimit();
		$rl_block  = $ratelimit->check( $ip_hash, $session_id, $settings );
		if ( null !== $rl_block ) {
			// Rate-limit breaches are an event (plan §7), not a logged
			// conversation message — the request never reached the model.
			( new Pandabot_Logger() )->log_event( $session_id, 'rate_limited' );
			return $this->short_circuit( $rl_block['answer'], $rl_block['guardrail_action'], $settings );
		}

		$guardrails = new Pandabot_Guardrails();
		$pre_block  = $guardrails->pre_check( $user_message, $settings );
		if ( null !== $pre_block ) {
			$ratelimit->record( $ip_hash );
			$this->log( $session_id, $ip_hash, $user_message, $pre_block['answer'], array(), $pre_block['guardrail_action'], 0, null );
			return $this->short_circuit( $pre_block['answer'], $pre_block['guardrail_action'], $settings );
		}

		$retriever = new Pandabot_Retriever();
		$retrieved = $retriever->retrieve( $user_message );

		if ( is_wp_error( $retrieved['error'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not reach the embeddings provider.', 'pandabot' ),
				'debug'   => $retrieved['error']->get_error_message(),
			);
		}

		$chunks     = $retrieved['chunks'];
		$candidates = $retrieved['candidates'];
		$floor      = $retrieved['floor'];
		$context    = $retriever->assemble_context( $chunks, (int) $settings['max_context_chars'] );

		$cfg = $settings['chat_provider'];
		if ( empty( $cfg['base_url'] ) || empty( $cfg['api_key'] ) || empty( $cfg['model'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Chat provider is not configured.', 'pandabot' ),
			);
		}

		$messages = $this->build_messages( $settings, $context, $user_message );
		$provider = new Pandabot_Provider( $cfg['base_url'], $cfg['api_key'], $cfg['model'] );
		$result   = $provider->chat_completion(
			$messages,
			array( 'max_tokens' => (int) $settings['max_tokens'] )
		);

		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$this->log( $session_id, $ip_hash, $user_message, null, $chunks, 'none', $latency_ms, null );
			return array(
				'success'    => false,
				'message'    => __( 'Could not reach the chat provider.', 'pandabot' ),
				'debug'      => $result->get_error_message(),
				'candidates' => $candidates,
				'floor'      => $floor,
			);
		}

		$raw_answer = isset( $result['choices'][0]['message']['content'] )
			? trim( (string) $result['choices'][0]['message']['content'] )
			: '';
		$usage = Pandabot_Provider::usage_from( $result );

		$guardrail_action = 'none';
		$answer            = $raw_answer;

		$post_block = $guardrails->post_check( $raw_answer, $chunks, $settings );
		if ( null !== $post_block ) {
			$guardrail_action = $post_block['guardrail_action'];
			$answer            = ( null !== $post_block['answer'] ) ? $post_block['answer'] : $settings['fallback_message'];
		}

		$ratelimit->record( $ip_hash );
		$this->log( $session_id, $ip_hash, $user_message, $answer, $chunks, $guardrail_action, $latency_ms, $usage );

		// candidates/floor are debug-only fields for the admin chat
		// tester — the public endpoint must NOT return these.
		return array(
			'success'          => true,
			'answer'           => $answer,
			'guardrail_action' => $guardrail_action,
			'show_cta'         => $this->wants_cta( $guardrail_action, $answer ),
			'chunks'           => $chunks,
			'candidates'       => $candidates,
			'floor'            => $floor,
			'latency_ms'       => $latency_ms,
		);
	}

	private function short_circuit( $answer, $guardrail_action, array $settings ) {
		return array(
			'success'          => true,
			'answer'           => $answer,
			'guardrail_action' => $guardrail_action,
			'show_cta'         => $this->wants_cta( $guardrail_action, $answer ),
			'chunks'           => array(),
			'candidates'       => array(),
			'floor'            => (float) $settings['similarity_floor'],
			'latency_ms'       => 0,
		);
	}

	/**
	 * Whether the widget should render booking/phone CTA buttons under
	 * this answer (plan §5 — every answer "that involves booking/contact"
	 * gets them, not literally every answer, per the mockup: the crisp
	 * insurance FAQ answer has none, but the PANS/PANDAS and process
	 * answers do). Guardrail redirects always get one; otherwise a light
	 * keyword check on the answer's own routing language.
	 */
	private function wants_cta( $guardrail_action, $answer ) {
		if ( in_array( $guardrail_action, array( 'blocked_medical', 'fallback_no_context' ), true ) ) {
			return true;
		}
		$patterns = array( '/לתאם/u', '/שיחת היכרות/u', '/לתיאום/u', '/להתקשר/u', '/ליצור קשר/u' );
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, (string) $answer ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * System prompt (with the clinic's fixed contact facts appended, per
	 * plan §6 — "include contact facts in every system prompt so routing
	 * answers are always correct") + a user turn carrying the delimited
	 * retrieved context ahead of the actual question.
	 */
	/**
	 * Turn the chunks that actually fed the prompt into citation entries for
	 * the widget: one per source, best-scoring chunk first.
	 *
	 * Callers must only use this when the answer was genuinely grounded in
	 * these chunks (guardrail_action === 'none'). A fallback or a medical
	 * redirect still has a retrieval result sitting in memory, and attaching
	 * it would cite pages that did not answer the question — borrowed
	 * authority for a non-answer, which on a clinical site is worse than
	 * showing no sources at all.
	 *
	 * Manual Q&A entries are cited too, even though they have no URL to link
	 * to. Skipping them would misattribute a mixed answer: one manual entry
	 * plus one page would show a single chip pointing at the page, telling the
	 * visitor the whole answer came from there.
	 *
	 * @return array<int, array{title:string, url:string, excerpt:string, kind:string}>
	 */
	public static function sources_from( array $chunks, $limit = 3 ) {
		$sources = array();

		foreach ( $chunks as $chunk ) {
			$type    = isset( $chunk['source_type'] ) ? (string) $chunk['source_type'] : 'post';
			$title   = isset( $chunk['title'] ) ? trim( (string) $chunk['title'] ) : '';
			$url     = isset( $chunk['source_url'] ) ? trim( (string) $chunk['source_url'] ) : '';
			$content = isset( $chunk['content'] ) ? (string) $chunk['content'] : '';

			if ( '' === $title && '' === $content ) {
				continue;
			}

			// Keyed on the source rather than the URL: every manual entry has
			// an empty URL, so a URL key would collapse all of them into one
			// citation. Chunks arrive similarity-ordered, so the first hit for
			// a source is its best one.
			$key = $type . ':' . ( isset( $chunk['source_id'] ) ? (int) $chunk['source_id'] : 0 );
			if ( isset( $sources[ $key ] ) ) {
				continue;
			}

			if ( 'manual' === $type ) {
				// index_manual_entry() stores "question\n\nanswer" and sets the
				// title to the question, so excerpting from the top would print
				// the question twice in one tooltip.
				$parts   = preg_split( "/\r?\n\r?\n/", $content, 2 );
				$content = ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) ? $parts[1] : $content;
			}

			$sources[ $key ] = array(
				'title' => ( '' !== $title ) ? $title : $url,
				'url'   => $url,
				// Chunks run to a couple of thousand characters; a tooltip
				// needs a taste, and the link covers the rest.
				'excerpt' => wp_html_excerpt( $content, 200, '…' ),
				// Explicit rather than inferred from an empty URL: a page whose
				// URL went missing would otherwise be labelled as an FAQ entry.
				'kind'    => ( 'manual' === $type ) ? 'manual' : 'page',
			);

			if ( count( $sources ) >= $limit ) {
				break;
			}
		}

		return array_values( $sources );
	}

	private function build_messages( array $settings, $context, $user_message ) {
		// Same settings the widget's CTA buttons are built from, so the
		// number the bot says out loud and the number the button dials can
		// never drift apart.
		$contact = $settings['contact'];

		// Only state channels that are actually configured. Feeding the model
		// "טלפון: " with nothing after it invites it to invent a number.
		$labels = array(
			'phone'       => 'טלפון',
			'email'       => 'אימייל',
			'address'     => 'כתובת',
			'booking_url' => 'תיאום שיחת היכרות',
		);

		$lines = array();
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $contact[ $key ] ) ) {
				$lines[] = "\n{$label}: {$contact[ $key ]}";
			}
		}

		$contact_facts = $lines
			? "\n\nפרטי יצירת קשר קבועים של הקליניקה (מדויקים תמיד, להשתמש בהם בכל תשובה שמפנה ליצירת קשר):" . implode( '', $lines )
			: '';

		$system_full = $settings['system_prompt'] . $contact_facts;

		$context_block = ( '' !== $context )
			? $context
			: '(לא נמצא תוכן רלוונטי באתר עבור שאלה זו.)';

		$user_full = "הקשר מהאתר — יש להשתמש רק במידע הזה כדי לענות:\n\n{$context_block}\n\n---\n\nשאלת המשתמש: {$user_message}";

		return array(
			array(
				'role'    => 'system',
				'content' => $system_full,
			),
			array(
				'role'    => 'user',
				'content' => $user_full,
			),
		);
	}

	private function log( $session_id, $ip_hash, $user_message, $answer, array $chunks, $guardrail_action, $latency_ms, $usage ) {
		$logger          = new Pandabot_Logger();
		$conversation_id = $logger->get_or_create_conversation( $session_id, $ip_hash );

		$logger->log_message( $conversation_id, 'user', $user_message );

		if ( null !== $answer ) {
			$retrieved_ids = implode( ',', wp_list_pluck( $chunks, 'id' ) );

			$logger->log_message(
				$conversation_id,
				'assistant',
				$answer,
				array(
					'tokens_prompt'     => $usage ? $usage['prompt_tokens'] : null,
					'tokens_completion' => $usage ? $usage['completion_tokens'] : null,
					'retrieved_ids'     => $retrieved_ids,
					'guardrail_action'  => $guardrail_action,
					'latency_ms'        => $latency_ms,
				)
			);
		}
	}
}
