<?php
// /includes/admin-settings.php (Final Reorganized Version)

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. ADMIN MENU SETUP (Reordered)
 */
function firebase_connector_add_admin_menu() {
    add_menu_page('Firebase Tools', 'Firebase Connector', 'manage_options', 'firebase-connector-tools', 'firebase_connector_tools_page_html', 'dashicons-cloud', 30);
    add_submenu_page('firebase-connector-tools', 'Sync Tools', 'Tools', 'manage_options', 'firebase-connector-tools', 'firebase_connector_tools_page_html');
    add_submenu_page('firebase-connector-tools', 'Firebase Settings', 'Settings', 'manage_options', 'firebase-connector-settings', 'firebase_connector_settings_page_html');
}
add_action( 'admin_menu', 'firebase_connector_add_admin_menu' );

/**
 * 2. REGISTER SETTINGS AND FIELDS (Reorganized)
 */
function firebase_connector_settings_init() {
    register_setting('firebase_connector_group', 'firebase_connector_settings', 'firebase_connector_sanitize_settings');
    $page_slug = 'firebase-connector-settings';

    // SECTION 1: API
    add_settings_section('firebase_api_section', 'API Credentials', null, $page_slug);
    add_settings_field('api_token', 'API Token', 'firebase_api_token_callback', $page_slug, 'firebase_api_section');

    // SECTION 2: Frontend
    add_settings_section('firebase_content_section', 'Frontend Content Settings', null, $page_slug);
    add_settings_field('limit', 'The max number of issues', 'firebase_limit_callback', $page_slug, 'firebase_content_section');
    add_settings_field('lang', 'Default Language', 'firebase_lang_callback', $page_slug, 'firebase_content_section');

    // SECTION 3: Automation
    add_settings_section('firebase_automation_section', 'Automation Settings', null, $page_slug);
    add_settings_field('enable_auto_sync', 'Enable Automatic Sync', 'firebase_enable_auto_sync_callback', $page_slug, 'firebase_automation_section');
    add_settings_field('sync_schedule', 'Sync Schedule', 'firebase_sync_schedule_callback', $page_slug, 'firebase_automation_section');
    add_settings_field('ongoing_sync_limit', 'Ongoing Sync Fetch Limit', 'firebase_ongoing_sync_limit_callback', $page_slug, 'firebase_automation_section');
    add_settings_field('admin_limit', 'Admin Tools Fetch Limit', 'firebase_admin_limit_callback', $page_slug, 'firebase_automation_section');

}
add_action( 'admin_init', 'firebase_connector_settings_init' );

/**
 * 3. CALLBACKS FOR SETTINGS FIELDS
 */
function firebase_api_token_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="password" name="firebase_connector_settings[api_token]" value="' . esc_attr($options['api_token'] ?? '') . '" class="regular-text" placeholder="Your secret API token">';
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
function firebase_ongoing_sync_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[ongoing_sync_limit]" value="' . absint($options['ongoing_sync_limit'] ?? 50) . '" min="1">';
    echo '<p class="description">The number of recent issues to check during an automatic or quick sync.</p>';
}
function firebase_admin_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[admin_limit]" value="' . absint($options['admin_limit'] ?? 200) . '" min="1">';
    echo '<p class="description">The number of issues to check when using the Interactive Tool.</p>';
}
function firebase_limit_callback() {
    $options = get_option('firebase_connector_settings');
    echo '<input type="number" name="firebase_connector_settings[limit]" value="' . absint($options['limit'] ?? 10) . '" min="1">';
    echo '<p class="description">The maximun number of issues visible in the News page using the shortcode `[firebase_issues_list]`.</p>';
}
function firebase_lang_callback() {
    $options = get_option('firebase_connector_settings');
    $current_value = $options['lang'] ?? 'en';
    $languages = ['en' => 'English', 'de' => 'German'];
    echo '<select name="firebase_connector_settings[lang]">';
    foreach ( $languages as $code => $name ) echo '<option value="' . esc_attr($code) . '" ' . selected($current_value, $code, false) . '>' . esc_html($name) . '</option>';
    echo '</select>';
}

/**
 * 4. SANITIZE SETTINGS
 */
function firebase_connector_sanitize_settings( $input ) {
    $sanitized = [];
    $old_options = get_option('firebase_connector_settings');
    $sanitized['api_token'] = sanitize_text_field($input['api_token'] ?? '');
    $sanitized['limit'] = absint($input['limit'] ?? 10);
    $sanitized['lang'] = sanitize_key($input['lang'] ?? 'en');
    $sanitized['enable_auto_sync'] = isset($input['enable_auto_sync']) ? 1 : 0;
    $sanitized['sync_schedule'] = sanitize_key($input['sync_schedule'] ?? 'hourly');
    $sanitized['ongoing_sync_limit'] = absint($input['ongoing_sync_limit'] ?? 50);
    $sanitized['admin_limit'] = absint($input['admin_limit'] ?? 200);

    // Cron job logic
    if (($old_options['enable_auto_sync'] ?? 0) !== $sanitized['enable_auto_sync'] || ($old_options['sync_schedule'] ?? 'hourly') !== $sanitized['sync_schedule']) {
        wp_clear_scheduled_hook(FIREBASE_CRON_HOOK);
        if ($sanitized['enable_auto_sync']) {
            wp_schedule_event(time(), $sanitized['sync_schedule'], FIREBASE_CRON_HOOK);
        }
    }
    return $sanitized;
}

/**
 * 5. RENDER ADMIN PAGES
 */
function firebase_connector_settings_page_html() {
    ?>
    <div class="wrap">
        <h1>Firebase Connector Settings</h1>
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
    $options = get_option('firebase_connector_settings');
    $admin_limit = $options['admin_limit'] ?? 200;
    $ongoing_sync_limit = $options['ongoing_sync_limit'] ?? 50;
    ?>
    <style>
        .firebase-controls { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;}
        #sync-tool-filters { display: flex; align-items: center; gap: 10px; }
        .tablenav-pages .current-page { background: #f0f0f1; border: 1px solid #dcdcde; }
        .firebase-controls .description a { text-decoration: none; }
    </style>
    <div class="wrap">
        <h1>Firebase Sync Tools</h1>
        <?php settings_errors('firebase_connector_messages'); ?>

        <hr>
        <h2>Quick Actions</h2>
        <div class="firebase-controls">
            <button id="quick-sync-button" class="button button-secondary">Refresh Recent Issues</button>
            <p class="description">
                (Update/link/create a draft for the latest <strong><?php echo esc_html($ongoing_sync_limit); ?></strong> issues. <a href="<?php echo admin_url('admin.php?page=firebase-connector-settings'); ?>">Change</a>)
            </p>
        </div>
        <div id="quick-sync-progress-bar-container" style="display: none; margin-bottom: 20px; background-color: #eee; border-radius: 4px; padding: 3px; border: 1px solid #ccc;">
            <div id="quick-sync-progress-bar" style="width: 0%; height: 20px; background-color: #46b450; border-radius: 2px; text-align: center; color: white; line-height: 20px;"></div>
        </div>
        <div id="quick-sync-progress-label" style="display: none; margin-top: -15px; margin-bottom: 20px; font-style: italic; color: #555;"></div>

        <hr>
        <h2>Interactive Sync Tool</h2>
        <p>Use this tool for detailed management and bulk actions.</p>
        <div class="firebase-controls">
            <button id="scan-firebase-issues" class="button button-primary">Scan All Issues</button>
            <p class="description">
                (Scans the latest <strong><?php echo esc_html($admin_limit); ?></strong> issues. <a href="<?php echo admin_url('admin.php?page=firebase-connector-settings'); ?>">Change</a>)
            </p>
            <span class="spinner" style="float: none; margin-top: 4px;"></span>
        </div>
        
        <div id="sync-tool-filters" style="display: none; margin-top: 15px; margin-bottom: 15px;">
        <!-- Filter Dropdown -->
        <select id="status-filter">
            <option value="all" selected>Show All</option>
            <option value="unsynced">Show Unsynced</option>
            <option value="synced">Show Synced</option>
            <option value="missing">Missing Only</option>
            <option value="match_unlinked">Unlinked Matches Only</option>
            <option value="draft_managed">Drafts Only</option>
        </select>

        <!-- Sort Dropdown -->
        <select id="sort-filter">
            <option value="newest">Sort by Newest First</option>
            <option value="oldest">Sort by Oldest First</option>
        </select>
            <input type="search" id="search-filter" placeholder="Search by headline...">
        </div>

        <div id="sync-tool-actions" style="margin-top: 15px; display: none;">
            <button id="create-selected" type="button" class="button">Create Selected Missing</button>
            <button id="link-selected" type="button" class="button">Link Selected Matches</button>
            <button id="publish-selected" type="button" class="button button-primary">Publish Selected Drafts</button>
        </div>
        
<table class="wp-list-table widefat fixed striped" style="margin-top: 20px; display: none;">
    <thead>
        <tr>
            <td id="cb" class="manage-column column-cb check-column">
                <input type="checkbox" id="cb-select-all-1">
            </td>
            <th scope="col" id="headline" class="manage-column column-title column-primary">
                <span>Firebase Headline</span>
            </th>
            <th scope="col" class="manage-column">Date</th>
            <th scope="col" id="status" class="manage-column column-tags">
                Status
            </th>
            <th scope="col" id="actions" class="manage-column column-comments">
                Actions
            </th>
        </tr>
    </thead>
    <tbody id="firebase-sync-table-body">
        <!-- Rows will be dynamically inserted here by JavaScript -->
    </tbody>
    <tfoot>
        <tr>
            <td class="manage-column column-cb check-column">
                <input type="checkbox" id="cb-select-all-2">
            </td>
            <th scope="col" class="manage-column column-title column-primary">
                <span>Firebase Headline</span>
            </th>
            <th scope="col" class="manage-column">Date</th>
            <th scope="col" class="manage-column column-tags">
                Status
            </th>
            <th scope="col" class="manage-column column-comments">
                Actions
            </th>
        </tr>
    </tfoot>
</table>
        <div class="tablenav bottom" style="display: none;"><div id="pagination-controls" class="tablenav-pages"></div></div>
    </div>
<template id="sync-row-template">
    <tr>
        <th scope="row" class="check-column">
            <input type="checkbox" class="row-checkbox" data-issue-id="{{issueId}}">
        </th>
        <td class="title column-title has-row-actions column-primary">
            <strong>{{headline}}</strong>
            <br>
            <small>Firebase ID: {{issueId}}</small>
        </td>
        <td class="date-cell column-date" data-colname="Date">{{date}}</td>
        <td class="status-cell column-tags" data-colname="Status">
            <span class="status-label">{{status}}</span>
        </td>
        <td class="actions-cell column-comments" data-colname="Actions">
            <button class="button button-small action-create" data-issue-id="{{issueId}}">Create Post</button>
            <span class="spinner row-spinner"></span>
        </td>
    </tr>
</template>
    <?php
}

function firebase_connector_enqueue_admin_scripts($hook_suffix) {
    if ( 'firebase-connector-tools' !== $hook_suffix && 'toplevel_page_firebase-connector-tools' !== $hook_suffix ) return;
    wp_enqueue_script('firebase-connector-sync-tool', plugin_dir_url(__FILE__) . '../js/admin-sync-tool.js', ['jquery'], '1.0.3', true);
    wp_localize_script('firebase-connector-sync-tool', 'firebase_sync_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('firebase_sync_nonce'),
        'quick_sync_nonce' => wp_create_nonce('firebase_quick_sync_nonce')
    ]);
}
add_action('admin_enqueue_scripts', 'firebase_connector_enqueue_admin_scripts');