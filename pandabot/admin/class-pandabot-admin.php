<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu shell plus the admin-post handlers for the settings form and
 * the privacy/data-maintenance actions. The screens themselves are plain
 * PHP views under admin/views/.
 */
class Pandabot_Admin {

	const CAPABILITY = 'manage_options';
	const MENU_SLUG   = 'pandabot';

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_pandabot_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_pandabot_regenerate_salt', array( $this, 'handle_regenerate_salt' ) );
		add_action( 'admin_post_pandabot_purge_now', array( $this, 'handle_purge_now' ) );
		add_action( 'admin_post_pandabot_delete_all_logs', array( $this, 'handle_delete_all_logs' ) );
		add_action( 'admin_post_pandabot_reset_ratelimits', array( $this, 'handle_reset_ratelimits' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'PandaBot', 'pandabot' ),
			__( 'PandaBot', 'pandabot' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'pandabot' ),
			__( 'Dashboard', 'pandabot' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'pandabot' ),
			__( 'Settings', 'pandabot' ),
			self::CAPABILITY,
			'pandabot-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Knowledge', 'pandabot' ),
			__( 'Knowledge', 'pandabot' ),
			self::CAPABILITY,
			'pandabot-knowledge',
			array( $this, 'render_knowledge' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Conversations', 'pandabot' ),
			__( 'Conversations', 'pandabot' ),
			self::CAPABILITY,
			'pandabot-conversations',
			array( $this, 'render_conversations' )
		);
	}

	/**
	 * Only load admin CSS/JS on PandaBot's own screens.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'pandabot-admin', PANDABOT_PLUGIN_URL . 'admin/css/admin.css', array(), PANDABOT_VERSION );
		wp_enqueue_script( 'pandabot-admin', PANDABOT_PLUGIN_URL . 'admin/js/admin.js', array(), PANDABOT_VERSION, true );
		// REST URL + nonce are read from data-* attributes rendered directly
		// in admin/views/settings.php, not from wp_localize_script — that
		// keeps the test-connection button working even if a strict CSP
		// blocks inline <script> tags.
	}

	/**
	 * Sanitization merges onto the existing settings rather than replacing
	 * them wholesale, so a partial submission can never blank out fields the
	 * form didn't include.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pandabot' ) );
		}
		check_admin_referer( 'pandabot_save_settings' );

		$raw = isset( $_POST['pandabot_settings'] ) ? wp_unslash( $_POST['pandabot_settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		Pandabot_Settings::save_all( Pandabot_Settings::sanitize_settings( $raw ) );

		$this->redirect_to_settings( array( 'pandabot_saved' => '1' ) );
	}

	/**
	 * Rotating the salt makes every stored ip_hash permanently unmatchable —
	 * that's the point (it severs the last link between a stored row and a
	 * person), but it also resets everyone's per-IP rate-limit counters.
	 */
	public function handle_regenerate_salt() {
		$this->guard( 'pandabot_regenerate_salt' );

		Pandabot_Settings::regenerate_ip_salt();

		$this->redirect_to_settings( array( 'pandabot_notice' => 'salt' ) );
	}

	public function handle_purge_now() {
		$this->guard( 'pandabot_purge_now' );

		$days    = (int) Pandabot_Settings::get( 'retention_days', 30 );
		$deleted = Pandabot_Privacy::purge_older_than( $days );
		update_option( Pandabot_Privacy::LAST_RUN_OPT, gmdate( 'Y-m-d H:i:s' ), false );

		$this->redirect_to_settings(
			array(
				'pandabot_notice' => 'purged',
				'pandabot_n'      => (int) $deleted['conversations'],
			)
		);
	}

	public function handle_delete_all_logs() {
		$this->guard( 'pandabot_delete_all_logs' );

		// The confirmation checkbox is validated server-side rather than with
		// a JS confirm(), so the widget's no-inline-script rule holds here too.
		if ( empty( $_POST['pandabot_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_to_settings( array( 'pandabot_notice' => 'unconfirmed' ) );
		}

		$deleted = Pandabot_Privacy::purge_all();

		$this->redirect_to_settings(
			array(
				'pandabot_notice' => 'wiped',
				'pandabot_n'      => (int) $deleted['conversations'],
			)
		);
	}

	public function handle_reset_ratelimits() {
		$this->guard( 'pandabot_reset_ratelimits' );

		Pandabot_Ratelimit::reset_counters();

		$this->redirect_to_settings( array( 'pandabot_notice' => 'rl_reset' ) );
	}

	private function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pandabot' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect_to_settings( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => 'pandabot-settings' ), $args ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_dashboard() {
		$this->render_view( 'dashboard' );
	}

	public function render_settings() {
		$this->render_view( 'settings' );
	}

	public function render_knowledge() {
		$this->render_view( 'knowledge' );
	}

	public function render_conversations() {
		$this->render_view( 'conversations' );
	}

	private function render_view( $view ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$view_file = PANDABOT_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}
}
