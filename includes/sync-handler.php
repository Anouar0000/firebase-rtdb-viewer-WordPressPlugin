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

function firebase_connector_find_post_by_title_flexibly( $title ) {
    $query_args = [
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        's'              => $title, // 's' uses the flexible WordPress search logic
    ];
    $query = new WP_Query( $query_args );
    // After a search, we must double-check that the title is an exact match to avoid partial matches
    if ( $query->have_posts() ) {
        $found_post = $query->posts[0];
        // Compare titles after decoding HTML entities to handle quote differences
        if ( html_entity_decode( $found_post->post_title, ENT_QUOTES ) === html_entity_decode( $title, ENT_QUOTES ) ) {
            return $found_post;
        }
    }
    return null;
}
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
    $issues = firebase_issues_fetcher_get_issues($admin_limit, 'en');
    if (is_wp_error($issues)) { wp_send_json_error('Failed to fetch issues from Firebase.'); }

    $status_list = [];
    foreach ($issues as $issue) {
        if (!is_array($issue) || !isset($issue['id']) || !isset($issue['headline'])) continue;
        
        $status = 'missing';
        $post_id_to_return = null; // Use a single variable for the post ID

        $post_by_id = firebase_connector_find_post_by_firebase_id($issue['id']);
        if ($post_by_id) {
            $is_managed = get_post_meta($post_by_id, FIREBASE_CONNECTOR_MANAGED_KEY, true);
            $status = $is_managed ? 'synced_managed' : 'synced_manual';
            $post_id_to_return = $post_by_id; // ** THIS IS THE CHANGE **
        } else {
            $post_by_title = firebase_connector_find_post_by_title_flexibly($issue['headline']);
            if ($post_by_title) {
                $status = 'match_unlinked';
                $post_id_to_return = $post_by_title->ID; // ** THIS IS THE CHANGE **
            }
        }
        
        $status_list[] = [
            'id'       => $issue['id'],
            'headline' => $issue['headline'],
            'status'   => $status,
            'post_id'  => $post_id_to_return // Now it's always included if a post is found
        ];
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

    $options = get_option('firebase_connector_settings');
    $create_as_draft = !isset($options['create_as_draft']) || $options['create_as_draft'] == 1;

    $issue_details = firebase_issues_fetcher_get_single_issue_details($issue_id);
    if (is_wp_error($issue_details)) {
        wp_send_json_error('Could not fetch issue details.');
    }

    $post_data = [
        'post_title'   => wp_strip_all_tags($issue_details['headline']),
        'post_content' => firebase_connector_generate_post_content($issue_details, $issue_id),
        'post_status'  => $create_as_draft ? 'draft' : 'publish',
        'post_type'    => 'post',
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
    $create_as_draft = !isset($options['create_as_draft']) || $options['create_as_draft'] == 1;
    $sync_limit = $options['ongoing_sync_limit'] ?? 50;

    $issues = firebase_issues_fetcher_get_issues($sync_limit, 'en'); 
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
                $post_data = [ 'ID' => $existing_post_id, 'post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id) ];
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
            $post_data = [ 'post_title' => wp_strip_all_tags($issue_details['headline']), 'post_content' => firebase_connector_generate_post_content($issue_details, $firebase_id), 'post_status'  => $create_as_draft ? 'draft' : 'publish', 'post_type' => 'post' ];
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
function firebase_connector_generate_post_content( $issue, $issue_id ) {
    $main_image_credit = isset( $issue['imageCredit'] ) ? esc_html( $issue['imageCredit'] ) : '';
    $teaser = isset( $issue['teaser'] ) ? wp_kses_post( $issue['teaser'] ) : '';
    
    ob_start();
    ?>
    
    <!-- NEW WRAPPER DIV with a unique class -->
    <div class="firebase-post-content-wrapper">

        <?php if ( ! empty( $main_image_credit ) ) : ?><p class="featured-img-caption">Photo: <?php echo $main_image_credit; ?></p><?php endif; ?>
        <?php if ( ! empty( $teaser ) ) : ?><p class="vorspann"><?php echo $teaser; ?></p><?php endif; ?>
        
        <?php if ( ! empty( $issue['articles'] ) && is_array( $issue['articles'] ) ) :
            $articles = $issue['articles'];
            usort($articles, function($a, $b) { return ($a['position'] ?? 999) <=> ($b['position'] ?? 999); });
            foreach ( $articles as $article ) :
                // ... all the variable definitions are the same ...
                $article_url = isset( $article['url'] ) ? esc_url( $article['url'] ) : '#';
                $article_image_url = isset( $article['imageUrl'] ) ? esc_url( $article['imageUrl'] ) : '';
                $article_title = isset( $article['title'] ) ? esc_html( $article['title'] ) : 'No Title';
                $article_teaser = isset( $article['teaser'] ) ? esc_html( $article['teaser'] ) : '';
                $article_source = isset( $article['source'] ) ? esc_html( $article['source'] ) : 'Unknown Source';
                $article_credit = isset( $article['credit'] ) ? esc_html( $article['credit'] ) : '';
        ?>
        <div class="wp-block-group" style="margin-bottom:30px;"><div class="wp-block-group__inner-container"><div class="wp-block-columns is-layout-flex"><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><figure class="wp-block-image size-large"><img decoding="async" loading="lazy" src="<?php echo $article_image_url; ?>" class="news-teaser-img" alt="<?php echo esc_attr( $article_title ); ?>"><?php if ( ! empty( $article_credit ) ) : ?><figcaption class="teaser-caption">Photo: <?php echo $article_credit; ?></figcaption><?php endif; ?></figure></a></div><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><h2 class="wp-block-heading"><?php echo $article_title; ?></h2></a><p class="news-teaser"><?php echo $article_teaser; ?></p><p><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">Source: <?php echo $article_source; ?></a></p></div></div></div></div>
        <?php endforeach; else : echo '<p>No articles found for this issue.</p>'; endif; ?>

    </div> <!-- End of new wrapper div -->

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