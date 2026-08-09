<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters.
$allowed_ranges = array( 7, 30, 90, 0 );
$range          = isset( $_GET['range'] ) ? (int) $_GET['range'] : 30;
if ( ! in_array( $range, $allowed_ranges, true ) ) {
	$range = 30;
}
// phpcs:enable

$analytics = new Pandabot_Analytics( $range );
$totals    = $analytics->totals();
$funnel    = $analytics->funnel();
$guards    = $analytics->guardrails();
$cost      = $analytics->cost();
$tokens    = $analytics->tokens();
$usage     = $analytics->global_usage_today();
$gaps      = $analytics->fallback_questions( 25 );
$terms     = $analytics->top_terms( 24 );

$fallbacks   = isset( $guards['counts']['fallback_no_context'] ) ? $guards['counts']['fallback_no_context'] : 0;
$fallback_pc = ( $guards['total'] > 0 ) ? round( ( $fallbacks / $guards['total'] ) * 100, 1 ) : 0;

$guard_labels = array(
	'none'                => __( 'Answered normally', 'pandabot' ),
	'fallback_no_context' => __( 'No site content matched', 'pandabot' ),
	'blocked_medical'     => __( 'Medical redirect', 'pandabot' ),
	'refused_offtopic'    => __( 'Off-topic refusal', 'pandabot' ),
	'input_too_long'      => __( 'Input too long', 'pandabot' ),
	'rate_limited'        => __( 'Rate limited', 'pandabot' ),
);
?>
<div class="wrap pandabot-wrap">
	<h1><?php esc_html_e( 'PandaBot — Dashboard', 'pandabot' ); ?></h1>

	<form method="get" class="pandabot-range">
		<input type="hidden" name="page" value="pandabot">
		<label for="pandabot-range"><?php esc_html_e( 'Period', 'pandabot' ); ?></label>
		<select name="range" id="pandabot-range" onchange="this.form.submit()">
			<option value="7" <?php selected( $range, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'pandabot' ); ?></option>
			<option value="30" <?php selected( $range, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'pandabot' ); ?></option>
			<option value="90" <?php selected( $range, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'pandabot' ); ?></option>
			<option value="0" <?php selected( $range, 0 ); ?>><?php esc_html_e( 'All time', 'pandabot' ); ?></option>
		</select>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'pandabot' ); ?></button></noscript>
	</form>

	<?php if ( $usage['cap'] > 0 && $usage['percent'] >= 80 ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: 1: messages used today, 2: configured daily cap, 3: percentage */
					esc_html__( 'Today\'s usage is %1$s of the %2$s daily cap (%3$s%%). Once the cap is reached the widget shows the rate-limit message instead of calling the provider.', 'pandabot' ),
					esc_html( number_format_i18n( $usage['used'] ) ),
					esc_html( number_format_i18n( $usage['cap'] ) ),
					esc_html( $usage['percent'] )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="pandabot-tiles">
		<div class="pandabot-tile">
			<span class="pandabot-tile-label"><?php esc_html_e( 'Conversations', 'pandabot' ); ?></span>
			<span class="pandabot-tile-value"><?php echo esc_html( number_format_i18n( $totals['conversations'] ) ); ?></span>
		</div>
		<div class="pandabot-tile">
			<span class="pandabot-tile-label"><?php esc_html_e( 'Questions asked', 'pandabot' ); ?></span>
			<span class="pandabot-tile-value"><?php echo esc_html( number_format_i18n( $totals['user_messages'] ) ); ?></span>
		</div>
		<div class="pandabot-tile">
			<span class="pandabot-tile-label"><?php esc_html_e( 'Avg messages / conversation', 'pandabot' ); ?></span>
			<span class="pandabot-tile-value"><?php echo esc_html( number_format_i18n( $totals['avg_messages'], 1 ) ); ?></span>
		</div>
		<div class="pandabot-tile">
			<span class="pandabot-tile-label"><?php esc_html_e( 'Content gaps (no match)', 'pandabot' ); ?></span>
			<span class="pandabot-tile-value"><?php echo esc_html( number_format_i18n( $fallbacks ) ); ?></span>
			<span class="pandabot-tile-sub"><?php echo esc_html( $fallback_pc . '% ' . __( 'of answers', 'pandabot' ) ); ?></span>
		</div>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Conversion funnel', 'pandabot' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Distinct visitor sessions reaching each stage. A click on any booking, phone or WhatsApp button counts as a conversion.', 'pandabot' ); ?></p>

		<?php
		$stages = array(
			array( __( 'Opened the chat', 'pandabot' ), $funnel['opens'] ),
			array( __( 'Sent a first message', 'pandabot' ), $funnel['first_messages'] ),
			array( __( 'Clicked a contact button', 'pandabot' ), $funnel['cta_sessions'] ),
		);
		$top = max( 1, $funnel['opens'] );
		?>
		<table class="pandabot-funnel">
			<?php foreach ( $stages as $index => $stage ) : ?>
				<tr>
					<th><?php echo esc_html( $stage[0] ); ?></th>
					<td class="pandabot-funnel-bar"><?php echo Pandabot_Chart::share_bar( $stage[1], $top, '#2271b1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from Pandabot_Chart. ?></td>
					<td class="pandabot-funnel-num"><?php echo esc_html( number_format_i18n( $stage[1] ) ); ?></td>
					<td class="pandabot-funnel-pc">
						<?php
						if ( $index > 0 && $funnel['opens'] > 0 ) {
							echo esc_html( round( ( $stage[1] / $funnel['opens'] ) * 100, 1 ) . '%' );
						} else {
							echo '&mdash;';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<p class="pandabot-cta-split">
			<?php
			printf(
				/* translators: 1: booking clicks, 2: phone clicks, 3: whatsapp clicks, 4: suggested prompt clicks */
				esc_html__( 'Button clicks — booking: %1$s · phone: %2$s · WhatsApp: %3$s. Suggested prompts clicked: %4$s.', 'pandabot' ),
				esc_html( number_format_i18n( $funnel['cta_by_type']['cta_click_booking'] ) ),
				esc_html( number_format_i18n( $funnel['cta_by_type']['cta_click_phone'] ) ),
				esc_html( number_format_i18n( $funnel['cta_by_type']['cta_click_whatsapp'] ) ),
				esc_html( number_format_i18n( $funnel['prompt_clicks'] ) )
			);
			?>
		</p>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Questions per day', 'pandabot' ); ?></h2>
		<?php echo Pandabot_Chart::bars( $analytics->daily( 'messages' ), array( 'color' => '#2271b1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by Pandabot_Chart with escaped values. ?>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Contact button clicks per day', 'pandabot' ); ?></h2>
		<?php echo Pandabot_Chart::bars( $analytics->daily( 'cta' ), array( 'color' => '#00854a' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by Pandabot_Chart with escaped values. ?>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Content gaps — questions the site could not answer', 'pandabot' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Each row is a real question where retrieval found nothing above the similarity floor. This is the list of pages or Q&A entries worth adding.', 'pandabot' ); ?></p>
		<?php if ( empty( $gaps ) ) : ?>
			<p><?php esc_html_e( 'No content gaps recorded in this period.', 'pandabot' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Question', 'pandabot' ); ?></th>
						<th class="pandabot-col-date"><?php esc_html_e( 'When', 'pandabot' ); ?></th>
						<th class="pandabot-col-action"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $gaps as $gap ) : ?>
						<tr>
							<td><?php echo esc_html( $gap['question'] ? $gap['question'] : __( '(question not recorded)', 'pandabot' ) ); ?></td>
							<td class="pandabot-col-date"><?php echo esc_html( get_date_from_gmt( $gap['created_at'], 'j M Y, H:i' ) ); ?></td>
							<td class="pandabot-col-action">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pandabot-conversations&conversation=' . (int) $gap['conversation_id'] ) ); ?>"><?php esc_html_e( 'View', 'pandabot' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Answer outcomes', 'pandabot' ); ?></h2>
		<?php if ( 0 === $guards['total'] ) : ?>
			<p><?php esc_html_e( 'No answers in this period yet.', 'pandabot' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pandabot-breakdown">
				<tbody>
					<?php foreach ( $guards['counts'] as $action => $count ) : ?>
						<tr>
							<th><?php echo esc_html( isset( $guard_labels[ $action ] ) ? $guard_labels[ $action ] : $action ); ?></th>
							<td class="pandabot-funnel-bar"><?php echo Pandabot_Chart::share_bar( $count, $guards['total'], '#4f5a63' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from Pandabot_Chart. ?></td>
							<td class="pandabot-funnel-num"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
							<td class="pandabot-funnel-pc"><?php echo esc_html( round( ( $count / $guards['total'] ) * 100, 1 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of rate-limit hits */
					esc_html__( 'Rate-limit hits in this period: %s.', 'pandabot' ),
					esc_html( number_format_i18n( $funnel['rate_limited'] ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'What people ask about', 'pandabot' ); ?></h2>
		<?php if ( empty( $terms ) ) : ?>
			<p><?php esc_html_e( 'Not enough questions yet to show themes.', 'pandabot' ); ?></p>
		<?php else : ?>
			<ul class="pandabot-terms">
				<?php foreach ( $terms as $term => $count ) : ?>
					<li><span class="pandabot-term"><?php echo esc_html( $term ); ?></span><span class="pandabot-term-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Plain word frequency across recent questions — a rough signal of themes, not topic modelling.', 'pandabot' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="pandabot-card">
		<h2><?php esc_html_e( 'Token usage & estimated cost', 'pandabot' ); ?></h2>
		<table class="pandabot-kv">
			<tr>
				<th><?php esc_html_e( 'Prompt tokens', 'pandabot' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $tokens['prompt'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Completion tokens', 'pandabot' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $tokens['completion'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Estimated chat cost', 'pandabot' ); ?></th>
				<td>
					<?php if ( $cost['priced'] ) : ?>
						<strong><?php echo esc_html( number_format_i18n( $cost['total'], 2 ) ); ?></strong>
					<?php else : ?>
						<em><?php esc_html_e( 'Set per-1K token prices in Settings to see a cost estimate.', 'pandabot' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Counts only what providers reported back, and covers chat completions only — indexing/embedding spend is not included.', 'pandabot' ); ?></p>
		<h3><?php esc_html_e( 'Tokens per day', 'pandabot' ); ?></h3>
		<?php echo Pandabot_Chart::bars( $analytics->daily( 'tokens' ), array( 'color' => '#8c5e00' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by Pandabot_Chart with escaped values. ?>
	</div>
</div>
