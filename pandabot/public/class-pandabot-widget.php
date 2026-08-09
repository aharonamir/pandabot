<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end widget: enqueues the (local-only) CSS/JS and renders the whole
 * launcher/panel tree server-side into wp_footer.
 *
 * Two deliberate choices, both per plan §5/§9:
 *  - No external assets. The mockup pulled Google Fonts + Tabler Icons from
 *    CDNs; every icon here is inline SVG and the font is inherited from the
 *    theme, so the widget adds zero third-party requests.
 *  - No inline <script>. Config reaches the JS through data-* attributes on
 *    #pandabot-root and through <template> elements, so a strict CSP that
 *    blocks inline scripts can't silently break the widget.
 */
class Pandabot_Widget {

	const NONCE_ACTION = 'pandabot_public_chat';

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * The widget is pointless (and would only ever show its error state)
	 * without a chat provider, so a half-configured site gets nothing rather
	 * than a bot that fails on every message.
	 */
	private function should_render() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		$chat = Pandabot_Settings::get( 'chat_provider' );

		return ! empty( $chat['base_url'] ) && ! empty( $chat['api_key'] ) && ! empty( $chat['model'] );
	}

	public function enqueue_assets() {
		if ( ! $this->should_render() ) {
			return;
		}

		wp_enqueue_style(
			'pandabot-widget',
			PANDABOT_PLUGIN_URL . 'public/css/pandabot.css',
			array(),
			PANDABOT_VERSION
		);

		wp_enqueue_script(
			'pandabot-widget',
			PANDABOT_PLUGIN_URL . 'public/js/pandabot.js',
			array(),
			PANDABOT_VERSION,
			true
		);
	}

	public function render() {
		if ( ! $this->should_render() ) {
			return;
		}

		$settings = Pandabot_Settings::get_all();
		$ui       = $settings['appearance'];
		$contact  = $settings['contact'];

		$style = sprintf(
			'--pandabot-accent:%1$s;--pandabot-radius:%2$dpx;--pandabot-offset-bottom:%3$dpx;--pandabot-offset-side:%4$dpx;--pandabot-mobile-gap:%5$dpx;',
			esc_attr( $ui['accent_color'] ),
			(int) $ui['radius'],
			(int) $ui['offset_bottom'],
			(int) $ui['offset_side'],
			(int) $ui['mobile_gap']
		);
		?>
		<div id="pandabot-root"
			class="pandabot pandabot--<?php echo esc_attr( $ui['position'] ); ?>"
			dir="rtl"
			lang="he"
			style="<?php echo esc_attr( $style ); ?>"
			data-rest-url="<?php echo esc_url( rest_url( 'pandabot/v1/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
			data-error-text="<?php echo esc_attr( $settings['generic_error_message'] ); ?>"
			data-auto-open="<?php echo esc_attr( (int) $ui['auto_open_delay'] ); ?>">

			<div class="pandabot-launcher" data-pandabot="launcher">
				<button type="button" class="pandabot-launcher-btn" data-pandabot="open" aria-expanded="false" aria-controls="pandabot-panel" aria-label="<?php esc_attr_e( 'פתיחת צ\'אט', 'pandabot' ); ?>">
					<?php echo self::icon( $ui['launcher_icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
					<span class="pandabot-badge" aria-hidden="true">1</span>
				</button>
				<?php if ( '' !== trim( $ui['teaser_text'] ) ) : ?>
					<div class="pandabot-teaser" data-pandabot="teaser">
						<button type="button" class="pandabot-teaser-text" data-pandabot="open"><?php echo esc_html( $ui['teaser_text'] ); ?></button>
						<button type="button" class="pandabot-teaser-close" data-pandabot="dismiss-teaser" aria-label="<?php esc_attr_e( 'הסתרת ההודעה', 'pandabot' ); ?>">
							<?php echo self::icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<div class="pandabot-panel" id="pandabot-panel" role="dialog" aria-label="<?php echo esc_attr( $ui['header_title'] ); ?>" hidden>
				<div class="pandabot-head">
					<div class="pandabot-avatar">
						<?php if ( ! empty( $ui['avatar'] ) ) : ?>
							<img src="<?php echo esc_url( $ui['avatar'] ); ?>" alt="" width="40" height="40">
						<?php else : ?>
							<?php echo self::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
						<?php endif; ?>
					</div>
					<div class="pandabot-head-text">
						<b><?php echo esc_html( $ui['header_title'] ); ?></b>
						<span><?php echo esc_html( $ui['header_status'] ); ?></span>
					</div>
					<button type="button" class="pandabot-close" data-pandabot="close" aria-label="<?php esc_attr_e( 'סגירת הצ\'אט', 'pandabot' ); ?>">
						<?php echo self::icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
					</button>
				</div>

				<div class="pandabot-body" data-pandabot="body" role="log" aria-live="polite" aria-atomic="false">
					<div class="pandabot-msg pandabot-msg--bot pandabot-msg--greet">
						<div class="pandabot-bubble"><?php echo esc_html( $ui['greeting'] ); ?></div>
					</div>
					<?php if ( ! empty( $settings['suggested_prompts'] ) ) : ?>
						<div class="pandabot-chips" data-pandabot="chips">
							<?php foreach ( $settings['suggested_prompts'] as $prompt ) : ?>
								<button type="button" class="pandabot-chip"><?php echo esc_html( $prompt ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<p class="pandabot-consent">
					<?php echo esc_html( $settings['consent_text'] ); ?>
					<?php if ( ! empty( $contact['privacy_url'] ) ) : ?>
						· <a href="<?php echo esc_url( $contact['privacy_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'מדיניות פרטיות', 'pandabot' ); ?></a>
					<?php endif; ?>
				</p>

				<form class="pandabot-foot" data-pandabot="form">
					<input type="text"
						class="pandabot-input"
						data-pandabot="input"
						autocomplete="off"
						maxlength="<?php echo esc_attr( (int) $settings['input_char_cap'] ); ?>"
						placeholder="<?php esc_attr_e( 'כתבו הודעה…', 'pandabot' ); ?>"
						aria-label="<?php esc_attr_e( 'הקלדת הודעה', 'pandabot' ); ?>">
					<button type="submit" class="pandabot-send" aria-label="<?php esc_attr_e( 'שליחה', 'pandabot' ); ?>">
						<?php echo self::icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
					</button>
				</form>
			</div>

			<template data-pandabot="tpl-typing">
				<div class="pandabot-typing" aria-label="<?php esc_attr_e( 'מקלידה…', 'pandabot' ); ?>"><span></span><span></span><span></span></div>
			</template>

			<template data-pandabot="tpl-guard">
				<div class="pandabot-msg pandabot-msg--bot pandabot-msg--guard">
					<div class="pandabot-bubble">
						<?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
						<div data-pandabot="text"></div>
					</div>
				</div>
			</template>

			<template data-pandabot="tpl-cta">
				<div class="pandabot-cta">
					<a class="pandabot-cta-primary" href="<?php echo esc_url( $contact['booking_url'] ); ?>" data-pandabot-event="cta_click_booking">
						<?php echo self::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
						<?php esc_html_e( 'לתיאום שיחת היכרות', 'pandabot' ); ?>
					</a>
					<div class="pandabot-cta-row">
						<?php if ( ! empty( $contact['phone'] ) ) : ?>
							<a class="pandabot-cta-sec" href="tel:<?php echo esc_attr( Pandabot_Settings::tel_digits( $contact['phone'] ) ); ?>" data-pandabot-event="cta_click_phone">
								<?php echo self::icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
								<?php esc_html_e( 'התקשרו', 'pandabot' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $contact['whatsapp'] ) ) : ?>
							<a class="pandabot-cta-sec" href="https://wa.me/<?php echo esc_attr( Pandabot_Settings::tel_digits( $contact['whatsapp'] ) ); ?>" target="_blank" rel="noopener" data-pandabot-event="cta_click_whatsapp">
								<?php echo self::icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
								<?php esc_html_e( 'וואטסאפ', 'pandabot' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * Inline SVG so the widget never reaches for an icon CDN. All icons share
	 * a 24px stroke grid and inherit currentColor.
	 */
	private static function icon( $name ) {
		$paths = array(
			'chat'     => '<path d="M8 9h8M8 13h5"/><path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3h-5l-5 3v-3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z"/>',
			'help'     => '<circle cx="12" cy="12" r="9"/><path d="M12 17v.01"/><path d="M12 13.5a1.5 1.5 0 0 1 1-1.5 2.6 2.6 0 1 0-3-4"/>',
			'leaf'     => '<path d="M5 21c.5-4.5 2.5-8 7-10"/><path d="M9 18c6.2 0 10.5-3.3 11-12V4h-4c-9 0-12 4-12 9 0 1 0 3 2 5h3z"/>',
			'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
			'send'     => '<path d="M10 14 21 3"/><path d="M21 3l-6.5 18a.55.55 0 0 1-1 0l-3.5-7-7-3.5a.55.55 0 0 1 0-1L21 3"/>',
			'shield'   => '<path d="M11.5 20.8A12 12 0 0 1 3.5 6.4a12 12 0 0 0 8.5-3.4 12 12 0 0 0 8.5 3.4 12 12 0 0 1-.1 1.9"/><path d="m15 19 2 2 4-4"/>',
			'calendar' => '<path d="M12.5 21H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6"/><path d="M16 3v4M8 3v4M4 11h16"/><path d="M16 19h6M19 16v6"/>',
			'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
			'whatsapp' => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			$name = 'chat';
		}

		return '<svg class="pandabot-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
