<?php
// /includes/sync-handler.php (Corrected, Final Version for Interactive Table)

if ( ! defined( 'ABSPATH' ) ) exit;

// Define our two meta keys
define( 'FIREBASE_ISSUE_ID_META_KEY', '_firebase_issue_id' );
define( 'FIREBASE_CONNECTOR_MANAGED_KEY', '_firebase_connector_managed' );

/**
 * ======================================================================
 * HELPER FUNCTIONS
 * ======================================================================
 */


/**
 * Finds a post by its Firebase ID stored in post meta.
 */
function firebase_connector_find_post_by_firebase_id( $firebase_id ) {
    $args = ['post_type' => 'post', 'meta_key' => FIREBASE_ISSUE_ID_META_KEY, 'meta_value' => $firebase_id, 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true];
    $query = new WP_Query( $args );
    return $query->have_posts() ? $query->posts[0] : null;
}

/**
 * ======================================================================
 * AJAX HANDLERS (for the interactive tools page)
 * ======================================================================
 */

/**
 * AJAX handler for the "Scan" button. This is the new "Dry Run".
 */
function firebase_scanner_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $options = get_option('firebase_connector_settings');
    $admin_limit = $options['admin_limit'] ?? 200;
    $lang = $options['lang'] ?? 'en';
    $issues = firebase_issues_fetcher_get_issues($admin_limit, $lang);
    if (is_wp_error($issues)) { wp_send_json_error('Failed to fetch issues.'); }

    // --- BRUTE FORCE METHOD ---
    // 1. Get ALL posts from the WordPress database once.
    $all_wp_posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => -1, // -1 means get ALL of them
    ]);

    // 2. Create a clean, normalized lookup table for fast searching.
    $normalized_post_titles = [];
    foreach ($all_wp_posts as $wp_post) {
        $normalized_title = strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode($wp_post->post_title, ENT_QUOTES, 'UTF-8'))));
        $normalized_post_titles[$normalized_title] = $wp_post->ID; // Store the Post ID
    }
    // --- END OF BRUTE FORCE SETUP ---

    $status_list = [];
    foreach ($issues as $issue) {
        if (!is_array($issue) || empty($issue['id']) || empty($issue['headline'])) continue;
        
        $status = 'missing';
        $post_id_to_return = null;

        $post_by_id = firebase_connector_find_post_by_firebase_id($issue['id']);
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
            // --- BRUTE FORCE SEARCH ---
            // 3. Normalize the Firebase headline in the exact same way.
            $normalized_fb_headline = strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode($issue['headline'], ENT_QUOTES, 'UTF-8'))));
            
            // 4. Check if this normalized headline exists as a key in our lookup table.
            if (isset($normalized_post_titles[$normalized_fb_headline])) {
                $status = 'match_unlinked';
                $post_id_to_return = $normalized_post_titles[$normalized_fb_headline];
            }
        }
        
        $status_list[] = ['id' => $issue['id'], 'headline' => $issue['headline'], 'status' => $status, 'post_id' => $post_id_to_return, 'debug' => null];
    }
    wp_send_json_success($status_list);
}
add_action('wp_ajax_firebase_scan_issues', 'firebase_scanner_ajax_handler');
add_action('wp_ajax_nopriv_firebase_scan_issues', 'firebase_scanner_ajax_handler'); // Good practice

/**
 * AJAX handler for creating a single post.
 */
function firebase_processor_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');

    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    if (empty($issue_id)) {
        wp_send_json_error('No Issue ID provided.');
    }

    $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
    if (is_wp_error($issue_details)) {
        wp_send_json_error('Could not fetch issue details.');
    }

    $post_data = [
        'post_title'   => wp_strip_all_tags($issue_details['headline']),
        'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id),
        'post_status'  => 'draft',
        'post_type'    => 'post',
        'post_author'   => 29, // Replace 7 with your actual "Squirrel News" User ID
        'post_category' => array( 4 ) // Replace 5 with your actual "News" Category ID
    ];
    $new_post_id = wp_insert_post($post_data);

    if (is_wp_error($new_post_id)) {
        wp_send_json_error('Failed to create post.');
    }
    
    update_post_meta($new_post_id, FIREBASE_ISSUE_ID_META_KEY, $issue_id);
    update_post_meta($new_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true); // Stamp as managed
    firebase_connector_set_featured_image($new_post_id, $issue_details['image'], $issue_details['headline']);

    wp_send_json_success(['message' => 'Post created successfully!', 'post_id' => $new_post_id]);
}
add_action('wp_ajax_firebase_create_single_post', 'firebase_processor_ajax_handler');
add_action('wp_ajax_nopriv_firebase_create_single_post', 'firebase_processor_ajax_handler'); // Good practice


/**
 * AJAX handler for linking an existing post.
 */
function firebase_linker_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($issue_id) || empty($post_id)) { wp_send_json_error('Missing IDs.'); }
    
    update_post_meta($post_id, FIREBASE_ISSUE_ID_META_KEY, $issue_id);
    wp_send_json_success(['message' => 'Post linked!']);
}
add_action('wp_ajax_firebase_link_single_post', 'firebase_linker_ajax_handler');
add_action('wp_ajax_nopriv_firebase_link_single_post', 'firebase_linker_ajax_handler'); // Good practice


/**
 * NEW: AJAX handler for unlinking a post.
 */
function firebase_unlinker_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($post_id)) {
        wp_send_json_error('No Post ID provided.');
    }
    
    // Delete the meta keys to break the link
    delete_post_meta($post_id, FIREBASE_ISSUE_ID_META_KEY);
    delete_post_meta($post_id, FIREBASE_CONNECTOR_MANAGED_KEY);
    
    wp_send_json_success(['message' => 'Post unlinked successfully!']);
}
add_action('wp_ajax_firebase_unlink_single_post', 'firebase_unlinker_ajax_handler');
add_action('wp_ajax_nopriv_firebase_unlink_single_post', 'firebase_unlinker_ajax_handler'); // Good practice

/**
 * NEW: AJAX handler for publishing a single post.
 */
function firebase_publish_post_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    
    $post_id = absint($_POST['post_id'] ?? 0);
    if (empty($post_id) || !current_user_can('publish_post', $post_id)) {
        wp_send_json_error('Invalid Post ID or insufficient permissions.');
    }
    
    // The core of the function: update the post status to 'publish'
    $update_args = ['ID' => $post_id, 'post_status' => 'publish'];
    wp_update_post($update_args);
    
    wp_send_json_success(['message' => 'Post published successfully!']);
}
add_action('wp_ajax_firebase_publish_single_post', 'firebase_publish_post_ajax_handler');

/**
 * NEW: AJAX handler for updating (refreshing) a single managed post.
 */
function firebase_updater_ajax_handler() {
    check_ajax_referer('firebase_sync_nonce', 'nonce');
    
    $post_id = absint($_POST['post_id'] ?? 0);
    $issue_id = sanitize_text_field($_POST['issue_id'] ?? '');

    if (empty($post_id) || empty($issue_id)) {
        wp_send_json_error('Missing required IDs for updating.');
    }

    // Security check: Make sure this post is actually managed by the plugin
    $is_managed = get_post_meta($post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
    if (!$is_managed) {
        wp_send_json_error('This post is not managed by the plugin and cannot be updated.');
    }

    // Fetch the latest details from Firebase
    $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
    if (is_wp_error($issue_details)) {
        wp_send_json_error('Could not fetch latest issue details from Firebase.');
    }

    // Prepare the data for updating the post
    $post_data = [
        'ID'           => $post_id,
        'post_title'   => wp_strip_all_tags($issue_details['headline']),
        'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id),
    ];

    // Update the post
    $result = wp_update_post($post_data, true); // Pass true to get error info

    if (is_wp_error($result)) {
        wp_send_json_error('Failed to update the post in the database.');
    }

    // Also update the featured image
    firebase_connector_set_featured_image($post_id, $issue_details['image'], $issue_details['headline']);

    wp_send_json_success(['message' => 'Post updated successfully!']);
}
add_action('wp_ajax_firebase_update_single_post', 'firebase_updater_ajax_handler');

/**
 * ======================================================================
 * AUTOMATIC SYNC FUNCTION (for WP-Cron)
 * ======================================================================
 */

/**
 * Main sync function for ONGOING, day-to-day updates.
 */
function firebase_connector_sync_issues_to_posts() {
    error_log('Firebase Sync: Automatic task started.');
    $options = get_option('firebase_connector_settings');
    $sync_limit = $options['ongoing_sync_limit'] ?? 50;
    $lang = $options['lang'] ?? 'en';

    $issues = firebase_issues_fetcher_get_issues($sync_limit, $lang); 
    if (is_wp_error($issues) || empty($issues)) {
        error_log('Firebase Sync: No issues found or API error.');
        return;
    }

    $created = 0; $updated = 0; $skipped = 0;

    foreach ($issues as $issue_summary) {
        if (!is_array($issue_summary) || !isset($issue_summary['id'])) continue;
        
        $firebase_id = $issue_summary['id'];
        $existing_post_id = firebase_connector_find_post_by_firebase_id($firebase_id);

        if ($existing_post_id) {
            $is_managed = get_post_meta($existing_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
            if ($is_managed) {
                // UPDATE if managed
                $issue_details = firebase_issues_fetcher_get_single_issue_details($firebase_id);
                if (is_wp_error($issue_details)) continue;
                $post_data = 
                [ 
                    'ID' => $existing_post_id,
                    'post_title' => wp_strip_all_tags($issue_details['headline']), 
                    'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id) 
                ];
                wp_update_post($post_data);
                firebase_connector_set_featured_image($existing_post_id, $issue_details['image'], $issue_details['headline']);
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            // CREATE if missing
            $issue_details = firebase_issues_fetcher_get_single_issue_details($firebase_id);
            if (is_wp_error($issue_details)) continue;
            $post_data = 
            [ 
                'post_title' => wp_strip_all_tags($issue_details['headline']), 
                'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id), 
                'post_status'  => 'draft', 
                'post_type' => 'post',
                'post_author'   => 29,
                'post_category' => array( 4 )
            ];
            $new_post_id = wp_insert_post($post_data);
            if ($new_post_id && !is_wp_error($new_post_id)) {
                update_post_meta($new_post_id, FIREBASE_ISSUE_ID_META_KEY, $firebase_id);
                update_post_meta($new_post_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
                firebase_connector_set_featured_image($new_post_id, $issue_details['image'], $issue_details['headline']);
                $created++;
            }
        }
    }
    error_log("Firebase Sync: Finished. Created:{$created}, Updated:{$updated}, Skipped (manual posts):{$skipped}.");
}
// Note: The hook to trigger this is now in the main plugin file
// add_action( FIREBASE_CRON_HOOK, 'firebase_connector_sync_issues_to_posts' );

/**
 * ======================================================================
 * CONTENT GENERATION FUNCTIONS
 * ======================================================================
 */
// in /includes/sync-handler.php

function firebase_connector_generate_post_content( $issue, $issue_id ) {
    $main_image_credit = isset( $issue['imageCredit'] ) ? esc_html( $issue['imageCredit'] ) : '';
    $teaser = isset( $issue['teaser'] ) ? wp_kses_post( $issue['teaser'] ) : '';
    
    ob_start();
    ?>
    <div class="firebase-post-content-wrapper">

        <?php if ( ! empty( $main_image_credit ) ) : ?><p class="featured-img-caption">Photo: <?php echo $main_image_credit; ?></p><?php endif; ?>
        <?php if ( ! empty( $teaser ) ) : ?><p class="vorspann"><?php echo $teaser; ?></p><?php endif; ?>
        
        <?php if ( ! empty( $issue['articles'] ) && is_array( $issue['articles'] ) ) :
            $articles = $issue['articles'];
            usort($articles, function($a, $b) { return ($a['position'] ?? 999) <=> ($b['position'] ?? 999); });
            
            // --- START OF CHANGES ---
            
            // 1. Initialize a counter for the loop
            $article_counter = 0;

            foreach ( $articles as $article ) :
                // 2. Increment the counter at the start of each loop
                $article_counter++;

                $article_url = isset( $article['url'] ) ? esc_url( $article['url'] ) : '#';
                $article_image_url = isset( $article['imageUrl'] ) ? esc_url( $article['imageUrl'] ) : '';
                $article_title = isset( $article['title'] ) ? esc_html( $article['title'] ) : 'No Title';
                $article_teaser = isset( $article['teaser'] ) ? wp_kses_post( $article['teaser'] ) : ''; // Use wp_kses_post for teasers
                $article_source = isset( $article['source'] ) ? esc_html( $article['source'] ) : 'Unknown Source';
                $article_credit = isset( $article['credit'] ) ? esc_html( $article['credit'] ) : '';
        ?>
        <div class="wp-block-group" style="margin-bottom:30px;"><div class="wp-block-group__inner-container"><div class="wp-block-columns is-layout-flex"><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><figure class="wp-block-image size-large"><img decoding="async" loading="lazy" src="<?php echo $article_image_url; ?>" class="news-teaser-img" alt="<?php echo esc_attr( $article_title ); ?>"><?php if ( ! empty( $article_credit ) ) : ?><figcaption class="teaser-caption">Photo: <?php echo $article_credit; ?></figcaption><?php endif; ?></figure></a></div><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><h2 class="wp-block-heading"><?php echo $article_title; ?></h2></a><p class="news-teaser"><?php echo $article_teaser; ?></p><p><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">Source: <?php echo $article_source; ?></a></p></div></div></div></div>
        
        <?php
                // 3. Check if the counter is at 5
                if ( $article_counter === 5 ) :
        ?>
        
        <!-- This is your custom HTML block that will be inserted after the 5th article -->
        <div class="ml-form-embed nl-cta"
            data-account="1712162:v1f8q9v0s8"
            data-form="3345723:b6d6q7">
        </div>

        <?php
                endif; // End the check for the 5th article
            
            endforeach; // End the main foreach loop

            // --- END OF CHANGES ---

        endif; 
        ?>
                <!-- wp:shortcode -->
        [Sassy_Social_Share]
        <!-- /wp:shortcode -->

        <!-- wp:spacer {"height":40} -->
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- /wp:spacer -->

        <!-- wp:block {"ref":2224} /-->

        <!-- wp:spacer {"height":40} -->
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- /wp:spacer -->

        <!-- wp:block {"ref":515} /-->
    </div> <!-- End of firebase-post-content-wrapper -->
    <?php
    return ob_get_clean();
}

function firebase_connector_set_featured_image( $post_id, $image_url, $post_title ) {
    if ( empty( $image_url ) ) return;
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attachment_id = media_sideload_image( $image_url, $post_id, $post_title, 'id' );
    if ( ! is_wp_error( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
    }
}