<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre- and post-checks around the model call (plan §6). Deterministic and
 * cheap by design — a safety net around the system prompt, not the main
 * mechanism. Every action here is logged to pandabot_messages.guardrail_action.
 */
class Pandabot_Guardrails {

	/**
	 * Runs BEFORE any provider call.
	 *
	 * @return array{answer:string, guardrail_action:string}|null Null = passed.
	 */
	public function pre_check( $message, array $settings ) {
		$len_cap = max( 1, (int) $settings['input_char_cap'] );
		if ( mb_strlen( $message ) > $len_cap ) {
			return array(
				'answer'           => $settings['input_too_long_message'],
				'guardrail_action' => 'input_too_long',
			);
		}

		$keyword = $this->match_keyword( $message, (array) $settings['guardrail_keywords'] );
		if ( null !== $keyword ) {
			return array(
				'answer'           => $settings['medical_redirect_message'],
				'guardrail_action' => 'blocked_medical',
			);
		}

		return null;
	}

	/**
	 * Runs AFTER the provider call, before the answer is shown/logged as
	 * final.
	 *
	 * @param string $answer        The model's raw answer.
	 * @param array  $context_chunks Chunks that were actually used as context.
	 * @param array  $settings
	 * @return array{answer:string|null, guardrail_action:string}|null
	 *         Null = answer stands as-is (guardrail_action 'none'). A
	 *         non-null 'answer' replaces the model's answer; a null
	 *         'answer' with 'fallback_no_context' tells the caller to
	 *         substitute the configured fallback_message.
	 */
	public function post_check( $answer, array $context_chunks, array $settings ) {
		if ( empty( $context_chunks ) ) {
			return array(
				'answer'           => null,
				'guardrail_action' => 'fallback_no_context',
			);
		}

		if ( $this->looks_medical( $answer ) ) {
			return array(
				'answer'           => $settings['medical_redirect_message'],
				'guardrail_action' => 'blocked_medical',
			);
		}

		if ( $this->looks_offtopic_refusal( $answer ) ) {
			// Tag-only: the model already declined on its own (system
			// prompt instructs it to) — keep its answer, just label it for
			// the analytics guardrail breakdown (plan §8).
			return array(
				'answer'           => $answer,
				'guardrail_action' => 'refused_offtopic',
			);
		}

		return null;
	}

	private function match_keyword( $message, array $keywords ) {
		foreach ( $keywords as $kw ) {
			$kw = trim( (string) $kw );
			if ( '' === $kw ) {
				continue;
			}
			if ( false !== mb_stripos( $message, $kw ) ) {
				return $kw;
			}
		}
		return null;
	}

	/**
	 * Conservative heuristic for the model slipping into dosage/
	 * medication-change advice despite instructions (plan §6: "heuristic/
	 * regex is fine; don't over-engineer").
	 */
	private function looks_medical( $answer ) {
		$patterns = array(
			'/\bמ"ג\b/u',
			'/מיליגרם/u',
			'/\bmg\b/i',
			'/כפית(?:ים)? ביום/u',
			'/מנה (?:יומית|של)/u',
			'/תפסיק(?:י|ו)? (?:לקחת|את ה)/u',
			'/תתחיל(?:י|ו)? לקחת/u',
			'/תחליפ(?:י|ו)? את ה(?:תרופה|מינון)/u',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $answer ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Heuristic tag (not enforcement) for an off-topic decline, so the
	 * dashboard can count how often the model steers a conversation back
	 * on-topic on its own.
	 */
	private function looks_offtopic_refusal( $answer ) {
		$patterns = array(
			'/לא (?:יכולה|יכול) לענות על/u',
			'/לא קשור ל(?:קליניקה|תחום)/u',
			'/אינ(?:ה|ו) קשור(?:ה)? ל(?:קליניקה|טיפול)/u',
			'/מעבר לתחום (?:הידע|העיסוק) שלי/u',
			'/אני כאן (?:רק )?כדי לעזור בנושאי/u',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $answer ) ) {
				return true;
			}
		}
		return false;
	}
}
