<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin OpenAI-compatible HTTP client. Provider-agnostic on purpose — no
 * DeepSeek/OpenRouter-specific assumptions, since the base URL/key/model
 * are all settings (plan §4/§11). One instance = one configured provider
 * (chat and embeddings each get their own instance from their own config).
 */
class Pandabot_Provider {

	private $base_url;
	private $api_key;
	private $model;
	private $timeout = 30;

	public function __construct( $base_url, $api_key, $model ) {
		$this->base_url = untrailingslashit( trim( (string) $base_url ) );
		$this->api_key  = (string) $api_key;
		$this->model    = (string) $model;
	}

	public function chat_completion( array $messages, array $args = array() ) {
		$body = array_merge(
			array(
				'model'       => $this->model,
				'messages'    => $messages,
				'max_tokens'  => 400,
				'temperature' => 0.3,
			),
			$args
		);
		return $this->post( '/chat/completions', $body );
	}

	/**
	 * @param string|array $input Single string or array of strings.
	 */
	public function embeddings( $input, array $args = array() ) {
		$body = array_merge(
			array(
				'model' => $this->model,
				'input' => $input,
			),
			$args
		);
		return $this->post( '/embeddings', $body );
	}

	/**
	 * @return array|WP_Error Decoded JSON body on 2xx. On failure, a WP_Error
	 *         whose message is safe to show an authenticated admin (used by
	 *         the test-connection tool) — callers on the PUBLIC chat path
	 *         must NOT forward get_error_message() to the browser; log it
	 *         and show a generic fallback instead (plan §11 security must).
	 */
	private function post( $path, array $body ) {
		if ( empty( $this->base_url ) ) {
			return new WP_Error( 'pandabot_provider_not_configured', __( 'Provider base URL is not configured.', 'pandabot' ) );
		}

		$url  = $this->base_url . $path;
		$args = array(
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_post( $url, $args );

		// Retry once on a transient transport failure or a 5xx — shared
		// hosting networking hiccups and provider-side blips are common
		// enough to be worth one retry before giving up (plan §11).
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 500 ) {
			$response = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$this->log( 'transport error: ' . $response->get_error_message() );
			return new WP_Error( 'pandabot_provider_unreachable', __( 'Could not reach the provider.', 'pandabot' ) );
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( is_array( $decoded ) && isset( $decoded['error']['message'] ) )
				? (string) $decoded['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Provider returned HTTP %d.', 'pandabot' ),
					$code
				);

			$this->log( sprintf( 'HTTP %d from %s: %s', $code, $url, substr( $raw_body, 0, 1000 ) ) );

			return new WP_Error(
				'pandabot_provider_error',
				$message,
				array(
					'status' => $code,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			$this->log( 'non-JSON response from ' . $url . ': ' . substr( $raw_body, 0, 500 ) );
			return new WP_Error( 'pandabot_provider_bad_response', __( 'Provider returned an unexpected response.', 'pandabot' ) );
		}

		return $decoded;
	}

	/**
	 * Extract usage tokens if the provider included them; tolerate ones
	 * that omit the field entirely.
	 */
	public static function usage_from( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded['usage'] ) || ! is_array( $decoded['usage'] ) ) {
			return array(
				'prompt_tokens'     => null,
				'completion_tokens' => null,
			);
		}
		$usage = $decoded['usage'];
		return array(
			'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null,
			'completion_tokens' => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null,
		);
	}

	/**
	 * Server-side only log line — never includes the API key, never
	 * returned to any HTTP response body.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PandaBot] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
