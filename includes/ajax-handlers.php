<?php

if ( ! defined( 'ABSPATH' ) ) exit;

function firebase_scanner_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $options = get_option('firebase_connector_settings');
    $admin_limit = absint($options['admin_limit'] ?? 200);
    $lang = $options['lang'] ?? 'en';
    $page_token = sanitize_text_field($_POST['page_token'] ?? '');
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to = sanitize_text_field($_POST['date_to'] ?? '');
    $requested_limit = absint($_POST['scan_limit'] ?? 50);
    $scan_limit = max(1, min($requested_limit, $admin_limit, 100));

    if ($date_from !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date_from)) {
        wp_send_json_error('Invalid start date.');
    }
    if ($date_to !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date_to)) {
        wp_send_json_error('Invalid end date.');
    }
    if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
        wp_send_json_error('Start date must be before end date.');
    }

    $issues_page = firebase_issues_fetcher_get_issues_page($scan_limit, $lang, $page_token, $date_from, $date_to);
    if (is_wp_error($issues_page)) { wp_send_json_error('Failed to fetch issues.'); }

    $issues = $issues_page['items'] ?? [];
    $firebase_issue_ids = [];
    foreach ($issues as $issue) {
        if (is_array($issue) && !empty($issue['id'])) {
            $firebase_issue_ids[] = $issue['id'];
        }
    }
    $posts_by_firebase_id = firebase_connector_find_posts_by_firebase_ids($firebase_issue_ids);

    $all_wp_posts = get_posts(['post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1]);
    $normalized_post_titles = [];
    foreach ($all_wp_posts as $wp_post) {
        $normalized_title = strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode($wp_post->post_title, ENT_QUOTES, 'UTF-8'))));
        $normalized_post_titles[$normalized_title] = $wp_post->ID;
    }

    $status_list = [];
    foreach ($issues as $issue) {
        if (!is_array($issue) || empty($issue['id']) || empty($issue['headline'])) continue;
        $status = 'missing';
        $post_id_to_return = null;
        $post_by_id = $posts_by_firebase_id[$issue['id']] ?? null;
        if ($post_by_id) {
            $post_status = get_post_status($post_by_id);
            if ($post_status === 'draft') {
                $status = 'draft_managed';
            } else {
                $is_managed = get_post_meta($post_by_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
                $status = $is_managed ? 'synced_managed' : 'synced_manual';
            }
            $post_id_to_return = $post_by_id;
        } else {
            $normalized_fb_headline = strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode($issue['headline'], ENT_QUOTES, 'UTF-8'))));
            if (isset($normalized_post_titles[$normalized_fb_headline])) {
                $status = 'match_unlinked';
                $post_id_to_return = $normalized_post_titles[$normalized_fb_headline];
            }
        }
        $date_formatted = 'N/A';
        if ( ! empty($issue['title']) && is_string($issue['title']) ) {
            if ( preg_match('/(\d{1,2})[\/.](\d{1,2})[\.\/](\d{4})/', $issue['title'], $matches) ) {
                $date_formatted = $matches[3] . '/' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '/' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            }
        }
        $status_list[] = [
            'id' => $issue['id'],
            'headline' => $issue['headline'],
            'status' => $status,
            'post_id' => $post_id_to_return,
            'date' => $date_formatted,
            'image_status' => firebase_connector_get_image_health_summary($post_id_to_return)
        ];
    }
    wp_send_json_success([
        'items' => $status_list,
        'next_page_token' => $issues_page['next_page_token'] ?? '',
        'has_more' => !empty($issues_page['has_more']),
    ]);
}
add_action('wp_ajax_firebase_scan_issues', 'firebase_scanner_ajax_handler');

function firebase_processor_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    if (empty($issue_id)) { wp_send_json_error('No Issue ID provided.'); }
    $options = get_option('firebase_connector_settings');
    $post_category = firebase_connector_get_post_category_for_language($options['lang'] ?? 'en');
    $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
    if (is_wp_error($issue_details)) { wp_send_json_error('Could not fetch issue details.'); }
    $post_data = [
        'post_title'   => wp_strip_all_tags($issue_details['headline']),
        'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id),
        'post_status'  => 'draft', 'post_type' => 'post', 'post_author'  => FIREBASE_CONNECTOR_POST_AUTHOR_ID, 'post_category' => $post_category
    ];
    $new_post_id = wp_insert_post($post_data, true);
    if (is_wp_error($new_post_id)) { wp_send_json_error('Failed to create post: ' . $new_post_id->get_error_message()); }
    update_post_meta($new_post_id, FIREBASE_ISSUE_ID_META_KEY, $issue_id);
    update_post_meta($new_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
    firebase_connector_set_featured_image($new_post_id, $issue_details['image'], $issue_details['headline']);
    wp_send_json_success(['message' => 'Post created successfully!', 'post_id' => $new_post_id]);
}
add_action('wp_ajax_firebase_create_single_post', 'firebase_processor_ajax_handler');

function firebase_linker_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($issue_id) || empty($post_id)) { wp_send_json_error('Missing IDs.'); }
    update_post_meta($post_id, FIREBASE_ISSUE_ID_META_KEY, $issue_id);
    wp_send_json_success(['message' => 'Post linked!']);
}
add_action('wp_ajax_firebase_link_single_post', 'firebase_linker_ajax_handler');

function firebase_unlinker_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($post_id)) { wp_send_json_error('No Post ID provided.'); }
    delete_post_meta($post_id, FIREBASE_ISSUE_ID_META_KEY);
    delete_post_meta($post_id, FIREBASE_CONNECTOR_MANAGED_KEY);
    wp_send_json_success(['message' => 'Post unlinked successfully!']);
}
add_action('wp_ajax_firebase_unlink_single_post', 'firebase_unlinker_ajax_handler');

function firebase_publish_post_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($post_id) || !current_user_can('publish_post', $post_id)) { wp_send_json_error('Invalid Post ID or permissions.'); }
    wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
    wp_send_json_success(['message' => 'Post published successfully!']);
}
add_action('wp_ajax_firebase_publish_single_post', 'firebase_publish_post_ajax_handler');

function firebase_updater_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    if (empty($post_id) || empty($issue_id)) { wp_send_json_error('Missing IDs for updating.'); }
    if (!get_post_meta($post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true)) { wp_send_json_error('This post is not managed by the plugin.'); }
    $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
    if (is_wp_error($issue_details)) { wp_send_json_error('Could not fetch latest issue details.'); }
    $post_data = ['ID' => $post_id, 'post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id)];
    if (is_wp_error(wp_update_post($post_data, true))) { wp_send_json_error('Failed to update the post.'); }
    firebase_connector_set_featured_image($post_id, $issue_details['image'], $issue_details['headline']);
    wp_send_json_success(['message' => 'Post updated successfully!']);
}
add_action('wp_ajax_firebase_update_single_post', 'firebase_updater_ajax_handler');


function firebase_image_health_ajax_context() {
    $post_id = absint($_POST['post_id'] ?? 0);
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');

    if (empty($post_id)) {
        wp_send_json_error('No Post ID provided.');
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('You do not have permission to edit this post.');
    }
    if (empty($issue_id)) {
        $issue_id = get_post_meta($post_id, FIREBASE_ISSUE_ID_META_KEY, true);
    }
    if (empty($issue_id)) {
        wp_send_json_error('No Firebase issue ID found for this post.');
    }

    return [$post_id, $issue_id];
}

function firebase_check_post_images_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    list($post_id, $issue_id) = firebase_image_health_ajax_context();

    $health = firebase_connector_build_image_health($post_id, $issue_id);
    if (is_wp_error($health)) {
        wp_send_json_error($health->get_error_message());
    }

    wp_send_json_success([
        'message' => 'Image check complete.',
        'image_status' => $health,
    ]);
}
add_action('wp_ajax_firebase_check_post_images', 'firebase_check_post_images_ajax_handler');

function firebase_fix_post_images_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    list($post_id, $issue_id) = firebase_image_health_ajax_context();

    $health = firebase_connector_fix_post_article_images($post_id, $issue_id);
    if (is_wp_error($health)) {
        wp_send_json_error($health->get_error_message());
    }

    wp_send_json_success([
        'message' => 'Image fix complete.',
        'image_status' => $health,
    ]);
}
add_action('wp_ajax_firebase_fix_post_images', 'firebase_fix_post_images_ajax_handler');

function firebase_mark_post_images_reviewed_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);

    if (empty($post_id)) {
        wp_send_json_error('No Post ID provided.');
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('You do not have permission to edit this post.');
    }

    $health = firebase_connector_mark_post_images_reviewed($post_id);
    wp_send_json_success([
        'message' => 'Image status marked reviewed.',
        'image_status' => $health,
    ]);
}
add_action('wp_ajax_firebase_mark_post_images_reviewed', 'firebase_mark_post_images_reviewed_ajax_handler');
function firebase_quick_sync_preflight_handler() {
    check_ajax_referer('firebase_quick_sync_nonce', 'nonce');
    $options = get_option('firebase_connector_settings');
    $sync_limit = $options['ongoing_sync_limit'] ?? 50;
    $lang = $options['lang'] ?? 'en';
    $issues = firebase_issues_fetcher_get_issues($sync_limit, $lang); 
    if (is_wp_error($issues) || empty($issues)) { wp_send_json_error('No recent issues found to sync.'); }
    $issues_in_reverse_order = array_reverse($issues);
    $issue_ids = wp_list_pluck($issues_in_reverse_order, 'id');
    wp_send_json_success(['issue_ids' => $issue_ids]);
}
add_action('wp_ajax_firebase_quick_sync_preflight', 'firebase_quick_sync_preflight_handler');

function firebase_quick_sync_process_single_handler() {
    check_ajax_referer('firebase_quick_sync_nonce', 'nonce');
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    if (empty($issue_id)) { wp_send_json_error('No issue ID provided.'); }
    $existing_post_id = firebase_connector_find_post_by_firebase_id($issue_id);
    if ($existing_post_id) {
        if (get_post_meta($existing_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true)) {
            $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
            if (is_wp_error($issue_details)) { wp_send_json_error('Could not fetch details for update.'); }
            $post_data = ['ID' => $existing_post_id, 'post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id)];
            wp_update_post($post_data);
            firebase_connector_set_featured_image($existing_post_id, $issue_details['image'], $issue_details['headline']);
            wp_send_json_success('updated');
        } else {
            wp_send_json_success('skipped');
        }
    } else {
        $options = get_option('firebase_connector_settings');
        $post_category = firebase_connector_get_post_category_for_language($options['lang'] ?? 'en');
        $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
        if (is_wp_error($issue_details)) { wp_send_json_error('Could not fetch details for creation.'); }
        $post_data = ['post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id), 
        'post_status' => 'publish', 'post_type' => 'post', 'post_author' => FIREBASE_CONNECTOR_POST_AUTHOR_ID, 'post_category' => $post_category];
        $new_post_id = wp_insert_post($post_data);
        if ($new_post_id && !is_wp_error($new_post_id)) {
            update_post_meta($new_post_id, FIREBASE_ISSUE_ID_META_KEY, $issue_id);
            update_post_meta($new_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
            firebase_connector_set_featured_image($new_post_id, $issue_details['image'], $issue_details['headline']);
            wp_send_json_success('created');
        } else {
            wp_send_json_error('Failed to create post.');
        }
    }
}
add_action('wp_ajax_firebase_quick_sync_process_single', 'firebase_quick_sync_process_single_handler');
