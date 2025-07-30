<?php
// /includes/admin-settings.php (Corrected and Final Version)

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. ADMIN MENU SETUP
 */
function firebase_connector_add_admin_menu() {
    add_menu_page('Firebase Connector', 'Firebase Connector', 'manage_options', 'firebase-connector-settings', 'firebase_connector_settings_page_html', 'dashicons-cloud', 30);
    add_submenu_page('firebase-connector-settings', 'Firebase Settings', 'Settings', 'manage_options', 'firebase-connector-settings', 'firebase_connector_settings_page_html');
    add_submenu_page('firebase-connector-settings', 'Firebase Sync Tools', 'Tools', 'manage_options', 'firebase-connector-tools', 'firebase_connector_tools_page_html');
}
add_action( 'admin_menu', 'firebase_connector_add_admin_menu' );

/**
 * 2. REGISTER SETTINGS AND FIELDS
 */
function firebase_connector_settings_init() {
    register_setting('firebase_connector_group', 'firebase_connector_settings', 'firebase_connector_sanitize_settings');
    
    // ** THIS WAS THE FIX **: Define the page slug variable to use consistently.
    $page_slug = 'firebase-connector-settings';

    // SECTION 1: API Settings
    add_settings_section('firebase_api_section', 'API Credentials', 'firebase_api_section_callback', $page_slug);
    add_settings_field('api_token', 'API Token', 'firebase_api_token_callback', $page_slug, 'firebase_api_section');

    // SECTION 2: Content Settings
    add_settings_section('firebase_content_section', 'Frontend Content Settings', 'firebase_content_section_callback', $page_slug);
    add_settings_field('limit', 'Default Issues Limit (Shortcode)', 'firebase_limit_callback', $page_slug, 'firebase_content_section');
    add_settings_field('lang', 'Default Language', 'firebase_lang_callback', $page_slug, 'firebase_content_section');

    // SECTION 3: Sync Behavior
    add_settings_section('firebase_sync_section', 'Sync Behavior', 'firebase_sync_section_callback', $page_slug);
    add_settings_field('enable_auto_sync', 'Enable Automatic Sync', 'firebase_enable_auto_sync_callback', $page_slug, 'firebase_sync_section');
    add_settings_field('sync_schedule', 'Sync Schedule', 'firebase_sync_schedule_callback', $page_slug, 'firebase_sync_section');
    add_settings_field('ongoing_sync_limit', 'Ongoing Sync Fetch Limit', 'firebase_ongoing_sync_limit_callback', $page_slug, 'firebase_sync_section');
    add_settings_field('admin_limit', 'Admin Tools Fetch Limit', 'firebase_admin_limit_callback', $page_slug, 'firebase_sync_section');
    add_settings_field('create_as_draft', 'Safety: Create Posts as Drafts', 'firebase_create_as_draft_callback', $page_slug, 'firebase_sync_section');
}
add_action( 'admin_init', 'firebase_connector_settings_init' );

/**
 * 3. CALLBACKS FOR SECTIONS AND FIELDS
 */
function firebase_api_section_callback() { echo '<p>Configure your Firebase Cloud Functions API token. This is required to fetch data.</p>'; }
function firebase_content_section_callback() { echo '<p>Settings that affect how content is displayed on the front-end of your site (e.g., via shortcodes).</p>'; }
function firebase_sync_section_callback() { echo '<p>Settings that control the behavior of the backend synchronization.</p>'; }

function firebase_api_token_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="password" name="firebase_connector_settings[api_token]" value="' . esc_attr($options['api_token'] ?? '') . '" class="regular-text" placeholder="Your secret API token">';
}
function firebase_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[limit]" value="' . absint($options['limit'] ?? 10) . '" min="1">';
    echo '<p class="description">The default number of issues to show in the `[firebase_issues_list]` shortcode.</p>';
}
function firebase_lang_callback() {
    $options = get_option('firebase_connector_settings');
    $current_value = $options['lang'] ?? 'en';
    $languages = ['en' => 'English', 'de' => 'German'];
    echo '<select name="firebase_connector_settings[lang]">';
    foreach ( $languages as $code => $name ) echo '<option value="' . esc_attr($code) . '" ' . selected($current_value, $code, false) . '>' . esc_html($name) . '</option>';
    echo '</select>';
}
function firebase_admin_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[admin_limit]" value="' . absint($options['admin_limit'] ?? 200) . '" min="1">';
    echo '<p class="description">The number of recent issues to check when using the admin tools (Dry Run, Link, Backfill).</p>';
}
function firebase_create_as_draft_callback() {
    $options = get_option('firebase_connector_settings');
    $checked = isset($options['create_as_draft']) ? checked(1, $options['create_as_draft'], false) : 'checked="checked"';
    echo '<input type="checkbox" name="firebase_connector_settings[create_as_draft]" value="1" ' . $checked . '>';
    echo '<p class="description">When checked, all new posts will be saved as drafts instead of being published live.</p>';
}
function firebase_ongoing_sync_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[ongoing_sync_limit]" value="' . absint($options['ongoing_sync_limit'] ?? 50) . '" min="1">';
    echo '<p class="description">The number of recent issues to check during a normal, ongoing sync (manual button or hourly cron job).</p>';
}
function firebase_enable_auto_sync_callback() {
    $options = get_option('firebase_connector_settings');
    $checked = isset($options['enable_auto_sync']) ? checked(1, $options['enable_auto_sync'], false) : '';
    echo '<input type="checkbox" name="firebase_connector_settings[enable_auto_sync]" value="1" ' . $checked . '>';
    echo '<p class="description">Master switch to enable or disable the automatic background sync.</p>';
}
function firebase_sync_schedule_callback() {
    $options = get_option('firebase_connector_settings');
    $current_value = $options['sync_schedule'] ?? 'hourly';
    $schedules = ['hourly' => 'Once Hourly', 'twicedaily' => 'Twice Daily', 'daily' => 'Once Daily', 'weekly' => 'Once Weekly'];
    echo '<select name="firebase_connector_settings[sync_schedule]">';
    foreach ( $schedules as $value => $label ) echo '<option value="' . esc_attr($value) . '" ' . selected($current_value, $value, false) . '>' . esc_html($label) . '</option>';
    echo '</select>';
    echo '<p class="description">How often the automatic sync should run.</p>';
}

/**
 * 4. SANITIZE SETTINGS
 */
function firebase_connector_sanitize_settings( $input ) {
    $sanitized = [];
    $old_options = get_option('firebase_connector_settings');
    $sanitized['api_token'] = sanitize_text_field($input['api_token'] ?? '');
    $sanitized['limit'] = isset($input['limit']) ? absint($input['limit']) : 10;
    $sanitized['lang'] = isset($input['lang']) ? sanitize_key($input['lang']) : 'en';
    $sanitized['admin_limit'] = isset($input['admin_limit']) ? absint($input['admin_limit']) : 200;
    $sanitized['ongoing_sync_limit'] = isset($input['ongoing_sync_limit']) ? absint($input['ongoing_sync_limit']) : 50;
    $sanitized['create_as_draft'] = isset($input['create_as_draft']) ? 1 : 0;
    $sanitized['enable_auto_sync'] = isset($input['enable_auto_sync']) ? 1 : 0;
    $sanitized['sync_schedule'] = isset($input['sync_schedule']) ? sanitize_key($input['sync_schedule']) : 'hourly';
    $old_enabled = $old_options['enable_auto_sync'] ?? 0;
    $new_enabled = $sanitized['enable_auto_sync'];
    $old_schedule = $old_options['sync_schedule'] ?? 'hourly';
    $new_schedule = $sanitized['sync_schedule'];
    if ( $old_enabled !== $new_enabled || $old_schedule !== $new_schedule ) {
        wp_clear_scheduled_hook( FIREBASE_CRON_HOOK );
        if ( $new_enabled ) {
            wp_schedule_event( time(), $new_schedule, FIREBASE_CRON_HOOK );
        }
    }
    return $sanitized;
}

/**
 * 5. RENDER ADMIN PAGES
 */
function firebase_connector_settings_page_html() {
    if ( ! current_user_can('manage_options') ) return;
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p>Configure the core settings for the Firebase Connector plugin below.</p>
        <form action="options.php" method="post">
            <?php
            settings_fields('firebase_connector_group');
            do_settings_sections('firebase-connector-settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

function firebase_connector_tools_page_html() {
    if ( ! current_user_can('manage_options') ) return;
    $dry_run_results = null;
    if ( isset($_POST['action']) && $_POST['action'] === 'link_dry_run' && check_admin_referer('firebase_link_dry_run_nonce') ) { $dry_run_results = firebase_connector_dry_run_link_by_title(); add_settings_error('firebase_connector_messages', 'firebase_dry_run_message', 'Dry Run report is ready below.', 'info'); }
    if ( isset($_POST['action']) && $_POST['action'] === 'link_by_title' && check_admin_referer('firebase_link_by_title_nonce') ) { $result = firebase_connector_link_posts_by_title(); add_settings_error('firebase_connector_messages', 'firebase_link_message', "Linking complete. Linked: {$result['linked']}.", 'success'); }
    if ( isset($_POST['action']) && $_POST['action'] === 'create_missing' && check_admin_referer('firebase_create_missing_nonce') ) { $result = firebase_connector_create_missing_posts(); add_settings_error('firebase_connector_messages', 'firebase_create_message', "Backfill complete. Created {$result['created']} new posts as drafts. Skipped {$result['matches']} posts that already exist.", 'success'); }
    if ( isset($_POST['action']) && $_POST['action'] === 'sync_now' && check_admin_referer('firebase_sync_now_nonce') ) { firebase_connector_sync_issues_to_posts(); add_settings_error('firebase_connector_messages', 'firebase_sync_message', 'Ongoing sync complete!', 'success'); }
    ?>
    <style>#sync-tool-controls { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; } #sync-tool-filters { display: flex; align-items: center; gap: 10px; } .tablenav-pages .current-page { background: #f0f0f1; border: 1px solid #dcdcde; }</style>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors('firebase_connector_messages'); ?>
        <p>This tool allows you to compare your WordPress posts with Firebase issues and sync them individually or in bulk.</p>
        <div id="sync-tool-controls">
            <button id="scan-firebase-issues" class="button button-primary">Scan for Issues</button>
            <div id="sync-tool-filters" style="display: none;">
                <select id="status-filter">
                    <option value="all" selected>Show All</option>
                    <option value="unsynced">Show Unsynced (Missing & Unlinked)</option>
                    <option value="synced">Show Synced (Managed & Protected)</option>
                    <option value="missing">Show Missing Only</option>
                    <option value="match_unlinked">Show Unlinked Matches Only</option>
                </select>
                <input type="search" id="search-filter" placeholder="Search by headline...">
            </div>
            <span class="spinner"></span>
        </div>
        <div id="sync-tool-actions" style="margin-top: 15px; display: none;">
            <button id="create-selected" class="button">Create Selected Missing</button>
<button id="link-selected" class="button">Link Selected Matches</button></div>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; display: none;">
            <thead><tr><td id="cb" class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></td><th scope="col" class="manage-column">Firebase Headline</th><th scope="col" class="manage-column">Status</th><th scope="col" class="manage-column">Actions</th></tr></thead>
            <tbody id="firebase-sync-table-body"></tbody>
        </table>
        <div class="tablenav bottom" style="display: none;"><div id="pagination-controls" class="tablenav-pages"></div></div>
    </div>
    <template id="sync-row-template"><tr><th scope="row" class="check-column"><input type="checkbox" class="row-checkbox" data-issue-id="{{issueId}}"></th><td><strong>{{headline}}</strong><br><small>ID: {{issueId}}</small></td><td class="status-cell"><span class="status-label">{{status}}</span></td><td class="actions-cell"><button class="button button-small action-create" data-issue-id="{{issueId}}">Create Post</button><span class="spinner row-spinner"></span></td></tr></template>
    <?php
}

function firebase_connector_enqueue_admin_scripts($hook_suffix) {
    if ( 'firebase-connector_page_firebase-connector-tools' !== $hook_suffix ) return;
    wp_enqueue_script('firebase-connector-sync-tool', plugin_dir_url( __FILE__ ) . '../js/admin-sync-tool.js', ['jquery'], '1.0.1', true);
    wp_localize_script('firebase-connector-sync-tool', 'firebase_sync_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('firebase_sync_nonce')]);
}
add_action('admin_enqueue_scripts', 'firebase_connector_enqueue_admin_scripts');