<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-generates FAQPage JSON-LD for posts that follow the "H2 question ->
 * paragraph(s) answer" pattern, and prints it in wp_head. Generation is
 * driven from Pandabot_Indexer::on_save_post() (right after index_post())
 * rather than its own save_post hook, so it shares that method's publish/
 * post-type/exclusion guard instead of duplicating it. This class only owns
 * its own hooks for the per-post opt-out checkbox and the wp_head output.
 *
 * KNOWN ISSUE (unresolved): the stored _pandabot_faq_schema value has been
 * observed on production with unescaped `"` inside Hebrew string values —
 * invalid JSON — despite maybe_generate_for_post() building it through a
 * single wp_json_encode() call on the whole structure, which passed
 * extensive local testing (including headings/answers containing literal
 * `"` characters) without ever reproducing the corruption. Root cause is
 * unconfirmed; leading theory is a server-side filter or security
 * plugin/WAF touching the postmeta write on that specific host, not this
 * generation code. Currently mitigated only by avoiding literal `"`
 * characters in FAQ post content. This is an open SEO-schema bug — it does
 * NOT affect chatbot retrieval accuracy, since Pandabot_Indexer's
 * FAQ-aware chunking re-derives pairs from post_content directly rather
 * than reading this postmeta (see Pandabot_Indexer::faq_aware_chunks()).
 */
class Pandabot_Faq_Schema {

	const META_DISABLED = '_pandabot_faq_disabled';
	const META_HASH      = '_pandabot_faq_hash';
	const META_SCHEMA     = '_pandabot_faq_schema';

	// Heuristics from the plan: skip the first H2 (usually an intro, handled
	// in extract_qa_pairs), skip headings shorter than this, and require at
	// least this many pairs before treating a post as FAQ-style at all.
	const MIN_HEADING_WORDS = 3;
	const MIN_PAIRS         = 2;

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_action( 'wp_head', array( $this, 'output_schema' ) );
	}

	// ---------------------------------------------------------------
	// Per-post manual override (postmeta checkbox in the editor)
	// ---------------------------------------------------------------

	public function add_meta_box() {
		$settings = Pandabot_Settings::get_all();
		foreach ( (array) $settings['indexed_post_types'] as $post_type ) {
			add_meta_box(
				'pandabot_faq_schema',
				__( 'PandaBot FAQ Schema', 'pandabot' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side'
			);
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'pandabot_faq_schema_save', 'pandabot_faq_schema_nonce' );
		$disabled = (bool) get_post_meta( $post->ID, self::META_DISABLED, true );
		?>
		<label>
			<input type="checkbox" name="pandabot_faq_disabled" value="1" <?php checked( $disabled ); ?> />
			<?php esc_html_e( 'Disable automatic FAQ schema for this post', 'pandabot' ); ?>
		</label>
		<?php
		if ( ! $disabled && get_post_meta( $post->ID, self::META_SCHEMA, true ) ) {
			echo '<p class="description">' . esc_html__( 'FAQ schema is currently generated for this post.', 'pandabot' ) . '</p>';
		}

		$this->render_debug_raw_postmeta( $post );
	}

	/**
	 * TEMPORARY DIAGNOSTIC — added to inspect the raw _pandabot_faq_schema
	 * postmeta value from wp-admin (no shell/WP-CLI access available while
	 * debugging the FAQPage JSON-LD corruption on the live site). Shows the
	 * exact stored string, untouched, plus a json_decode() validity check
	 * against that same raw value — nothing here re-encodes or reformats it.
	 *
	 * Remove this method and its call site once the corruption question is
	 * resolved — it has no reason to exist outside this investigation.
	 */
	private function render_debug_raw_postmeta( $post ) {
		$raw = get_post_meta( $post->ID, self::META_SCHEMA, true );

		echo '<hr />';
		echo '<p><strong>' . esc_html__( 'DEBUG (temporary): raw _pandabot_faq_schema', 'pandabot' ) . '</strong></p>';

		if ( $raw ) {
			json_decode( $raw );
			$json_error = json_last_error();

			if ( JSON_ERROR_NONE === $json_error ) {
				echo '<p style="color:green;">' . esc_html__( 'Valid JSON', 'pandabot' ) . '</p>';
			} else {
				echo '<p style="color:red;">' . esc_html( 'INVALID JSON: ' . json_last_error_msg() ) . '</p>';
			}

			echo '<textarea readonly rows="10" style="width:100%;direction:ltr;font-family:monospace;font-size:11px;">' . esc_textarea( $raw ) . '</textarea>';
		} else {
			echo '<p>' . esc_html__( '(no schema currently stored)', 'pandabot' ) . '</p>';
		}

		// Diagnostic: does the hash guard explain a stuck/stale value? If the
		// freshly-computed hash already matches the stored one, a normal
		// re-save will skip regeneration entirely (by design) — so a broken
		// stored value written by something other than this code would
		// survive re-saves indefinitely unless that guard is bypassed.
		$pairs         = self::extract_qa_pairs( $post->post_content );
		$fresh_hash    = ( count( $pairs ) >= self::MIN_PAIRS ) ? sha1( wp_json_encode( $pairs ) ) : '(fewer than ' . self::MIN_PAIRS . ' pairs — would not generate)';
		$stored_hash   = get_post_meta( $post->ID, self::META_HASH, true );
		$stored_hash   = $stored_hash ? $stored_hash : '(none stored)';
		echo '<p style="font-size:11px;color:#666;">' . esc_html( 'stored hash: ' . $stored_hash ) . '<br />' . esc_html( 'fresh hash: ' . $fresh_hash ) . '</p>';

		?>
		<p>
			<label>
				<input type="checkbox" name="pandabot_faq_force_regen" value="1" />
				<?php esc_html_e( 'Force regenerate on save (clears stored schema/hash first, bypassing the unchanged-content guard)', 'pandabot' ); ?>
			</label>
		</p>
		<?php
	}

	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['pandabot_faq_schema_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pandabot_faq_schema_nonce'] ) ), 'pandabot_faq_schema_save' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['pandabot_faq_disabled'] ) ) {
			update_post_meta( $post_id, self::META_DISABLED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DISABLED );
		}

		// TEMPORARY DIAGNOSTIC — see render_debug_raw_postmeta(). Runs at the
		// default save_post priority (10), before Pandabot_Indexer's
		// on_save_post (priority 20) calls maybe_generate_for_post(), so
		// clearing here guarantees the guard sees no stored hash and
		// regenerates unconditionally on this same save.
		if ( ! empty( $_POST['pandabot_faq_force_regen'] ) ) {
			self::clear_schema( $post_id );
		}
	}

	// ---------------------------------------------------------------
	// Generation — called from Pandabot_Indexer::on_save_post()
	// ---------------------------------------------------------------

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function maybe_generate_for_post( $post_id, $post ) {
		if ( get_post_meta( $post_id, self::META_DISABLED, true ) ) {
			self::clear_schema( $post_id );
			return;
		}

		$pairs = self::extract_qa_pairs( $post->post_content );

		if ( count( $pairs ) < self::MIN_PAIRS ) {
			self::clear_schema( $post_id );
			return;
		}

		// Only regenerate when the extracted Q/A content actually changed,
		// not on every save (plan requirement — avoid pointless DB writes
		// and keep the postmeta stable across unrelated edits).
		$hash = sha1( wp_json_encode( $pairs ) );
		if ( $hash === get_post_meta( $post_id, self::META_HASH, true ) ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				function ( $pair ) {
					return array(
						'@type'          => 'Question',
						'name'           => $pair['question'],
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $pair['answer'],
						),
					);
				},
				$pairs
			),
		);

		update_post_meta( $post_id, self::META_SCHEMA, wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) );
		update_post_meta( $post_id, self::META_HASH, $hash );
	}

	private static function clear_schema( $post_id ) {
		delete_post_meta( $post_id, self::META_SCHEMA );
		delete_post_meta( $post_id, self::META_HASH );
	}

	// ---------------------------------------------------------------
	// Front-end output
	// ---------------------------------------------------------------

	public function output_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$schema = get_post_meta( $post_id, self::META_SCHEMA, true );
		if ( ! $schema ) {
			return;
		}

		// Defensive: a literal "</script>" inside extracted answer text
		// (e.g. a post quoting markup) must not close the tag early.
		$schema = str_replace( '</script>', '<\/script>', $schema );

		echo '<script type="application/ld+json">' . $schema . "</script>\n";
	}

	// ---------------------------------------------------------------
	// Parsing: post_content -> Question/Answer pairs
	// ---------------------------------------------------------------

	/**
	 * @return array<int, array{question:string, answer:string}>
	 */
	public static function extract_qa_pairs( $content ) {
		$html = is_string( $content ) ? $content : '';

		if ( function_exists( 'do_blocks' ) ) {
			$html = do_blocks( $html );
		}
		$html = strip_shortcodes( $html );

		if ( '' === trim( $html ) || false === stripos( $html, '<h2' ) ) {
			return array();
		}

		$doc = new DOMDocument();
		$internal_errors = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="pandabot-faq-root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		$root = $doc->getElementById( 'pandabot-faq-root' );
		if ( ! $root ) {
			return array();
		}

		$pairs        = array();
		$h2_seen      = -1;
		$question     = null;
		$answer_nodes = array();

		foreach ( $root->childNodes as $node ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );

			if ( 'h2' === $tag ) {
				++$h2_seen;

				if ( null !== $question ) {
					self::maybe_add_pair( $pairs, $question, $answer_nodes );
				}

				// Skip the first H2 by default — usually an intro, not a
				// real question.
				$question     = ( 0 === $h2_seen ) ? null : self::clean_node_text( $node );
				$answer_nodes = array();
				continue;
			}

			if ( null !== $question && in_array( $tag, array( 'p', 'ul', 'ol' ), true ) ) {
				$answer_nodes[] = $node;
			}
		}

		if ( null !== $question ) {
			self::maybe_add_pair( $pairs, $question, $answer_nodes );
		}

		return $pairs;
	}

	private static function maybe_add_pair( array &$pairs, $question, array $answer_nodes ) {
		$word_count = count( preg_split( '/\s+/u', trim( $question ), -1, PREG_SPLIT_NO_EMPTY ) );
		if ( $word_count < self::MIN_HEADING_WORDS ) {
			return;
		}

		$answer_parts = array();
		foreach ( $answer_nodes as $node ) {
			$tag = strtolower( $node->nodeName );

			if ( 'p' === $tag ) {
				$text = self::clean_node_text( $node );
				if ( '' !== $text ) {
					$answer_parts[] = $text;
				}
				continue;
			}

			foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
				$text = self::clean_node_text( $li );
				if ( '' !== $text ) {
					$answer_parts[] = $text;
				}
			}
		}

		$answer = trim( implode( ' ', $answer_parts ) );
		if ( '' === $question || '' === $answer ) {
			return;
		}

		$pairs[] = array(
			'question' => $question,
			'answer'   => $answer,
		);
	}

	private static function clean_node_text( DOMNode $node ) {
		$text = $node->textContent;
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}
}
