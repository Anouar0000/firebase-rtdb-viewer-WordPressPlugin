<?php

if ( ! defined( 'ABSPATH' ) ) exit;

function firebase_connector_sync_issues_to_posts() {
    error_log('Firebase Sync: Automatic task started.');
    $options = get_option('firebase_connector_settings');
    $sync_limit = $options['ongoing_sync_limit'] ?? 50;
    $lang = $options['lang'] ?? 'en';
    $issues = firebase_issues_fetcher_get_issues($sync_limit, $lang); 
    if (is_wp_error($issues) || empty($issues)) return;
    
    $issues_in_reverse_order = array_reverse($issues);

    foreach ($issues_in_reverse_order as $issue_summary) {
        if (!is_array($issue_summary) || !isset($issue_summary['id'])) continue;
        
        $firebase_id = $issue_summary['id'];
        $existing_post_id = firebase_connector_find_post_by_firebase_id($firebase_id);

        if ($existing_post_id) {
            if (get_post_meta($existing_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true)) {
                // UPDATE if managed
                $issue_details = firebase_issues_fetcher_get_single_issue_details($firebase_id);
                if (is_wp_error($issue_details)) continue;
                $post_data = ['ID' => $existing_post_id, 'post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id)];
                wp_update_post($post_data);
                firebase_connector_set_featured_image($existing_post_id, $issue_details['image'], $issue_details['headline']);
            }
        } else {
            // CREATE if missing (ALWAYS publishes)
            $issue_details = firebase_issues_fetcher_get_single_issue_details($firebase_id);
            if (is_wp_error($issue_details)) continue;
            $post_data = ['post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id), 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 29, 'post_category' => [4]];
            $new_post_id = wp_insert_post($post_data);
            if ($new_post_id && !is_wp_error($new_post_id)) {
                update_post_meta($new_post_id, FIREBASE_ISSUE_ID_META_KEY, $firebase_id);
                update_post_meta($new_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
                firebase_connector_set_featured_image($new_post_id, $issue_details['image'], $issue_details['headline']);
            }
        }
    }
}
add_action( FIREBASE_CRON_HOOK, 'firebase_connector_sync_issues_to_posts' );