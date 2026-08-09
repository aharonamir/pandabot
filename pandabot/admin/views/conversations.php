<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only browsing.
$conversation_id = isset( $_GET['conversation'] ) ? (int) $_GET['conversation'] : 0;
$paged           = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
// phpcs:enable

$analytics = new Pandabot_Analytics( 0 );
$per_page  = 20;

$guard_labels = array(
	'fallback_no_context' => __( 'No site content matched', 'pandabot' ),
	'blocked_medical'     => __( 'Medical redirect', 'pandabot' ),
	'refused_offtopic'    => __( 'Off-topic refusal', 'pandabot' ),
	'input_too_long'      => __( 'Input too long', 'pandabot' ),
	'rate_limited'        => __( 'Rate limited', 'pandabot' ),
);
?>
<div class="wrap pandabot-wrap">

<?php if ( $conversation_id ) : ?>

	<?php
	$conversation = $analytics->conversation( $conversation_id );
	?>
	<h1><?php esc_html_e( 'PandaBot — Conversation', 'pandabot' ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pandabot-conversations' ) ); ?>">&larr; <?php esc_html_e( 'Back to all conversations', 'pandabot' ); ?></a></p>

	<?php if ( ! $conversation ) : ?>
		<div class="pandabot-card"><p><?php esc_html_e( 'That conversation no longer exists — it may have been removed by the retention policy.', 'pandabot' ); ?></p></div>
	<?php else : ?>
		<div class="pandabot-card">
			<table class="pandabot-kv">
				<tr>
					<th><?php esc_html_e( 'Started', 'pandabot' ); ?></th>
					<td><?php echo esc_html( get_date_from_gmt( $conversation['started_at'], 'j M Y, H:i' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last activity', 'pandabot' ); ?></th>
					<td><?php echo esc_html( get_date_from_gmt( $conversation['last_at'], 'j M Y, H:i' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Messages', 'pandabot' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $conversation['message_count'] ) ); ?></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Visitor IP addresses are never stored in readable form, so there is nothing here that identifies the person.', 'pandabot' ); ?></p>
		</div>

		<div class="pandabot-card pandabot-transcript">
			<?php foreach ( $analytics->transcript( $conversation_id ) as $message ) : ?>
				<?php
				$is_user = ( 'user' === $message['role'] );
				$action  = $message['guardrail_action'];
				$chunks  = $is_user ? array() : $analytics->chunk_labels( $message['retrieved_ids'] );
				?>
				<div class="pandabot-t-msg <?php echo $is_user ? 'is-user' : 'is-bot'; ?>">
					<div class="pandabot-t-bubble"><?php echo nl2br( esc_html( $message['content'] ) ); ?></div>
					<div class="pandabot-t-meta">
						<span><?php echo esc_html( get_date_from_gmt( $message['created_at'], 'j M, H:i' ) ); ?></span>
						<?php if ( ! $is_user && $action && 'none' !== $action ) : ?>
							<span class="pandabot-badge-guard"><?php echo esc_html( isset( $guard_labels[ $action ] ) ? $guard_labels[ $action ] : $action ); ?></span>
						<?php endif; ?>
						<?php if ( ! $is_user && $message['latency_ms'] ) : ?>
							<span><?php echo esc_html( number_format_i18n( $message['latency_ms'] ) . ' ms' ); ?></span>
						<?php endif; ?>
						<?php if ( ! $is_user && ( $message['tokens_prompt'] || $message['tokens_completion'] ) ) : ?>
							<span>
								<?php
								printf(
									/* translators: 1: prompt tokens, 2: completion tokens */
									esc_html__( '%1$s in / %2$s out', 'pandabot' ),
									esc_html( number_format_i18n( (int) $message['tokens_prompt'] ) ),
									esc_html( number_format_i18n( (int) $message['tokens_completion'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $chunks ) ) : ?>
						<div class="pandabot-t-sources">
							<strong><?php esc_html_e( 'Answered from:', 'pandabot' ); ?></strong>
							<?php foreach ( $chunks as $chunk ) : ?>
								<?php if ( ! empty( $chunk['source_url'] ) ) : ?>
									<a href="<?php echo esc_url( $chunk['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $chunk['title'] ); ?></a>
								<?php else : ?>
									<span><?php echo esc_html( $chunk['title'] ); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

<?php else : ?>

	<h1><?php esc_html_e( 'PandaBot — Conversations', 'pandabot' ); ?></h1>

	<?php
	$total       = $analytics->conversation_count();
	$rows        = $analytics->conversation_page( $paged, $per_page );
	$total_pages = (int) ceil( $total / $per_page );
	?>

	<div class="pandabot-card">
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No conversations recorded yet.', 'pandabot' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'First question', 'pandabot' ); ?></th>
						<th class="pandabot-col-num"><?php esc_html_e( 'Messages', 'pandabot' ); ?></th>
						<th class="pandabot-col-num"><?php esc_html_e( 'Guardrails', 'pandabot' ); ?></th>
						<th class="pandabot-col-date"><?php esc_html_e( 'Last activity', 'pandabot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pandabot-conversations&conversation=' . (int) $row['id'] ) ); ?>">
									<?php echo esc_html( $row['first_question'] ? wp_trim_words( $row['first_question'], 14, '…' ) : __( '(no message sent)', 'pandabot' ) ); ?>
								</a>
							</td>
							<td class="pandabot-col-num"><?php echo esc_html( number_format_i18n( $row['message_count'] ) ); ?></td>
							<td class="pandabot-col-num"><?php echo esc_html( number_format_i18n( $row['guardrail_hits'] ) ); ?></td>
							<td class="pandabot-col-date"><?php echo esc_html( get_date_from_gmt( $row['last_at'], 'j M Y, H:i' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => admin_url( 'admin.php?page=pandabot-conversations&paged=%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

<?php endif; ?>

</div>
