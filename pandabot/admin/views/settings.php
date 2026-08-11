<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings   = Pandabot_Settings::get_all();
$chat       = $settings['chat_provider'];
$emb        = $settings['embeddings_provider'];
$post_types = get_post_types( array( 'public' => true ), 'objects' );
unset( $post_types['attachment'] );

/**
 * Fields backed by a wp-config.php constant are shown locked: the constant
 * always wins at read time, so an editable box here would just be a lie.
 * A disabled input posts nothing, which is also what stops the save path
 * from copying the constant's value into the database.
 */
if ( ! function_exists( 'pandabot_lock_note' ) ) {
	function pandabot_lock_note( $group, $key ) {
		$constant = Pandabot_Settings::overridden_by( $group, $key );
		if ( ! $constant ) {
			return '';
		}
		return '<p class="description pandabot-locked">' . sprintf(
			/* translators: %s: PHP constant name */
			esc_html__( 'Set in wp-config.php as %s — change it there, not here.', 'pandabot' ),
			'<code>' . esc_html( $constant ) . '</code>'
		) . '</p>';
	}
}

$privacy_data = Pandabot_Privacy::data_summary();
$date_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice routing.
$notice   = isset( $_GET['pandabot_notice'] ) ? sanitize_key( wp_unslash( $_GET['pandabot_notice'] ) ) : '';
$notice_n = isset( $_GET['pandabot_n'] ) ? (int) $_GET['pandabot_n'] : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap pandabot-wrap">
	<h1><?php esc_html_e( 'PandaBot — Settings', 'pandabot' ); ?></h1>

	<?php if ( isset( $_GET['pandabot_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'pandabot' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'salt' === $notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'IP hashing salt regenerated. Existing stored hashes can no longer be matched to any visitor, and per-IP rate-limit counters have been reset.', 'pandabot' ); ?></p>
		</div>
	<?php elseif ( 'purged' === $notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: number of conversations deleted */
					esc_html__( 'Retention cleanup finished. %s conversations were past the retention window and have been deleted.', 'pandabot' ),
					esc_html( number_format_i18n( $notice_n ) )
				);
				?>
			</p>
		</div>
	<?php elseif ( 'wiped' === $notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: number of conversations deleted */
					esc_html__( 'All chat logs deleted (%s conversations). Indexed site content was left untouched.', 'pandabot' ),
					esc_html( number_format_i18n( $notice_n ) )
				);
				?>
			</p>
		</div>
	<?php elseif ( 'rl_reset' === $notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Rate-limit counters cleared. Everyone currently blocked can ask again immediately.', 'pandabot' ); ?></p>
		</div>
	<?php elseif ( 'unconfirmed' === $notice ) : ?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'Nothing was deleted — you need to tick the confirmation box first.', 'pandabot' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="pandabot-card pandabot-app" id="pandabot-settings-app"
		data-rest-url="<?php echo esc_url( rest_url( 'pandabot/v1/' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
		<p class="description">
			<?php esc_html_e( 'Chat and embeddings are two independently configured OpenAI-compatible providers — some DeepSeek/OpenRouter-style endpoints only support one of the two. API keys are never displayed here and never sent to the browser; leave the key field blank to keep the currently saved key.', 'pandabot' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pandabot_save_settings' ); ?>
			<input type="hidden" name="action" value="pandabot_save_settings">

			<h2><?php esc_html_e( 'Chat provider', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="chat_base_url"><?php esc_html_e( 'Base URL', 'pandabot' ); ?></label></th>
					<td>
						<input type="url" id="chat_base_url" class="regular-text" placeholder="https://api.example.com/v1"
							name="pandabot_settings[chat_provider][base_url]" value="<?php echo esc_attr( $chat['base_url'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'chat_provider', 'base_url' ) ); ?>>
						<?php echo pandabot_lock_note( 'chat_provider', 'base_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th><label for="chat_model"><?php esc_html_e( 'Model', 'pandabot' ); ?></label></th>
					<td>
						<input type="text" id="chat_model" class="regular-text"
							name="pandabot_settings[chat_provider][model]" value="<?php echo esc_attr( $chat['model'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'chat_provider', 'model' ) ); ?>>
						<?php echo pandabot_lock_note( 'chat_provider', 'model' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th><label for="chat_api_key"><?php esc_html_e( 'API key', 'pandabot' ); ?></label></th>
					<td>
						<?php $chat_key_locked = (bool) Pandabot_Settings::overridden_by( 'chat_provider', 'api_key' ); ?>
						<input type="password" id="chat_api_key" class="regular-text" autocomplete="new-password"
							name="pandabot_settings[chat_provider][api_key]" value=""
							<?php disabled( $chat_key_locked ); ?>
							placeholder="<?php echo $chat_key_locked ? esc_attr__( 'מוגדר ב-wp-config.php', 'pandabot' ) : ( $chat['api_key'] ? esc_attr__( '•••••••• (מפתח קיים — השאירו ריק כדי לשמור)', 'pandabot' ) : esc_attr__( 'לא הוגדר', 'pandabot' ) ); ?>">
						<?php echo pandabot_lock_note( 'chat_provider', 'api_key' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" class="button pandabot-test-btn" data-provider="chat"><?php esc_html_e( 'בדיקת חיבור', 'pandabot' ); ?></button>
						<span class="pandabot-test-result" data-provider="chat"></span>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Embeddings provider', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="emb_base_url"><?php esc_html_e( 'Base URL', 'pandabot' ); ?></label></th>
					<td>
						<input type="url" id="emb_base_url" class="regular-text" placeholder="https://api.openai.com/v1"
							name="pandabot_settings[embeddings_provider][base_url]" value="<?php echo esc_attr( $emb['base_url'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'embeddings_provider', 'base_url' ) ); ?>>
						<?php echo pandabot_lock_note( 'embeddings_provider', 'base_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th><label for="emb_model"><?php esc_html_e( 'Model', 'pandabot' ); ?></label></th>
					<td>
						<input type="text" id="emb_model" class="regular-text" placeholder="text-embedding-3-small"
							name="pandabot_settings[embeddings_provider][model]" value="<?php echo esc_attr( $emb['model'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'embeddings_provider', 'model' ) ); ?>>
						<?php echo pandabot_lock_note( 'embeddings_provider', 'model' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th><label for="emb_api_key"><?php esc_html_e( 'API key', 'pandabot' ); ?></label></th>
					<td>
						<?php $emb_key_locked = (bool) Pandabot_Settings::overridden_by( 'embeddings_provider', 'api_key' ); ?>
						<input type="password" id="emb_api_key" class="regular-text" autocomplete="new-password"
							name="pandabot_settings[embeddings_provider][api_key]" value=""
							<?php disabled( $emb_key_locked ); ?>
							placeholder="<?php echo $emb_key_locked ? esc_attr__( 'מוגדר ב-wp-config.php', 'pandabot' ) : ( $emb['api_key'] ? esc_attr__( '•••••••• (מפתח קיים — השאירו ריק כדי לשמור)', 'pandabot' ) : esc_attr__( 'לא הוגדר', 'pandabot' ) ); ?>">
						<?php echo pandabot_lock_note( 'embeddings_provider', 'api_key' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
					</td>
				</tr>
				<tr>
					<th><label for="emb_dimension"><?php esc_html_e( 'Vector dimension', 'pandabot' ); ?></label></th>
					<td>
						<input type="number" id="emb_dimension" class="small-text" min="0"
							name="pandabot_settings[embeddings_provider][dimension]" value="<?php echo esc_attr( $emb['dimension'] ); ?>">
						<p class="description"><?php esc_html_e( 'Auto-filled by "Test connection". Changing embedding models later will require a full reindex.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" class="button pandabot-test-btn" data-provider="embeddings"><?php esc_html_e( 'בדיקת חיבור', 'pandabot' ); ?></button>
						<span class="pandabot-test-result" data-provider="embeddings"></span>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Content scope', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Post types to index', 'pandabot' ); ?></th>
					<td>
						<input type="hidden" name="pandabot_settings[indexed_post_types][]" value="">
						<?php foreach ( $post_types as $pt ) : ?>
							<label style="display:inline-block; margin-inline-end:14px">
								<input type="checkbox" name="pandabot_settings[indexed_post_types][]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, (array) $settings['indexed_post_types'], true ) ); ?>>
								<?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><label for="excluded_ids"><?php esc_html_e( 'Excluded post/page IDs', 'pandabot' ); ?></label></th>
					<td>
						<input type="text" id="excluded_ids" class="regular-text" placeholder="12, 45, 88"
							name="pandabot_settings[excluded_ids]" value="<?php echo esc_attr( implode( ', ', (array) $settings['excluded_ids'] ) ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated post/page IDs to skip when indexing (e.g. a legal page you never want the bot quoting from). Takes effect on next save/reindex.', 'pandabot' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Guardrails', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="system_prompt"><?php esc_html_e( 'System prompt', 'pandabot' ); ?></label></th>
					<td>
						<textarea id="system_prompt" name="pandabot_settings[system_prompt]" rows="10" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['system_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The clinic\'s fixed contact facts are appended automatically at call time — no need to repeat them here.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="guardrail_keywords"><?php esc_html_e( 'Keyword watchlist', 'pandabot' ); ?></label></th>
					<td>
						<textarea id="guardrail_keywords" name="pandabot_settings[guardrail_keywords]" rows="6" class="large-text" dir="rtl"><?php echo esc_textarea( implode( "\n", (array) $settings['guardrail_keywords'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One phrase per line. A match short-circuits before the model is called at all (safety net, not the main mechanism — the system prompt does the real work).', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="medical_redirect_message"><?php esc_html_e( 'Medical redirect message', 'pandabot' ); ?></label></th>
					<td><textarea id="medical_redirect_message" name="pandabot_settings[medical_redirect_message]" rows="3" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['medical_redirect_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="fallback_message"><?php esc_html_e( 'No-context fallback message', 'pandabot' ); ?></label></th>
					<td><textarea id="fallback_message" name="pandabot_settings[fallback_message]" rows="3" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['fallback_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="input_too_long_message"><?php esc_html_e( 'Input-too-long message', 'pandabot' ); ?></label></th>
					<td><textarea id="input_too_long_message" name="pandabot_settings[input_too_long_message]" rows="2" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['input_too_long_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="generic_error_message"><?php esc_html_e( 'Generic error message (provider/connection failure)', 'pandabot' ); ?></label></th>
					<td><textarea id="generic_error_message" name="pandabot_settings[generic_error_message]" rows="2" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['generic_error_message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown to site visitors on any internal/provider error — the real error detail is never exposed publicly, only logged server-side.', 'pandabot' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Rate limiting', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="rl_ip_min"><?php esc_html_e( 'Per IP / minute', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_ip_min" min="0" class="small-text" name="pandabot_settings[rate_limit_per_ip_minute]" value="<?php echo esc_attr( $settings['rate_limit_per_ip_minute'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_ip_hr"><?php esc_html_e( 'Per IP / hour', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_ip_hr" min="0" class="small-text" name="pandabot_settings[rate_limit_per_ip_hour]" value="<?php echo esc_attr( $settings['rate_limit_per_ip_hour'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_session"><?php esc_html_e( 'Per session (soft cap)', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_session" min="0" class="small-text" name="pandabot_settings[rate_limit_per_session]" value="<?php echo esc_attr( $settings['rate_limit_per_session'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_global_day"><?php esc_html_e( 'Global / day', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_global_day" min="0" class="small-text" name="pandabot_settings[rate_limit_global_day]" value="<?php echo esc_attr( $settings['rate_limit_global_day'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_max_tokens"><?php esc_html_e( 'Max tokens per reply', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_max_tokens" min="50" class="small-text" name="pandabot_settings[max_tokens]" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_max_context"><?php esc_html_e( 'Max context characters', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_max_context" min="500" class="small-text" name="pandabot_settings[max_context_chars]" value="<?php echo esc_attr( $settings['max_context_chars'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rl_input_cap"><?php esc_html_e( 'Input character cap', 'pandabot' ); ?></label></th>
					<td><input type="number" id="rl_input_cap" min="50" class="small-text" name="pandabot_settings[input_char_cap]" value="<?php echo esc_attr( $settings['input_char_cap'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="rate_limit_message"><?php esc_html_e( 'Rate-limited message', 'pandabot' ); ?></label></th>
					<td><textarea id="rate_limit_message" name="pandabot_settings[rate_limit_message]" rows="2" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['rate_limit_message'] ); ?></textarea></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Widget appearance', 'pandabot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ap_accent"><?php esc_html_e( 'Accent color', 'pandabot' ); ?></label></th>
					<td>
						<input type="color" id="ap_accent" name="pandabot_settings[appearance][accent_color]" value="<?php echo esc_attr( $settings['appearance']['accent_color'] ); ?>">
						<p class="description"><?php esc_html_e( 'The whole widget palette (header, bubbles, buttons, chips) is derived from this one color. Set it to the theme\'s main color so the widget reads as part of the site.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_radius"><?php esc_html_e( 'Panel corner radius (px)', 'pandabot' ); ?></label></th>
					<td><input type="number" id="ap_radius" min="0" max="40" class="small-text" name="pandabot_settings[appearance][radius]" value="<?php echo esc_attr( $settings['appearance']['radius'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ap_position"><?php esc_html_e( 'Screen corner', 'pandabot' ); ?></label></th>
					<td>
						<select id="ap_position" name="pandabot_settings[appearance][position]">
							<option value="left" <?php selected( $settings['appearance']['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left (natural for RTL)', 'pandabot' ); ?></option>
							<option value="right" <?php selected( $settings['appearance']['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'pandabot' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="ap_icon"><?php esc_html_e( 'Launcher icon', 'pandabot' ); ?></label></th>
					<td>
						<select id="ap_icon" name="pandabot_settings[appearance][launcher_icon]">
							<option value="chat" <?php selected( $settings['appearance']['launcher_icon'], 'chat' ); ?>><?php esc_html_e( 'Chat bubble', 'pandabot' ); ?></option>
							<option value="help" <?php selected( $settings['appearance']['launcher_icon'], 'help' ); ?>><?php esc_html_e( 'Question mark', 'pandabot' ); ?></option>
							<option value="leaf" <?php selected( $settings['appearance']['launcher_icon'], 'leaf' ); ?>><?php esc_html_e( 'Leaf', 'pandabot' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="ap_offset_bottom"><?php esc_html_e( 'Distance from bottom (px)', 'pandabot' ); ?></label></th>
					<td>
						<input type="number" id="ap_offset_bottom" min="0" max="400" class="small-text" name="pandabot_settings[appearance][offset_bottom]" value="<?php echo esc_attr( $settings['appearance']['offset_bottom'] ); ?>">
						<p class="description"><?php esc_html_e( 'Raise the launcher above other floating buttons in the same corner (back-to-top, accessibility toolbar, cookie banner). This theme\'s back-to-top button occupies the bottom-left corner up to about 80px, so ~96 clears it.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_offset_side"><?php esc_html_e( 'Distance from side (px)', 'pandabot' ); ?></label></th>
					<td><input type="number" id="ap_offset_side" min="0" max="400" class="small-text" name="pandabot_settings[appearance][offset_side]" value="<?php echo esc_attr( $settings['appearance']['offset_side'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ap_mobile_gap"><?php esc_html_e( 'Extra space under input on mobile (px)', 'pandabot' ); ?></label></th>
					<td>
						<input type="number" id="ap_mobile_gap" min="0" max="400" class="small-text" name="pandabot_settings[appearance][mobile_gap]" value="<?php echo esc_attr( $settings['appearance']['mobile_gap'] ); ?>">
						<p class="description"><?php esc_html_e( 'On phones the chat panel fills the screen, so a floating accessibility or chat button can land on top of the send button. Increase this to push the input row clear of it — around 60 is usually enough.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_avatar"><?php esc_html_e( 'Header avatar image URL', 'pandabot' ); ?></label></th>
					<td>
						<input type="url" id="ap_avatar" class="regular-text" name="pandabot_settings[appearance][avatar]" value="<?php echo esc_attr( $settings['appearance']['avatar'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. Leave empty to use the built-in icon. Must be hosted on this site — the widget loads no third-party assets.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_title"><?php esc_html_e( 'Header title', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ap_title" class="regular-text" dir="rtl" name="pandabot_settings[appearance][header_title]" value="<?php echo esc_attr( $settings['appearance']['header_title'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ap_status"><?php esc_html_e( 'Header status line', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ap_status" class="regular-text" dir="rtl" name="pandabot_settings[appearance][header_status]" value="<?php echo esc_attr( $settings['appearance']['header_status'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ap_teaser"><?php esc_html_e( 'Teaser bubble text', 'pandabot' ); ?></label></th>
					<td>
						<input type="text" id="ap_teaser" class="regular-text" dir="rtl" name="pandabot_settings[appearance][teaser_text]" value="<?php echo esc_attr( $settings['appearance']['teaser_text'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown next to the launcher. Leave empty to show only the button.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_greeting"><?php esc_html_e( 'Greeting', 'pandabot' ); ?></label></th>
					<td><textarea id="ap_greeting" name="pandabot_settings[appearance][greeting]" rows="3" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['appearance']['greeting'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="suggested_prompts"><?php esc_html_e( 'Suggested prompts', 'pandabot' ); ?></label></th>
					<td>
						<textarea id="suggested_prompts" name="pandabot_settings[suggested_prompts]" rows="5" class="large-text" dir="rtl"><?php echo esc_textarea( implode( "\n", (array) $settings['suggested_prompts'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, up to 6. Shown as chips under the greeting until the visitor sends their first message.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ap_auto_open"><?php esc_html_e( 'Auto-open after (seconds)', 'pandabot' ); ?></label></th>
					<td>
						<input type="number" id="ap_auto_open" min="0" max="120" class="small-text" name="pandabot_settings[appearance][auto_open_delay]" value="<?php echo esc_attr( $settings['appearance']['auto_open_delay'] ); ?>">
						<p class="description"><?php esc_html_e( '0 keeps the panel closed until the visitor clicks — the recommended setting.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="consent_text"><?php esc_html_e( 'Consent line', 'pandabot' ); ?></label></th>
					<td>
						<textarea id="consent_text" name="pandabot_settings[consent_text]" rows="2" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['consent_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Always visible above the input. The privacy-policy link below is appended automatically when a URL is set, so don\'t repeat it here.', 'pandabot' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Contact & CTA targets', 'pandabot' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These feed both the widget\'s action buttons and the fixed contact block appended to every system prompt — so what the bot says and what the button does can never drift apart.', 'pandabot' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ct_booking"><?php esc_html_e( 'Booking URL', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ct_booking" class="regular-text" name="pandabot_settings[contact][booking_url]" value="<?php echo esc_attr( $settings['contact']['booking_url'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'booking_url' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'booking_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?></td>
				</tr>
				<tr>
					<th><label for="ct_phone"><?php esc_html_e( 'Phone', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ct_phone" class="regular-text" name="pandabot_settings[contact][phone]" value="<?php echo esc_attr( $settings['contact']['phone'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'phone' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?></td>
				</tr>
				<tr>
					<th><label for="ct_whatsapp"><?php esc_html_e( 'WhatsApp number', 'pandabot' ); ?></label></th>
					<td>
						<input type="text" id="ct_whatsapp" class="regular-text" name="pandabot_settings[contact][whatsapp]" value="<?php echo esc_attr( $settings['contact']['whatsapp'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'whatsapp' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?>
						<p class="description"><?php esc_html_e( 'International format without + or dashes, e.g. 972546657207. Leave empty to hide the WhatsApp button.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ct_email"><?php esc_html_e( 'Email', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ct_email" class="regular-text" name="pandabot_settings[contact][email]" value="<?php echo esc_attr( $settings['contact']['email'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'email' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?></td>
				</tr>
				<tr>
					<th><label for="ct_address"><?php esc_html_e( 'Address', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ct_address" class="regular-text" dir="rtl" name="pandabot_settings[contact][address]" value="<?php echo esc_attr( $settings['contact']['address'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'address' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'address' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?></td>
				</tr>
				<tr>
					<th><label for="ct_privacy"><?php esc_html_e( 'Privacy policy URL', 'pandabot' ); ?></label></th>
					<td><input type="text" id="ct_privacy" class="regular-text" name="pandabot_settings[contact][privacy_url]" value="<?php echo esc_attr( $settings['contact']['privacy_url'] ); ?>" <?php disabled( (bool) Pandabot_Settings::overridden_by( 'contact', 'privacy_url' ) ); ?>>
						<?php echo pandabot_lock_note( 'contact', 'privacy_url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in the helper. ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Cost estimation', 'pandabot' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Your provider\'s prices per 1,000 tokens. Used only to turn logged token counts into the estimate on the dashboard — leave at 0 to hide the cost figure.', 'pandabot' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="cost_in"><?php esc_html_e( 'Chat input / 1K tokens', 'pandabot' ); ?></label></th>
					<td><input type="number" step="0.0001" min="0" id="cost_in" class="small-text" name="pandabot_settings[cost][chat_input_per_1k]" value="<?php echo esc_attr( $settings['cost']['chat_input_per_1k'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cost_out"><?php esc_html_e( 'Chat output / 1K tokens', 'pandabot' ); ?></label></th>
					<td><input type="number" step="0.0001" min="0" id="cost_out" class="small-text" name="pandabot_settings[cost][chat_output_per_1k]" value="<?php echo esc_attr( $settings['cost']['chat_output_per_1k'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cost_emb"><?php esc_html_e( 'Embeddings / 1K tokens', 'pandabot' ); ?></label></th>
					<td><input type="number" step="0.0001" min="0" id="cost_emb" class="small-text" name="pandabot_settings[cost][embeddings_per_1k]" value="<?php echo esc_attr( $settings['cost']['embeddings_per_1k'] ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy & data retention', 'pandabot' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Visitor IP addresses are never stored — only a salted SHA-256 hash, used solely for rate limiting. No names, emails or accounts are collected, so a chat log can only ever be linked back to a person by what the visitor chose to type into it.', 'pandabot' ); ?>
			</p>
			<input type="hidden" name="pandabot_settings[privacy_section]" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="retention_days"><?php esc_html_e( 'Delete chat logs after (days)', 'pandabot' ); ?></label></th>
					<td>
						<input type="number" id="retention_days" min="0" max="3650" class="small-text" name="pandabot_settings[retention_days]" value="<?php echo esc_attr( $settings['retention_days'] ); ?>">
						<p class="description"><?php esc_html_e( 'A daily job deletes conversations, their messages and their analytics events once they pass this age. Set to 0 to keep everything forever — legal only if your privacy policy says so.', 'pandabot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On plugin deletion', 'pandabot' ); ?></th>
					<td>
						<label for="delete_on_uninstall">
							<input type="checkbox" id="delete_on_uninstall" name="pandabot_settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
							<?php esc_html_e( 'Drop all PandaBot tables and settings when the plugin is deleted', 'pandabot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. While off, deleting the plugin leaves your chat logs and the embedded site index in the database, so reinstalling picks up exactly where you left off — no re-indexing cost. Turn it on only when you are decommissioning the bot for good.', 'pandabot' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'שמור הגדרות', 'pandabot' ) ); ?>
		</form>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Rate limiting', 'pandabot' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Counters are kept per visitor IP, in fixed windows that reset on the minute, the hour and the day. Clearing them unblocks everyone at once — useful while testing, since your own phone and computer share one home IP and a private browsing window does not change it.', 'pandabot' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pandabot_reset_ratelimits' ); ?>
			<input type="hidden" name="action" value="pandabot_reset_ratelimits">
			<button type="submit" class="button"><?php esc_html_e( 'Clear rate-limit counters', 'pandabot' ); ?></button>
		</form>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Stored data', 'pandabot' ); ?></h2>

		<table class="pandabot-kv">
			<tr>
				<th><?php esc_html_e( 'Conversations stored', 'pandabot' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $privacy_data['conversations'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Messages stored', 'pandabot' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $privacy_data['messages'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Analytics events stored', 'pandabot' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $privacy_data['events'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Oldest conversation', 'pandabot' ); ?></th>
				<td>
					<?php
					// Rows are stored in UTC; wp_date() renders them in the
					// site's timezone.
					echo $privacy_data['oldest']
						? esc_html( wp_date( $date_format, strtotime( $privacy_data['oldest'] . ' UTC' ) ) )
						: '&mdash;';
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last cleanup run', 'pandabot' ); ?></th>
				<td>
					<?php
					echo $privacy_data['last_purge']
						? esc_html( wp_date( $date_format, strtotime( $privacy_data['last_purge'] . ' UTC' ) ) )
						: esc_html__( 'never', 'pandabot' );
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Next cleanup scheduled', 'pandabot' ); ?></th>
				<td>
					<?php
					echo $privacy_data['next_purge']
						? esc_html( wp_date( $date_format, (int) $privacy_data['next_purge'] ) )
						: esc_html__( 'not scheduled', 'pandabot' );
					?>
				</td>
			</tr>
		</table>

		<p class="description">
			<?php esc_html_e( 'Cleanup runs on WP-Cron, which fires on site traffic rather than on a real system clock — on a quiet site it can lag by a few hours. Run it by hand any time:', 'pandabot' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pandabot_purge_now' ); ?>
			<input type="hidden" name="action" value="pandabot_purge_now">
			<button type="submit" class="button"><?php esc_html_e( 'Run retention cleanup now', 'pandabot' ); ?></button>
		</form>

		<h3><?php esc_html_e( 'IP hashing salt', 'pandabot' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Rate limiting counts requests per visitor without ever storing an IP: the address is hashed with a secret salt held only in this database. Regenerating the salt permanently severs any link between stored hashes and a visitor — useful after a data-access request, or if the database was ever exposed. It clears the rate-limit counters as a side effect — if that is all you want, use "Clear rate-limit counters" above instead.', 'pandabot' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pandabot_regenerate_salt' ); ?>
			<input type="hidden" name="action" value="pandabot_regenerate_salt">
			<button type="submit" class="button"><?php esc_html_e( 'Regenerate salt', 'pandabot' ); ?></button>
		</form>

		<h3><?php esc_html_e( 'Delete all chat logs', 'pandabot' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Immediately deletes every conversation, message and analytics event, whatever their age. Your dashboard history goes with them. The indexed site content is not affected, so the bot keeps working and nothing needs re-embedding.', 'pandabot' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pandabot_delete_all_logs' ); ?>
			<input type="hidden" name="action" value="pandabot_delete_all_logs">
			<p>
				<label>
					<input type="checkbox" name="pandabot_confirm" value="1">
					<?php esc_html_e( 'I understand this permanently deletes all chat history.', 'pandabot' ); ?>
				</label>
			</p>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all chat logs', 'pandabot' ); ?></button>
		</form>
	</div>
</div>
