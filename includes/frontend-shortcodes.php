<?php
// /includes/frontend-shortcodes.php (Your Code + Infinite Scroll)

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueues the frontend stylesheet AND the new script.
 */
function firebase_connector_enqueue_styles() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'firebase_issues_list' ) ) ) {
        // Enqueue the stylesheet (your original code)
        wp_enqueue_style(
            'firebase-connector-styles',
            plugin_dir_url( __FILE__ ) . 'frontend-shortcodes.css',
            [],
            '1.2.5' // Increment version
        );
        
        // ** ADDITION: Enqueue the JavaScript for infinite scroll **
        wp_enqueue_script(
            'firebase-connector-loader',
            plugin_dir_url( __FILE__ ) . '../js/frontend-loader.js',
            ['jquery'],
            '1.1.0',
            true // Load in footer
        );
        
        // ** ADDITION: Pass data to the script **
        wp_localize_script('firebase-connector-loader', 'firebase_loader_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('firebase_load_more_nonce')
        ]);
    }
    
    // Also load CSS on single posts
    if ( is_singular('post') && get_post_meta(get_the_ID(), '_firebase_issue_id', true) ) {
        wp_enqueue_style(
            'firebase-connector-styles',
            plugin_dir_url( __FILE__ ) . 'frontend-shortcodes.css',
            [],
            '1.2.5'
        );
    }
}
add_action( 'wp_enqueue_scripts', 'firebase_connector_enqueue_styles' );


/**
 * SHORTCODE: Display a grid of issues.
 */
function firebase_issues_list_shortcode( $atts ) {
    // ** UPDATED: Use the new settings name **
    $options = get_option( 'firebase_connector_settings' );
    
    $atts = shortcode_atts([
        // ** UPDATED: Initial load is now 50, not from settings **
        'limit' => 50,
        'lang'  => $options['lang'] ?? 'en',
        'title' => 'News',
    ], $atts, 'firebase_issues_list');
    
    $issues = firebase_issues_fetcher_get_issues( absint( $atts['limit'] ), sanitize_key( $atts['lang'] ) );

    if ( is_wp_error( $issues ) ) {
        return '<p class="fc-error-message">Error: Could not retrieve news issues.</p>';
    }
    if ( empty( $issues ) ) {
        return '<p>No news issues found for this language.</p>';
    }

    ob_start();
    ?>
    <div class="fc-issues-block">
        <?php if ( ! empty( $atts['title'] ) ) : ?>
            <h2 class="fc-issues-title"><?php echo esc_html( $atts['title'] ); ?></h2>
        <?php endif; ?>

        <div class="wpcap-grid">
            <!-- ADDED ID for JavaScript to target -->
            <div class="wpcap-grid-container" id="firebase-issues-grid">
                <?php foreach ( $issues as $issue ) :
                    if ( !isset($issue['id']) ) continue;

                    $post_id = firebase_connector_find_post_by_firebase_id( $issue['id'] );
                    
                    // ADDED CHECK: Only show published posts
                    if ( ! $post_id || get_post_status($post_id) !== 'publish' ) {
                        continue;
                    }

                    $post_link = get_permalink( $post_id );
                    $headline = esc_html( $issue['headline'] );
                    $image_url = esc_url( $issue['image'] );
                    ?>
                    <article id="post-ext-<?php echo esc_attr($issue['id']); ?>" class="wpcap-post wpbf-post">
                        <div class="post-grid-inner">
                            <div class="post-grid-thumbnail">
                                <a href="<?php echo esc_url( $post_link ); ?>">
                                    <img loading="lazy" decoding="async" src="<?php echo $image_url; ?>" class="attachment-full size-full wp-post-image" alt="<?php echo $headline; ?>">
                                </a>
                            </div>
                            <div class="post-grid-text-wrap">
                                <h3 class="title"><a href="<?php echo esc_url( $post_link ); ?>"><?php echo $headline; ?></a></h3>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- ** ADDITION: The invisible trigger for infinite scroll ** -->
        <div class="firebase-load-trigger"
            data-page="1"
            data-lang="<?php echo esc_attr($atts['lang']); ?>"
            data-per-page="10">
            <div class="firebase-spinner" style="display: none; margin: 40px auto; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite;"></div>
        </div>
        <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'firebase_issues_list', 'firebase_issues_list_shortcode' );