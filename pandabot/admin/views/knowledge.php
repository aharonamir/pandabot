<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$indexer   = new Pandabot_Indexer();
$health    = $indexer->get_health();
$sources   = $indexer->get_source_list();
$settings  = Pandabot_Settings::get_all();
$manual_qa = $settings['manual_qa'];

$chunk_counts_by_manual_id = array();
foreach ( $sources as $s ) {
	if ( 'manual' === $s['source_type'] ) {
		$chunk_counts_by_manual_id[ (int) $s['source_id'] ] = (int) $s['chunk_count'];
	}
}

$rest_url = esc_url( rest_url( 'pandabot/v1/' ) );
$nonce    = esc_attr( wp_create_nonce( 'wp_rest' ) );
?>
<div class="wrap pandabot-wrap">
	<h1><?php esc_html_e( 'PandaBot — Knowledge', 'pandabot' ); ?></h1>

	<div class="pandabot-card pandabot-app" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">
		<h2><?php esc_html_e( 'Index health', 'pandabot' ); ?></h2>
		<table class="widefat striped" style="max-width:620px">
			<tbody>
				<tr>
					<th style="width:220px"><?php esc_html_e( 'Last full reindex', 'pandabot' ); ?></th>
					<td id="pandabot-last-run"><?php echo esc_html( $health['last_run'] ? $health['last_run'] . ' UTC' : __( 'Never', 'pandabot' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Indexed chunks', 'pandabot' ); ?></th>
					<td id="pandabot-chunk-count"><?php echo esc_html( $health['chunk_count'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Indexed sources', 'pandabot' ); ?></th>
					<td id="pandabot-source-count"><?php echo esc_html( $health['source_count'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Indexed post types', 'pandabot' ); ?></th>
					<td>
						<?php echo esc_html( implode( ', ', (array) $settings['indexed_post_types'] ) ); ?>
						<p class="description"><?php esc_html_e( 'Choose which post types are indexed, and exclude individual pages, under Settings → Content scope.', 'pandabot' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $health['failed_items'] ) ) : ?>
			<p class="pandabot-fail-note">
				<?php
				printf(
					/* translators: %d: number of failed items */
					esc_html__( '%d item(s) failed on the last run:', 'pandabot' ),
					count( $health['failed_items'] )
				);
				?>
			</p>
			<ul>
				<?php foreach ( $health['failed_items'] as $fail ) : ?>
					<li><?php echo esc_html( $fail['title'] ? $fail['title'] : ( $fail['type'] . ' #' . $fail['id'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-primary" id="pandabot-reindex-btn"><?php esc_html_e( 'אינדוקס מחדש של הכל', 'pandabot' ); ?></button>
		</p>
		<div id="pandabot-reindex-progress" class="pandabot-progress" hidden>
			<div class="pandabot-progress-bar"><div class="pandabot-progress-fill" id="pandabot-progress-fill"></div></div>
			<p id="pandabot-progress-text"></p>
		</div>
	</div>

	<div class="pandabot-card pandabot-app" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">
		<h2><?php esc_html_e( 'Per-source list', 'pandabot' ); ?></h2>
		<?php if ( empty( $sources ) ) : ?>
			<p><?php esc_html_e( 'Nothing indexed yet. Click the reindex button above.', 'pandabot' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'pandabot' ); ?></th>
						<th><?php esc_html_e( 'Type', 'pandabot' ); ?></th>
						<th><?php esc_html_e( 'Title', 'pandabot' ); ?></th>
						<th><?php esc_html_e( 'Chunks', 'pandabot' ); ?></th>
						<th><?php esc_html_e( 'Updated (UTC)', 'pandabot' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'pandabot' ); ?></th>
					</tr>
				</thead>
				<tbody id="pandabot-source-tbody">
					<?php foreach ( $sources as $s ) : ?>
						<tr data-source-id="<?php echo esc_attr( $s['source_id'] ); ?>">
							<td><?php echo esc_html( $s['source_id'] ); ?></td>
							<td><?php echo esc_html( $s['source_type'] ); ?></td>
							<td>
								<?php if ( ! empty( $s['source_url'] ) ) : ?>
									<a href="<?php echo esc_url( $s['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $s['title'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $s['chunk_count'] ); ?></td>
							<td><?php echo esc_html( $s['updated_at'] ); ?></td>
							<td>
								<?php if ( in_array( $s['source_type'], array( 'post', 'page' ), true ) ) : ?>
									<button type="button" class="button-link-delete pandabot-exclude-btn" data-id="<?php echo esc_attr( $s['source_id'] ); ?>"><?php esc_html_e( 'החרגה', 'pandabot' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $settings['excluded_ids'] ) ) : ?>
			<h3><?php esc_html_e( 'Excluded IDs', 'pandabot' ); ?></h3>
			<ul id="pandabot-excluded-list">
				<?php foreach ( $settings['excluded_ids'] as $ex_id ) : ?>
					<li data-excluded-id="<?php echo esc_attr( $ex_id ); ?>">
						#<?php echo esc_html( $ex_id ); ?>
						<?php $ex_title = get_the_title( $ex_id ); ?>
						<?php echo $ex_title ? '— ' . esc_html( $ex_title ) : ''; ?>
						<button type="button" class="button-link pandabot-include-btn" data-id="<?php echo esc_attr( $ex_id ); ?>"><?php esc_html_e( 'כלול מחדש', 'pandabot' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="pandabot-card pandabot-app" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">
		<h2><?php esc_html_e( 'Manual Q&A entries', 'pandabot' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Perfect for FAQ answers you want the bot to answer crisply and exactly — e.g. insurance reimbursement, who the treatment suits, contact info.', 'pandabot' ); ?></p>

		<div id="pandabot-manual-qa-list">
			<?php foreach ( $manual_qa as $entry ) : ?>
				<div class="pandabot-qa-item">
					<strong><?php echo esc_html( $entry['question'] ); ?></strong>
					<p><?php echo esc_html( $entry['answer'] ); ?></p>
					<span class="description">
						<?php
						printf(
							/* translators: %d: number of indexed chunks for this entry */
							esc_html__( 'Chunks indexed: %d', 'pandabot' ),
							isset( $chunk_counts_by_manual_id[ (int) $entry['id'] ] ) ? $chunk_counts_by_manual_id[ (int) $entry['id'] ] : 0
						);
						?>
					</span>
					<button type="button" class="button-link-delete pandabot-qa-delete" data-id="<?php echo esc_attr( $entry['id'] ); ?>"><?php esc_html_e( 'מחיקה', 'pandabot' ); ?></button>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $manual_qa ) ) : ?>
				<p><?php esc_html_e( 'No manual Q&A entries yet.', 'pandabot' ); ?></p>
			<?php endif; ?>
		</div>

		<h3><?php esc_html_e( 'Add entry', 'pandabot' ); ?></h3>
		<p>
			<label for="pandabot-qa-question"><?php esc_html_e( 'Question', 'pandabot' ); ?></label><br>
			<input type="text" id="pandabot-qa-question" class="regular-text">
		</p>
		<p>
			<label for="pandabot-qa-answer"><?php esc_html_e( 'Answer', 'pandabot' ); ?></label><br>
			<textarea id="pandabot-qa-answer" class="large-text" rows="3"></textarea>
		</p>
		<p>
			<button type="button" class="button button-primary" id="pandabot-qa-add"><?php esc_html_e( 'הוספה ואינדוקס', 'pandabot' ); ?></button>
			<span id="pandabot-qa-result" class="pandabot-test-result"></span>
		</p>
	</div>

	<div class="pandabot-card pandabot-app" data-rest-url="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>">
		<h2><?php esc_html_e( 'בדיקת שיחה (כלי פיתוח זמני)', 'pandabot' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Runs exactly the same pipeline a visitor gets — retrieval, guardrails, rate limits and all — so an answer here is the answer the widget would give. Unlike the widget, it also shows every candidate chunk and its similarity score, including the ones that did not clear the floor below, so you can tune retrieval against real numbers instead of guessing. Note that test questions do count against your daily spend cap.', 'pandabot' ); ?>
		</p>

		<p class="pandabot-tuning-row">
			<label><?php esc_html_e( 'סף דמיון (similarity floor)', 'pandabot' ); ?>
				<input type="number" id="pandabot-tune-floor" step="0.01" min="-1" max="1" value="<?php echo esc_attr( $settings['similarity_floor'] ); ?>" style="width:80px">
			</label>
			<label><?php esc_html_e( 'Top-K', 'pandabot' ); ?>
				<input type="number" id="pandabot-tune-topk" step="1" min="1" max="20" value="<?php echo esc_attr( $settings['top_k'] ); ?>" style="width:60px">
			</label>
			<label><?php esc_html_e( 'Max tokens (תשובה)', 'pandabot' ); ?>
				<input type="number" id="pandabot-tune-maxtokens" step="50" min="50" max="4000" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>" style="width:80px">
			</label>
			<button type="button" class="button" id="pandabot-tune-save"><?php esc_html_e( 'שמירת כוונון', 'pandabot' ); ?></button>
			<span id="pandabot-tune-result" class="pandabot-test-result"></span>
		</p>

		<div id="pandabot-chat-transcript" class="pandabot-chat-transcript"></div>

		<p style="display:flex; gap:8px; align-items:flex-start">
			<input type="text" id="pandabot-chat-input" class="regular-text" style="flex:1" placeholder="<?php esc_attr_e( 'כתבו שאלה…', 'pandabot' ); ?>">
			<button type="button" class="button button-primary" id="pandabot-chat-send"><?php esc_html_e( 'שליחה', 'pandabot' ); ?></button>
			<button type="button" class="button" id="pandabot-chat-reset"><?php esc_html_e( 'איפוס שיחה', 'pandabot' ); ?></button>
		</p>
	</div>
</div>
