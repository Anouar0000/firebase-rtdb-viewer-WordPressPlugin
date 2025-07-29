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

// Exit if accessed directly to prevent security vulnerabilities.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants (good practice for file paths)
if ( ! defined( 'FIREBASE_CONNECTOR_PLUGIN_DIR' ) ) {
    define( 'FIREBASE_CONNECTOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Include core plugin files
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/admin-settings.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/api-client.php';
require_once FIREBASE_CONNECTOR_PLUGIN_DIR . 'includes/frontend-shortcodes.php';

