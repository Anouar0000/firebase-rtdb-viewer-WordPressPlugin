<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * This file handles all backend administration settings for the plugin.
 */

/**
 * 1. Register the admin menu for Firebase Issues Fetcher.
 * This hook adds a menu item under 'Settings' in the WordPress admin sidebar.
 */
function firebase_issues_fetcher_add_admin_menu() {
    add_options_page(
        'Firebase Settings',           // Page title: Appears in the browser tab and page header.
        'Firebase Connector',          // Menu title: The text shown in the admin menu.
        'manage_options',              // Capability required: Users must have this capability (usually Admins).
        'firebase-issues-fetcher',     // Menu slug: Unique identifier for this page (used in URL).
        'firebase_issues_fetcher_options_page_html' // Callback function: The function that renders the HTML for the page.
    );
}
add_action( 'admin_menu', 'firebase_issues_fetcher_add_admin_menu' );

/**
 * 2. Register settings and fields for Firebase Issues Fetcher.
 * This hook is fired when the admin section is initialized, and it's where we define
 * which settings groups, sections, and fields our plugin uses.
 */
function firebase_issues_fetcher_settings_init() {
    // 2.1. Register a new setting group.
    register_setting(
        'firebase_issues_fetcher_group',
        'firebase_issues_fetcher_settings',
        'firebase_issues_fetcher_sanitize_settings'
    );

    // 2.2. Add a settings section.
    add_settings_section(
        'firebase_issues_fetcher_section_general',
        'General Firebase Cloud Functions Settings',
        'firebase_issues_fetcher_section_general_callback',
        'firebase-issues-fetcher'
    );

    // 2.3. Add settings fields.
    add_settings_field(
        'firebase_issues_fetcher_base_url',
        'Cloud Functions Base URL',
        'firebase_issues_fetcher_base_url_callback',
        'firebase-issues-fetcher',
        'firebase_issues_fetcher_section_general'
    );

    add_settings_field(
        'firebase_issues_fetcher_api_token',
        'API Token',
        'firebase_issues_fetcher_api_token_callback',
        'firebase-issues-fetcher',
        'firebase_issues_fetcher_section_general'
    );

    add_settings_field(
        'firebase_issues_fetcher_limit',
        'Default Issues Limit',
        'firebase_issues_fetcher_limit_callback',
        'firebase-issues-fetcher',
        'firebase_issues_fetcher_section_general'
    );

    add_settings_field(
        'firebase_issues_fetcher_lang',
        'Default Language (e.g., "en")',
        'firebase_issues_fetcher_lang_callback',
        'firebase-issues-fetcher',
        'firebase_issues_fetcher_section_general'
    );

    // New field for Single Issue Page Slug
    add_settings_field(
        'firebase_issues_fetcher_single_page_slug',
        'Single Issue Page Slug',
        'firebase_issues_fetcher_single_page_slug_callback',
        'firebase-issues-fetcher',
        'firebase_issues_fetcher_section_general'
    );
}
add_action( 'admin_init', 'firebase_issues_fetcher_settings_init' );

/**
 * 3. Callback functions for rendering the sections and fields.
 */
function firebase_issues_fetcher_section_general_callback() {
    echo '<p>Configure your Firebase Cloud Functions base URL and API token here. These settings are used by the plugin to fetch data.</p>';
}

function firebase_issues_fetcher_base_url_callback() {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    $value = isset( $options['base_url'] ) ? esc_attr( $options['base_url'] ) : '';
    echo '<input type="url" id="firebase_issues_fetcher_base_url" name="firebase_issues_fetcher_settings[base_url]" value="' . $value . '" class="regular-text" placeholder="e.g., https://us-central1-your-project.cloudfunctions.net/">';
    echo '<p class="description">This is the base URL for your Firebase Cloud Functions (e.g., `https://us-central1-squirrel-news-prod.cloudfunctions.net/`).</p>';
}

function firebase_issues_fetcher_api_token_callback() {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    $value = isset( $options['api_token'] ) ? esc_attr( $options['api_token'] ) : '';
    echo '<input type="password" id="firebase_issues_fetcher_api_token" name="firebase_issues_fetcher_settings[api_token]" value="' . $value . '" class="regular-text" placeholder="Your secret API token">';
    echo '<p class="description">The secret token your Cloud Function expects in the "token" header. Keep this secure!</p>';
}

function firebase_issues_fetcher_limit_callback() {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    $value = isset( $options['limit'] ) ? absint( $options['limit'] ) : 10;
    echo '<input type="number" id="firebase_issues_fetcher_limit" name="firebase_issues_fetcher_settings[limit]" value="' . $value . '" min="1">';
    echo '<p class="description">The default number of latest issues to fetch for list views (e.g., 10).</p>';
}

function firebase_issues_fetcher_lang_callback() {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    $current_value = isset( $options['lang'] ) ? esc_attr( $options['lang'] ) : 'en';

    // Define the language options
    $languages = array(
        'en' => 'English',
        'de' => 'German',
        // Add more languages here if your Firestore data supports them
        // 'fr' => 'French',
        // 'es' => 'Spanish',
    );

    echo '<select id="firebase_issues_fetcher_lang" name="firebase_issues_fetcher_settings[lang]">';
    foreach ( $languages as $code => $name ) {
        echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current_value, $code, false ) . '>' . esc_html( $name ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Select the default language for fetching issues.</p>';
}
// Callback for rendering the Single Issue Page Slug input field.
function firebase_issues_fetcher_single_page_slug_callback() {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    $value = isset( $options['single_page_slug'] ) ? esc_attr( $options['single_page_slug'] ) : 'issue-detail'; // Default slug
    echo '<input type="text" id="firebase_issues_fetcher_single_page_slug" name="firebase_issues_fetcher_settings[single_page_slug]" value="' . $value . '" class="regular-text" placeholder="e.g., issue-detail">';
    echo '<p class="description">The slug of the WordPress page where you display single issue details (e.g., "issue-detail" for `your-site.com/issue-detail/`).</p>';
}

/**
 * 4. Sanitize callback for plugin settings.
 */
function firebase_issues_fetcher_sanitize_settings( $input ) {
    $sanitized_input = array();

    if ( isset( $input['base_url'] ) ) {
        $sanitized_input['base_url'] = esc_url_raw( $input['base_url'] );
    }

    if ( isset( $input['api_token'] ) ) {
        $sanitized_input['api_token'] = sanitize_text_field( $input['api_token'] );
    }

    if ( isset( $input['limit'] ) ) {
        $sanitized_input['limit'] = absint( $input['limit'] );
        if ( $sanitized_input['limit'] < 1 ) {
            $sanitized_input['limit'] = 10;
        }
    }

    if ( isset( $input['lang'] ) ) {
        $sanitized_input['lang'] = sanitize_key( $input['lang'] );
    }

    if ( isset( $input['single_page_slug'] ) ) {
        $sanitized_input['single_page_slug'] = sanitize_title_for_query( $input['single_page_slug'] ); // Clean slug for permalink
    }

    return $sanitized_input;
}

/**
 * 5. Render the plugin's main options page HTML.
 */
function firebase_issues_fetcher_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'firebase_issues_fetcher_messages', 'firebase_issues_fetcher_message', 'Settings Saved', 'updated' );
    }

    settings_errors( 'firebase_issues_fetcher_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'firebase_issues_fetcher_group' );
            do_settings_sections( 'firebase-issues-fetcher' );
            submit_button( 'Save Settings' );
            ?>
        </form>
    </div>
    <?php
}

