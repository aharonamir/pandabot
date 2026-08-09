<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Given a user query: embed it, run local cosine top-k over the stored
 * vectors, and assemble a clearly delimited context block for the prompt
 * (plan §6: "pass retrieved chunks as clearly delimited context").
 */
class Pandabot_Retriever {

	/**
	 * @return array{chunks: array, candidates: array, floor: float, error: WP_Error|null}
	 */
	public function retrieve( $query ) {
		$settings = Pandabot_Settings::get_all();
		$top_k    = max( 1, (int) $settings['top_k'] );
		$floor    = (float) $settings['similarity_floor'];

		$vectors = Pandabot_Embeddings::embed_batch( array( $query ) );
		$vector  = $vectors[0];

		if ( is_wp_error( $vector ) ) {
			return array(
				'chunks'     => array(),
				'candidates' => array(),
				'floor'      => $floor,
				'error'      => $vector,
			);
		}

		// Fetch a floor-free superset so callers (the admin chat tester)
		// can show real similarity numbers even when nothing clears the
		// bar — "nothing retrieved" is otherwise a silent dead end to
		// diagnose. Actual context assembly still only uses chunks that
		// pass the configured floor.
		$candidates = Pandabot_Embeddings::search( $vector, max( $top_k, 8 ), -1.0 );
		$chunks     = array_slice(
			array_values(
				array_filter(
					$candidates,
					function ( $c ) use ( $floor ) {
						return $c['similarity'] >= $floor;
					}
				)
			),
			0,
			$top_k
		);

		return array(
			'chunks'     => $chunks,
			'candidates' => $candidates,
			'floor'      => $floor,
			'error'      => null,
		);
	}

	/**
	 * Numbered, delimited context block. Stops adding chunks once
	 * $max_chars is reached rather than truncating mid-chunk.
	 */
	public function assemble_context( array $chunks, $max_chars = 6000 ) {
		$parts = array();
		$total = 0;

		foreach ( $chunks as $i => $chunk ) {
			$piece = sprintf( "[%d] %s\n%s", $i + 1, $chunk['title'], $chunk['content'] );
			$len   = mb_strlen( $piece );

			if ( $total + $len > $max_chars && ! empty( $parts ) ) {
				break;
			}

			$parts[] = $piece;
			$total  += $len;
		}

		return implode( "\n\n---\n\n", $parts );
	}
}
