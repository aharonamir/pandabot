<?php
/**
 * Plugin Name:       PandaBot
 * Plugin URI:        https://pandakids-clinic.co.il/
 * Description:       Self-hosted RAG chatbot for the clinic site — answers from site content, books calls, never gives medical advice.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Panda Kids Clinic
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pandabot
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PANDABOT_VERSION', '1.1.0' );
define( 'PANDABOT_DB_VERSION', '1' );
define( 'PANDABOT_PLUGIN_FILE', __FILE__ );
define( 'PANDABOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PANDABOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PANDABOT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum PHP version this plugin requires. Checked on activation so a
 * shared-hosting box on an old PHP doesn't get a hard fatal.
 */
define( 'PANDABOT_MIN_PHP', '7.4' );

/**
 * Optional per-site values baked in at build time by build.sh from a
 * gitignored pandabot.config.json, so one clinic's real phone number never
 * has to live in shared source. Absent in the repository and in any plain
 * checkout — the plugin runs fine without it, falling back to whatever is
 * configured on the settings screen.
 *
 * It only ever defines the constants in Pandabot_Settings::constant_map(),
 * and defining a constant twice is a PHP notice, so this must load before
 * anything reads settings.
 */
if ( file_exists( PANDABOT_PLUGIN_DIR . 'site-config.php' ) ) {
	require_once PANDABOT_PLUGIN_DIR . 'site-config.php';
}

require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-activator.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-settings.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-provider.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-chunker.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-embeddings.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-indexer.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-retriever.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-logger.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-analytics.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-privacy.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-guardrails.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-ratelimit.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-chat.php';
require_once PANDABOT_PLUGIN_DIR . 'public/class-pandabot-widget.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot-rest.php';
require_once PANDABOT_PLUGIN_DIR . 'includes/class-pandabot.php';

register_activation_hook( PANDABOT_PLUGIN_FILE, array( 'Pandabot_Activator', 'activate' ) );
register_deactivation_hook( PANDABOT_PLUGIN_FILE, array( 'Pandabot_Activator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded so we're safe to rely on
 * other hooks (i18n, admin, etc).
 */
function pandabot_run() {
	Pandabot::instance()->run();
}
add_action( 'plugins_loaded', 'pandabot_run' );
