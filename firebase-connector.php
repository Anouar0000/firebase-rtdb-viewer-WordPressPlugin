<?php
/**
 * Plugin Name: Firebase connector
 * Plugin URI:  https://example.com/
 * Description: Connect to Firebase, Fetches the issues from Firebase Firestore.
 * Version:     1.0.0
 * Author:      Anouar Ben Hamza
 * Author URI:  https://example.com/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: firebase-connector
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
if ( ! defined( 'FIREBASE_CONNECTOR_PLUGIN_DIR' ) ) {
    define( 'FIREBASE_CONNECTOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Include core plugin files
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/admin-settings.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/api-client.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/frontend-shortcodes.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/sync-handler.php';

// Define the name of our custom cron hook
define( 'FIREBASE_CRON_HOOK', 'firebase_connector_hourly_sync' );

/**
 * Schedules the cron job on plugin activation if it doesn't already exist.
 */
function firebase_connector_activate() {
    // Only schedule if it's not already scheduled.
    // The settings page will handle changes after this.
    if ( ! wp_next_scheduled( FIREBASE_CRON_HOOK ) ) {
        $options = get_option('firebase_connector_settings');
        // Check if user has settings saved to enable it, otherwise do nothing.
        if ( !empty($options['enable_auto_sync']) ) {
            wp_schedule_event( time(), $options['sync_schedule'] ?? 'hourly', FIREBASE_CRON_HOOK );
        }
    }
}
register_activation_hook( __FILE__, 'firebase_connector_activate' );

/**
 * Clears the scheduled cron job on plugin deactivation.
 */
function firebase_connector_deactivate() {
    wp_clear_scheduled_hook( FIREBASE_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'firebase_connector_deactivate' );

// This listener just waits for the hook to be fired.
add_action( FIREBASE_CRON_HOOK, 'firebase_connector_sync_issues_to_posts' );
register_activation_hook( __FILE__, 'firebase_connector_activate' );

// This listener just waits for the hook to be fired.
add_action( FIREBASE_CRON_HOOK, 'firebase_connector_sync_issues_to_posts' );
register_activation_hook( __FILE__, 'firebase_connector_activate' );

/**
 * The action that our cron job will trigger. It calls our main sync function.
 */
add_action( FIREBASE_CRON_HOOK, 'firebase_connector_sync_issues_to_posts' );


