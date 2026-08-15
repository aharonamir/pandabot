<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton. Owns hook wiring only — actual behavior lives in the
 * dedicated classes it loads and instantiates.
 */
final class Pandabot {

	private static $instance = null;

	/** @var Pandabot_Admin|null */
	public $admin = null;

	/** @var Pandabot_Rest */
	public $rest = null;

	/** @var Pandabot_Indexer */
	public $indexer = null;

	/** @var Pandabot_Faq_Schema */
	public $faq_schema = null;

	/** @var Pandabot_Widget */
	public $widget = null;

	/** @var Pandabot_Privacy */
	public $privacy = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function run() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->rest = new Pandabot_Rest();
		$this->rest->init();

		$this->indexer = new Pandabot_Indexer();
		$this->indexer->init();

		$this->faq_schema = new Pandabot_Faq_Schema();
		$this->faq_schema->init();

		$this->widget = new Pandabot_Widget();
		$this->widget->init();

		// Not admin-gated: WP-Cron fires on ordinary front-end requests, so
		// the retention job has to be registered on those too.
		$this->privacy = new Pandabot_Privacy();
		$this->privacy->init();

		if ( is_admin() ) {
			// Cheap version check, admin-only: catches the "updated the
			// plugin zip without deactivating" case, where activation
			// hooks never fire. Public/REST requests trust the schema is
			// already current rather than paying this check every load.
			$this->maybe_upgrade();

			require_once PANDABOT_PLUGIN_DIR . 'admin/class-pandabot-chart.php';
			require_once PANDABOT_PLUGIN_DIR . 'admin/class-pandabot-admin.php';
			$this->admin = new Pandabot_Admin();
			$this->admin->init();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'pandabot', false, dirname( PANDABOT_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Re-run table creation if PANDABOT_DB_VERSION was bumped by a plugin
	 * update while the site owner just clicked "update" (no deactivate/
	 * reactivate cycle happens in that case, so activation hooks won't fire).
	 */
	private function maybe_upgrade() {
		$installed = get_option( Pandabot_Settings::DB_VERSION_OPT, '' );
		if ( $installed !== PANDABOT_DB_VERSION ) {
			require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-activator.php';
			Pandabot_Activator::create_tables();
			update_option( Pandabot_Settings::DB_VERSION_OPT, PANDABOT_DB_VERSION, false );
		}
	}
}
