<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embeddings provider calls + local vector storage format + brute-force
 * cosine search. Vectors are stored as packed float32 bytes (pack('g*',...) —
 * 'g' is explicit little-endian IEEE-754 single precision, portable across
 * PHP builds, unlike the machine-endian 'f') rather than JSON, since it's
 * a quarter the size on disk and unpacks fast enough in PHP for this
 * site's scale (plan §3/§4: no external vector DB, brute-force scan is fine).
 */
class Pandabot_Embeddings {

	/**
	 * Embed a batch of chunk texts against the configured embeddings
	 * provider (independent from the chat provider — plan §4).
	 *
	 * @return array Same length/order as $texts. Each entry is either a
	 *         float[] vector or a WP_Error for that specific item.
	 */
	public static function embed_batch( array $texts ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$cfg = Pandabot_Settings::get( 'embeddings_provider' );

		if ( empty( $cfg['base_url'] ) || empty( $cfg['api_key'] ) || empty( $cfg['model'] ) ) {
			$err = new WP_Error( 'pandabot_embeddings_not_configured', __( 'Embeddings provider is not configured.', 'pandabot' ) );
			return array_fill( 0, count( $texts ), $err );
		}

		$texts    = array_values( $texts );
		$provider = new Pandabot_Provider( $cfg['base_url'], $cfg['api_key'], $cfg['model'] );
		$result   = $provider->embeddings( $texts );

		if ( is_wp_error( $result ) ) {
			return array_fill( 0, count( $texts ), $result );
		}

		if ( empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
			$err = new WP_Error( 'pandabot_embeddings_bad_response', __( 'Embeddings provider returned no data.', 'pandabot' ) );
			return array_fill( 0, count( $texts ), $err );
		}

		// Respect each item's own "index" rather than assuming the
		// provider preserved input order.
		$by_index = array();
		foreach ( $result['data'] as $item ) {
			if ( isset( $item['index'], $item['embedding'] ) && is_array( $item['embedding'] ) ) {
				$by_index[ (int) $item['index'] ] = $item['embedding'];
			}
		}

		$vectors = array();
		foreach ( array_keys( $texts ) as $i ) {
			$vectors[ $i ] = isset( $by_index[ $i ] )
				? $by_index[ $i ]
				: new WP_Error( 'pandabot_embeddings_missing', __( 'No embedding returned for this chunk.', 'pandabot' ) );
		}

		return $vectors;
	}

	public static function pack_vector( array $vector ) {
		return pack( 'g*', ...$vector );
	}

	public static function unpack_vector( $blob ) {
		if ( empty( $blob ) ) {
			return array();
		}
		$unpacked = unpack( 'g*', $blob );
		return $unpacked ? array_values( $unpacked ) : array();
	}

	public static function cosine_similarity( array $a, array $b ) {
		$len = min( count( $a ), count( $b ) );
		if ( 0 === $len ) {
			return 0.0;
		}

		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$dot   += $a[ $i ] * $b[ $i ];
			$mag_a += $a[ $i ] * $a[ $i ];
			$mag_b += $b[ $i ] * $b[ $i ];
		}

		if ( 0.0 === $mag_a || 0.0 === $mag_b ) {
			return 0.0;
		}

		return $dot / ( sqrt( $mag_a ) * sqrt( $mag_b ) );
	}

	/**
	 * Brute-force cosine top-k over every stored vector. Fine at this
	 * site's scale — tens of pages, low hundreds of chunks (plan §4).
	 *
	 * @param float[] $query_vector
	 * @return array Rows from pandabot_embeddings (embedding blob dropped,
	 *         'similarity' added), sorted desc, top $top_k, filtered to
	 *         similarity >= $floor.
	 */
	public static function search( array $query_vector, $top_k = 5, $floor = 0.0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pandabot_embeddings';

		$rows = $wpdb->get_results(
			"SELECT id, source_type, source_id, source_url, title, chunk_index, content, embedding, lang FROM {$table}",
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array();
		}

		$query_dim = count( $query_vector );
		$scored    = array();

		foreach ( $rows as $row ) {
			$vector = self::unpack_vector( $row['embedding'] );
			if ( empty( $vector ) ) {
				continue;
			}
			$sim = self::cosine_similarity( $query_vector, $vector );
			if ( $sim < $floor ) {
				continue;
			}
			unset( $row['embedding'] );
			$row['similarity']   = $sim;
			$row['vector_dim']   = count( $vector );
			// A stored vector from a different embedding model/dimension
			// than the current query silently truncates in
			// cosine_similarity() and produces a misleading score — flag
			// it explicitly rather than let it masquerade as "just low".
			$row['dim_mismatch'] = ( count( $vector ) !== $query_dim );
			$scored[]             = $row;
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		return array_slice( $scored, 0, max( 0, (int) $top_k ) );
	}
}
