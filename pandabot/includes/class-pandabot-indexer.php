<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates chunking + embedding + DB storage for both WP posts/pages
 * and manual Q&A entries, plus the batched "reindex all" state machine.
 *
 * Stateless class — instantiate freely per-request. Only init() attaches
 * WordPress hooks, so REST handlers that just need index_post()/etc. can
 * create an instance without side effects.
 */
class Pandabot_Indexer {

	const EMBED_BATCH_SIZE  = 20; // texts per provider embeddings call.
	const REINDEX_STEP_SIZE = 3;  // queue items processed per "step" REST call.
	const HEALTH_OPTION     = 'pandabot_index_health';
	const QUEUE_TRANSIENT   = 'pandabot_reindex_queue';
	const QUEUE_TTL         = 2 * HOUR_IN_SECONDS;

	public function init() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
	}

	// ---------------------------------------------------------------
	// save_post / delete hooks
	// ---------------------------------------------------------------

	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			$this->remove_post( $post_id );
			return;
		}

		$settings = Pandabot_Settings::get_all();
		$types    = (array) $settings['indexed_post_types'];
		$excluded = array_map( 'intval', (array) $settings['excluded_ids'] );

		if ( ! in_array( $post->post_type, $types, true ) || in_array( (int) $post_id, $excluded, true ) ) {
			$this->remove_post( $post_id );
			return;
		}

		$this->index_post( $post_id );
		Pandabot_Faq_Schema::maybe_generate_for_post( $post_id, $post );
	}

	public function on_delete_post( $post_id ) {
		$this->remove_post( $post_id );
	}

	public function remove_post( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type IN ('post','page') AND source_id = %d", $post_id ) );
	}

	public function remove_manual_entry( $entry_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type = 'manual' AND source_id = %d", (int) $entry_id ) );
	}

	// ---------------------------------------------------------------
	// Chunk + embed + upsert for a single source
	// ---------------------------------------------------------------

	/**
	 * @return array{chunks:int,embedded:int,reused:int,failed:int}
	 */
	public function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->remove_post( $post_id );
			return array(
				'chunks'   => 0,
				'embedded' => 0,
				'reused'   => 0,
				'failed'   => 0,
			);
		}

		$chunks = $this->faq_aware_chunks( $post );
		if ( null === $chunks ) {
			$clean  = Pandabot_Chunker::clean_html( $post->post_content );
			$chunks = Pandabot_Chunker::chunk_text( $clean );
		}

		return $this->sync_source(
			'page' === $post->post_type ? 'page' : 'post',
			$post_id,
			(string) get_permalink( $post_id ),
			(string) get_the_title( $post_id ),
			$chunks
		);
	}

	/**
	 * For FAQ-style posts (H2-question / paragraph-answer pattern, same
	 * detection as Pandabot_Faq_Schema), one chunk per Question/Answer pair
	 * instead of token-window chunking — keeps each Q&A intact for
	 * retrieval rather than letting it get split mid-answer or merged with
	 * a neighboring pair.
	 *
	 * Deliberately re-derives pairs from post_content via
	 * Pandabot_Faq_Schema::extract_qa_pairs() rather than reading the
	 * stored _pandabot_faq_schema postmeta, so retrieval accuracy never
	 * depends on that postmeta write having succeeded (see the known-issue
	 * note on Pandabot_Faq_Schema — its JSON-LD output can be corrupted in
	 * production independently of this).
	 *
	 * @return string[]|null Null = not FAQ-style, caller falls back to
	 *                        normal token-window chunking.
	 */
	private function faq_aware_chunks( WP_Post $post ) {
		$pairs = Pandabot_Faq_Schema::extract_qa_pairs( $post->post_content );

		if ( count( $pairs ) < Pandabot_Faq_Schema::MIN_PAIRS ) {
			return null;
		}

		return array_map(
			function ( $pair ) {
				return trim( $pair['question'] . "\n\n" . $pair['answer'] );
			},
			$pairs
		);
	}

	/**
	 * @param array{id:int,question:string,answer:string} $entry
	 */
	public function index_manual_entry( array $entry ) {
		$text   = trim( $entry['question'] . "\n\n" . $entry['answer'] );
		$chunks = Pandabot_Chunker::chunk_text( $text );

		return $this->sync_source( 'manual', (int) $entry['id'], '', $entry['question'], $chunks );
	}

	/**
	 * Diffs new chunk texts against existing rows for this source (matched
	 * by chunk_index + content_hash), only pays for embeddings on chunks
	 * that are new or changed, and drops rows whose chunk_index no longer
	 * exists (plan §4: skip re-embedding unchanged chunks).
	 */
	private function sync_source( $source_type, $source_id, $source_url, $title, array $chunk_texts ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';

		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, chunk_index, content_hash FROM {$table} WHERE source_type = %s AND source_id = %d",
				$source_type,
				$source_id
			),
			ARRAY_A
		);

		$existing_by_index = array();
		foreach ( $existing as $row ) {
			$existing_by_index[ (int) $row['chunk_index'] ] = $row;
		}

		$hashes   = array();
		$to_embed = array(); // chunk_index => text
		$reused   = 0;

		foreach ( $chunk_texts as $i => $text ) {
			$hash         = sha1( $text );
			$hashes[ $i ] = $hash;

			if ( isset( $existing_by_index[ $i ] ) && $existing_by_index[ $i ]['content_hash'] === $hash ) {
				++$reused;
				continue;
			}
			$to_embed[ $i ] = $text;
		}

		$embedded = 0;
		$failed   = 0;

		if ( ! empty( $to_embed ) ) {
			$indices = array_keys( $to_embed );
			$texts   = array_values( $to_embed );
			$batches = array_chunk( range( 0, count( $texts ) - 1 ), self::EMBED_BATCH_SIZE );

			foreach ( $batches as $positions ) {
				$batch_texts = array();
				foreach ( $positions as $pos ) {
					$batch_texts[] = $texts[ $pos ];
				}

				$vectors = Pandabot_Embeddings::embed_batch( $batch_texts );

				foreach ( $positions as $local_i => $pos ) {
					$chunk_index = $indices[ $pos ];
					$vector      = $vectors[ $local_i ];

					if ( is_wp_error( $vector ) ) {
						++$failed;
						continue;
					}

					$content = $texts[ $pos ];
					$data    = array(
						'source_type'    => $source_type,
						'source_id'      => $source_id,
						'source_url'     => $source_url,
						'title'          => $title,
						'chunk_index'    => $chunk_index,
						'content'        => $content,
						'embedding'      => Pandabot_Embeddings::pack_vector( $vector ),
						'token_estimate' => Pandabot_Chunker::estimate_tokens( $content ),
						'content_hash'   => $hashes[ $chunk_index ],
						'lang'           => 'he',
						'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
					);

					if ( isset( $existing_by_index[ $chunk_index ] ) ) {
						$wpdb->update( $table, $data, array( 'id' => $existing_by_index[ $chunk_index ]['id'] ) );
					} else {
						$wpdb->insert( $table, $data );
					}
					++$embedded;
				}
			}
		}

		// Drop rows left over from a previously longer version of this source.
		$new_count = count( $chunk_texts );
		foreach ( $existing_by_index as $idx => $row ) {
			if ( $idx >= $new_count ) {
				$wpdb->delete( $table, array( 'id' => $row['id'] ) );
			}
		}

		return array(
			'chunks'   => $new_count,
			'embedded' => $embedded,
			'reused'   => $reused,
			'failed'   => $failed,
		);
	}

	// ---------------------------------------------------------------
	// Batched "reindex all" — driven by repeated REST calls from the
	// admin's browser tab rather than WP-Cron, so there's no dependency
	// on the site's cron actually firing reliably on shared hosting.
	// ---------------------------------------------------------------

	public function build_reindex_queue() {
		$settings = Pandabot_Settings::get_all();
		$types    = array_map( 'sanitize_key', (array) $settings['indexed_post_types'] );
		$excluded = array_map( 'intval', (array) $settings['excluded_ids'] );

		$queue = array();

		if ( ! empty( $types ) ) {
			$post_ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__not_in'   => $excluded,
					'no_found_rows'  => true,
				)
			);
			foreach ( $post_ids as $id ) {
				$queue[] = array(
					'type' => 'wp',
					'id'   => (int) $id,
				);
			}
		}

		foreach ( (array) $settings['manual_qa'] as $entry ) {
			if ( ! empty( $entry['id'] ) ) {
				$queue[] = array(
					'type' => 'manual',
					'id'   => (int) $entry['id'],
				);
			}
		}

		return $queue;
	}

	public function start_reindex() {
		$queue = $this->build_reindex_queue();
		set_transient( self::QUEUE_TRANSIENT, $queue, self::QUEUE_TTL );

		update_option(
			self::HEALTH_OPTION,
			array(
				'last_run'     => null,
				'running'      => true,
				'total'        => count( $queue ),
				'done'         => 0,
				'failed_items' => array(),
			),
			false
		);

		return count( $queue );
	}

	public function step_reindex() {
		$queue = get_transient( self::QUEUE_TRANSIENT );
		if ( ! is_array( $queue ) ) {
			return array(
				'finished'  => true,
				'done'      => 0,
				'total'     => 0,
				'remaining' => 0,
				'failed'    => 0,
			);
		}

		$health = get_option(
			self::HEALTH_OPTION,
			array(
				'total'        => count( $queue ),
				'done'         => 0,
				'failed_items' => array(),
			)
		);

		$batch = array_splice( $queue, 0, self::REINDEX_STEP_SIZE );

		foreach ( $batch as $item ) {
			$label  = '';
			$result = array( 'failed' => 0 );

			if ( 'wp' === $item['type'] ) {
				$title  = get_the_title( $item['id'] );
				$label  = $title ? $title : ( '#' . $item['id'] );
				$result = $this->index_post( $item['id'] );
			} elseif ( 'manual' === $item['type'] ) {
				$entry = $this->find_manual_entry( $item['id'] );
				if ( $entry ) {
					$label  = mb_substr( $entry['question'], 0, 60 );
					$result = $this->index_manual_entry( $entry );
				}
			}

			++$health['done'];
			if ( ! empty( $result['failed'] ) ) {
				$health['failed_items'][] = array(
					'type'  => $item['type'],
					'id'    => $item['id'],
					'title' => $label,
				);
			}
		}

		if ( empty( $queue ) ) {
			delete_transient( self::QUEUE_TRANSIENT );
			$health['running']  = false;
			$health['last_run'] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::HEALTH_OPTION, $health, false );

			return array(
				'finished'  => true,
				'done'      => $health['done'],
				'total'     => $health['total'],
				'remaining' => 0,
				'failed'    => count( $health['failed_items'] ),
			);
		}

		set_transient( self::QUEUE_TRANSIENT, $queue, self::QUEUE_TTL );
		update_option( self::HEALTH_OPTION, $health, false );

		return array(
			'finished'  => false,
			'done'      => $health['done'],
			'total'     => $health['total'],
			'remaining' => count( $queue ),
			'failed'    => count( $health['failed_items'] ),
		);
	}

	private function find_manual_entry( $id ) {
		$settings = Pandabot_Settings::get_all();
		foreach ( (array) $settings['manual_qa'] as $entry ) {
			if ( (int) $entry['id'] === (int) $id ) {
				return $entry;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------
	// Readouts for the Knowledge admin page
	// ---------------------------------------------------------------

	public function get_health() {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';

		$health = wp_parse_args(
			get_option( self::HEALTH_OPTION, array() ),
			array(
				'last_run'     => null,
				'running'      => false,
				'total'        => 0,
				'done'         => 0,
				'failed_items' => array(),
			)
		);

		$health['chunk_count']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$health['source_count'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT CONCAT(source_type,':',source_id)) FROM {$table}" );

		return $health;
	}

	public function get_source_list() {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';

		return $wpdb->get_results(
			"SELECT source_type, source_id, MAX(title) AS title, MAX(source_url) AS source_url,
			        COUNT(*) AS chunk_count, MAX(updated_at) AS updated_at
			 FROM {$table}
			 GROUP BY source_type, source_id
			 ORDER BY source_type ASC, title ASC",
			ARRAY_A
		);
	}
}
