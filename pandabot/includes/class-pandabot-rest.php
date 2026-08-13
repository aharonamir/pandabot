<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes under pandabot/v1: admin tooling (test-connection, knowledge/
 * reindex, manual Q&A, tuning, chat tester) plus the public /chat and
 * /event routes — locked down per plan §11.
 */
class Pandabot_Rest {

	const NAMESPACE_ = 'pandabot/v1';

	/** Ceiling on analytics writes per IP per minute — a real session fires a handful. */
	const EVENTS_PER_MINUTE = 60;

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/admin/test-provider',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'provider' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'chat', 'embeddings' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/manual-qa',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_manual_qa' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'question' => array( 'required' => true, 'type' => 'string' ),
					'answer'   => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/manual-qa/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_manual_qa' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/reindex/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reindex_start' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/reindex/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reindex_step' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/chat-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat_test' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'session_id' => array( 'required' => true, 'type' => 'string' ),
					'message'    => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		// Narrow write route for the two retrieval-tuning knobs, so they can
		// be adjusted from the Knowledge page's tester without a round trip
		// through the full settings form.
		register_rest_route(
			self::NAMESPACE_,
			'/admin/tuning',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_tuning' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'similarity_floor' => array( 'required' => true, 'type' => 'number' ),
					'top_k'            => array( 'required' => true, 'type' => 'integer' ),
					'max_tokens'       => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/exclude-source',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'exclude_source' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/include-source',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'include_source' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		// The public endpoint (plan §11). Locked down per the security
		// musts: the client sends ONLY session_id + message — this
		// callback never reads any other field, so there is nothing to
		// leak or override even if a client tries to smuggle a model/
		// system/context_id param. Origin + a dedicated nonce (separate
		// from wp_rest, since anonymous visitors have no WP session)
		// gate access; rate limiting happens inside Pandabot_Chat itself.
		register_rest_route(
			self::NAMESPACE_,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'public_chat' ),
				'permission_callback' => array( $this, 'public_chat_permission' ),
				'args'                => array(
					'session_id' => array( 'required' => true, 'type' => 'string' ),
					'message'    => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		// Hands the widget a fresh nonce when the one baked into the page has
		// expired. This exists because a page cache (LiteSpeed on this host)
		// serves HTML for far longer than a nonce lives: a visitor loading a
		// 23-hour-old cached page gets a dead nonce, 403 on /chat, and the
		// generic error message forever — reloading cannot help, since the
		// reload comes from the same cache.
		//
		// Origin-checked but obviously NOT nonce-checked, which costs nothing:
		// the nonce it returns was already free to anyone who could fetch the
		// public page it was rendered into. It is a bot speedbump for an
		// endpoint with no authenticated user, not a CSRF token.
		//
		// POST rather than GET on purpose — LiteSpeed and friends can be
		// configured to cache REST GETs, which would reinstate the exact bug
		// this route exists to fix.
		register_rest_route(
			self::NAMESPACE_,
			'/nonce',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'public_nonce' ),
				'permission_callback' => array( $this, 'public_origin_permission' ),
			)
		);

		// Widget analytics (plan §8 funnel). Same origin+nonce gate as /chat;
		// event_type is validated against a fixed whitelist so the client can
		// only ever write event names the dashboard already knows about.
		register_rest_route(
			self::NAMESPACE_,
			'/event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'public_event' ),
				'permission_callback' => array( $this, 'public_chat_permission' ),
				'args'                => array(
					'session_id' => array( 'required' => true, 'type' => 'string' ),
					'event_type' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array(
							'open',
							'first_message',
							'suggested_prompt_click',
							'cta_click_booking',
							'cta_click_phone',
							'cta_click_whatsapp',
						),
					),
				),
			)
		);
	}

	/**
	 * Writes one analytics event. Cheap by design (no provider call), but
	 * still capped per IP so it can't be used to flood the events table.
	 */
	public function public_event( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $session_id ) ) {
			return new WP_REST_Response( array( 'success' => false ), 400 );
		}

		$key   = 'pandabot_ev_' . Pandabot_Ratelimit::hash_ip();
		$count = (int) get_transient( $key );
		if ( $count >= self::EVENTS_PER_MINUTE ) {
			return new WP_REST_Response( array( 'success' => false ), 429 );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		( new Pandabot_Logger() )->log_event( $session_id, $request->get_param( 'event_type' ) );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Origin check only — the gate for /nonce, which by definition cannot
	 * require the thing it hands out.
	 */
	public function public_origin_permission( WP_REST_Request $request ) {
		if ( ! $this->origin_allowed( $request ) ) {
			return new WP_Error( 'pandabot_forbidden', __( 'Forbidden.', 'pandabot' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * A freshly minted public-chat nonce. nocache_headers() so no proxy or
	 * page cache between here and the visitor can store it — a cached nonce
	 * is the problem this route solves, not something to reintroduce.
	 */
	public function public_nonce() {
		nocache_headers();

		return new WP_REST_Response(
			array(
				'success' => true,
				'nonce'   => wp_create_nonce( Pandabot_Widget::NONCE_ACTION ),
			),
			200
		);
	}

	public function public_chat_permission( WP_REST_Request $request ) {
		if ( ! $this->origin_allowed( $request ) ) {
			return new WP_Error( 'pandabot_forbidden', __( 'Forbidden.', 'pandabot' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-PandaBot-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, Pandabot_Widget::NONCE_ACTION ) ) {
			return new WP_Error( 'pandabot_forbidden', __( 'Forbidden.', 'pandabot' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Same-origin check via Origin/Referer host match against home_url().
	 * A public endpoint's origin check is never airtight on its own (a
	 * server-side script can forge these headers) — it's one layer among
	 * nonce + rate limiting, per plan §7/§11, not a standalone guarantee.
	 */
	private function origin_allowed( WP_REST_Request $request ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$candidate = $request->get_header( 'Origin' );
		if ( empty( $candidate ) ) {
			$candidate = $request->get_header( 'Referer' );
		}
		if ( empty( $candidate ) ) {
			return false;
		}
		return wp_parse_url( $candidate, PHP_URL_HOST ) === $home_host;
	}

	/**
	 * The public endpoint. Reads ONLY session_id + message — nothing else
	 * from the request is ever consulted. On any internal failure, returns
	 * the generic configured message, never the real provider/internal
	 * error (that's logged server-side inside Pandabot_Provider instead).
	 */
	public function public_chat( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$message    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $session_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'answer'  => __( 'Invalid session.', 'pandabot' ),
				),
				400
			);
		}

		if ( '' === trim( $message ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'answer'  => __( 'Empty message.', 'pandabot' ),
				),
				400
			);
		}

		$chat   = new Pandabot_Chat();
		$result = $chat->handle_message( $session_id, $message );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'answer'  => Pandabot_Settings::get( 'generic_error_message' ),
				),
				200
			);
		}

		// Sources only when the answer really came from them. Any guardrail
		// action means the reply is a boundary or a fallback, not something
		// the retrieved pages support — see Pandabot_Chat::sources_from().
		$sources = ( 'none' === $result['guardrail_action'] )
			? Pandabot_Chat::sources_from( $result['chunks'], 3 )
			: array();

		return new WP_REST_Response(
			array(
				'success'          => true,
				'answer'           => $result['answer'],
				'guardrail_action' => $result['guardrail_action'],
				'show_cta'         => $result['show_cta'],
				'sources'          => $sources,
			),
			200
		);
	}

	/**
	 * One-click "stop indexing this page" from the Knowledge page's
	 * per-source list — adds the ID to excluded_ids AND removes its
	 * existing embeddings rows immediately, rather than leaving stale
	 * (now-unwanted) rows around until the next full reindex.
	 */
	public function exclude_source( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		Pandabot_Settings::add_excluded_id( $id );
		( new Pandabot_Indexer() )->remove_post( $id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Re-includes and immediately (re)indexes the single post, so the
	 * per-source list reflects reality without needing a full reindex.
	 */
	public function include_source( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		Pandabot_Settings::remove_excluded_id( $id );
		$result = ( new Pandabot_Indexer() )->index_post( $id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'chunks'  => $result['chunks'],
			),
			200
		);
	}

	public function update_tuning( WP_REST_Request $request ) {
		$floor      = (float) $request->get_param( 'similarity_floor' );
		$floor      = max( -1.0, min( 1.0, $floor ) );
		$top_k      = max( 1, (int) $request->get_param( 'top_k' ) );
		$max_tokens = max( 50, min( 4000, (int) $request->get_param( 'max_tokens' ) ) );

		$all                     = Pandabot_Settings::get_all_raw();
		$all['similarity_floor'] = $floor;
		$all['top_k']            = $top_k;
		$all['max_tokens']       = $max_tokens;
		Pandabot_Settings::save_all( $all );

		return new WP_REST_Response(
			array(
				'success'          => true,
				'similarity_floor' => $floor,
				'top_k'            => $top_k,
				'max_tokens'       => $max_tokens,
			),
			200
		);
	}

	/**
	 * Admin-only tester. Runs the identical pipeline as the public route
	 * (guardrails and rate limits included, since both live inside
	 * Pandabot_Chat), but returns the full result array — retrieved chunks
	 * and provider error detail — which the public route must never do.
	 */
	public function chat_test( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$message    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === trim( $message ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Message is empty.', 'pandabot' ),
				),
				200
			);
		}

		$chat   = new Pandabot_Chat();
		$result = $chat->handle_message( $session_id, $message );

		return new WP_REST_Response( $result, 200 );
	}

	public function create_manual_qa( WP_REST_Request $request ) {
		$question = sanitize_text_field( (string) $request->get_param( 'question' ) );
		$answer   = sanitize_textarea_field( (string) $request->get_param( 'answer' ) );

		if ( '' === trim( $question ) || '' === trim( $answer ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Question and answer are both required.', 'pandabot' ),
				),
				200
			);
		}

		$id = Pandabot_Settings::add_manual_qa( $question, $answer );

		$indexer = new Pandabot_Indexer();
		$result  = $indexer->index_manual_entry(
			array(
				'id'       => $id,
				'question' => $question,
				'answer'   => $answer,
			)
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'chunks'  => $result['chunks'],
			),
			200
		);
	}

	public function delete_manual_qa( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$indexer = new Pandabot_Indexer();
		$indexer->remove_manual_entry( $id );
		Pandabot_Settings::delete_manual_qa( $id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function reindex_start() {
		$indexer = new Pandabot_Indexer();
		$total   = $indexer->start_reindex();

		return new WP_REST_Response(
			array(
				'success' => true,
				'total'   => $total,
			),
			200
		);
	}

	public function reindex_step() {
		$indexer = new Pandabot_Indexer();
		$result  = $indexer->step_reindex();

		return new WP_REST_Response( array_merge( array( 'success' => true ), $result ), 200 );
	}

	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Admin-only, authenticated tool — safe to return the real provider
	 * error message here (unlike the public /chat endpoint) so the site
	 * owner can actually debug a bad key/URL/model.
	 */
	public function test_provider( WP_REST_Request $request ) {
		$which    = $request->get_param( 'provider' );
		$settings = Pandabot_Settings::get_all();
		$cfg      = ( 'embeddings' === $which ) ? $settings['embeddings_provider'] : $settings['chat_provider'];

		if ( empty( $cfg['base_url'] ) || empty( $cfg['api_key'] ) || empty( $cfg['model'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Base URL, API key, and model must all be set before testing.', 'pandabot' ),
				),
				200
			);
		}

		$provider = new Pandabot_Provider( $cfg['base_url'], $cfg['api_key'], $cfg['model'] );

		$start = microtime( true );

		if ( 'embeddings' === $which ) {
			$result = $provider->embeddings( 'PandaBot connection test.' );
		} else {
			$result = $provider->chat_completion(
				array( array( 'role' => 'user', 'content' => 'ping' ) ),
				array( 'max_tokens' => 5 )
			);
		}

		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				200
			);
		}

		if ( 'embeddings' === $which ) {
			$vector = isset( $result['data'][0]['embedding'] ) && is_array( $result['data'][0]['embedding'] )
				? $result['data'][0]['embedding']
				: null;
			$dimension = $vector ? count( $vector ) : null;

			if ( $dimension ) {
				$all = Pandabot_Settings::get_all_raw();
				$all['embeddings_provider']['dimension'] = $dimension;
				Pandabot_Settings::save_all( $all );
			}

			return new WP_REST_Response(
				array(
					'success'    => (bool) $dimension,
					'message'    => $dimension
						? sprintf(
							/* translators: 1: vector dimension, 2: latency in ms */
							__( 'Success — vector dimension %1$d, %2$d ms.', 'pandabot' ),
							$dimension,
							$latency_ms
						)
						: __( 'Provider responded but no embedding vector was found in the response.', 'pandabot' ),
					'dimension'  => $dimension,
					'latency_ms' => $latency_ms,
				),
				200
			);
		}

		$reply_present = isset( $result['choices'][0]['message']['content'] );

		return new WP_REST_Response(
			array(
				'success'    => $reply_present,
				'message'    => $reply_present
					? sprintf(
						/* translators: %d: latency in ms */
						__( 'Success — %d ms.', 'pandabot' ),
						$latency_ms
					)
					: __( 'Provider responded but no message content was found in the response.', 'pandabot' ),
				'latency_ms' => $latency_ms,
			),
			200
		);
	}
}
