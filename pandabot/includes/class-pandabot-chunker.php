<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP post content -> clean text -> paragraph-aligned, overlapping chunks.
 * No HTML crawling here — the plugin indexes straight from post_content
 * (plan §14: "the plugin does its own indexing from the WordPress DB"),
 * so there's no theme nav/footer/cookie-banner chrome to strip; this only
 * has to clean up blocks/shortcodes/tags within the content itself.
 */
class Pandabot_Chunker {

	const TARGET_TOKENS   = 650; // midpoint of the plan's 500-800 token target.
	const OVERLAP_RATIO   = 0.15;
	const CHARS_PER_TOKEN = 3; // conservative Hebrew estimate (plan §4).

	public static function estimate_tokens( $text ) {
		return (int) ceil( mb_strlen( (string) $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Render blocks/shortcodes, strip tags, decode entities, collapse
	 * whitespace while preserving paragraph breaks.
	 */
	public static function clean_html( $html ) {
		$html = is_string( $html ) ? $html : '';

		if ( function_exists( 'do_blocks' ) ) {
			$html = do_blocks( $html );
		}
		$html = strip_shortcodes( $html );

		// Turn block-level closing tags into paragraph breaks before
		// stripping tags, so unrelated paragraphs don't get fused together.
		$html = preg_replace( '/<\/(p|div|h[1-6]|li|blockquote|tr)>/i', "$0\n\n", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Split cleaned text into overlapping, paragraph-aligned chunks of
	 * roughly TARGET_TOKENS tokens each. Never splits mid-sentence: a
	 * paragraph too long to fit is broken on sentence boundaries instead.
	 *
	 * @return string[] chunk texts, in order.
	 */
	public static function chunk_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$target_chars  = self::TARGET_TOKENS * self::CHARS_PER_TOKEN;
		$overlap_chars = (int) round( $target_chars * self::OVERLAP_RATIO );

		$paragraphs = preg_split( '/\n{2,}/u', $text );
		$paragraphs = array_values(
			array_filter(
				array_map( 'trim', $paragraphs ),
				function ( $p ) {
					return '' !== $p;
				}
			)
		);

		if ( empty( $paragraphs ) ) {
			return array();
		}

		$chunks  = array();
		$current = '';

		foreach ( $paragraphs as $para ) {
			$pieces = ( mb_strlen( $para ) > $target_chars * 1.4 )
				? self::split_sentences( $para )
				: array( $para );

			foreach ( $pieces as $piece ) {
				$candidate = ( '' === $current ) ? $piece : $current . "\n\n" . $piece;

				if ( mb_strlen( $candidate ) > $target_chars && '' !== $current ) {
					$chunks[] = $current;
					$tail     = self::tail_chars( $current, $overlap_chars );
					$current  = ( '' === $tail ) ? $piece : $tail . "\n\n" . $piece;
				} else {
					$current = $candidate;
				}
			}
		}

		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Sentence-boundary split (punctuation + whitespace). Deliberately
	 * simple and script-agnostic so it works the same for Hebrew text and
	 * Latin acronyms mixed in one line (PANS/PANDAS, OCD, etc.).
	 */
	private static function split_sentences( $paragraph ) {
		$parts = preg_split( '/(?<=[.!?])\s+/u', $paragraph );
		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				function ( $p ) {
					return '' !== $p;
				}
			)
		);
	}

	private static function tail_chars( $text, $chars ) {
		if ( $chars <= 0 ) {
			return '';
		}
		$len = mb_strlen( $text );
		if ( $len <= $chars ) {
			return $text;
		}
		return mb_substr( $text, $len - $chars, $chars );
	}
}
