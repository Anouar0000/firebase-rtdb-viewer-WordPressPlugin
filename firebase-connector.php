<?php
/**
 * Plugin Name: Firebase Connector
 * Plugin URI:  https://github.com/Anouar0000/firebase-rtdb-viewer-WordPressPlugin/tree/main
 * Description: Seamlessly sync news issues from Google Firebase Firestore into native WordPress posts with powerful interactive admin tools.
 * Version:     3.0.0
 * Author:      Anouar Ben Hamza
 * Author URI:  https://github.com/Anouar0000
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: firebase-connector
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ======================================================================
 * PLUGIN CONSTANTS
 * Define all constants here so they are available globally.
 * ======================================================================
 */
define( 'FIREBASE_CONNECTOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// Meta Keys (moved from post-helpers.php)
define( 'FIREBASE_ISSUE_ID_META_KEY', '_firebase_issue_id' );
define( 'FIREBASE_CONNECTOR_MANAGED_KEY', '_firebase_connector_managed' );
define( 'FIREBASE_IMAGE_URL_META_KEY', '_firebase_image_url' );

// Include core plugin files
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/admin-settings.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/api-client.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/frontend-shortcodes.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/post-helpers.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/ajax-handlers.php';

/**
 * Remove any recurring event left behind by the abandoned background sync feature.
 */
function firebase_connector_clear_legacy_cron_schedule() {
    if ( get_option( 'firebase_connector_legacy_cron_cleared' ) ) {
        return;
    }

    wp_clear_scheduled_hook( 'firebase_connector_hourly_sync' );
    update_option( 'firebase_connector_legacy_cron_cleared', 1, false );
}
add_action( 'init', 'firebase_connector_clear_legacy_cron_schedule' );
