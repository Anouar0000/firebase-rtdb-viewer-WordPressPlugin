<?php
// /includes/frontend-shortcodes.php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueues the frontend stylesheet for our shortcodes.
 */
function firebase_connector_enqueue_styles() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'firebase_issues_list' ) || has_shortcode( $post->post_content, 'firebase_single_issue' ) ) ) {
        wp_enqueue_style(
            'firebase-connector-styles',
            plugin_dir_url( __FILE__ ) . 'frontend-shortcodes.css', // Assumes CSS is in the same folder
            array(),
            '1.0.2' // Update version when you change CSS
        );
    }
}
add_action( 'wp_enqueue_scripts', 'firebase_connector_enqueue_styles' );


/**
 * ======================================================================
 * SHORTCODE: Display a grid of issues (FINAL VERSION with Title)
 * ======================================================================
 */
function firebase_issues_list_shortcode( $atts ) {
    $options = get_option( 'firebase_issues_fetcher_settings' );
    
    // ** NEW: Added 'title' attribute with a default value of "News" **
    $atts = shortcode_atts(
        array(
            'limit' => $options['limit'] ?? 10,
            'lang'  => get_locale(),
            'title' => 'News', // You can change this default to whatever you like
        ),
        $atts,
        'firebase_issues_list'
    );
    
    $lang_parts = explode( '_', $atts['lang'] );
    $lang = $lang_parts[0];
    $limit = absint( $atts['limit'] );
    $title = esc_html( $atts['title'] );
    
    // This function still fetches the list of IDs and basic info
    $issues = firebase_issues_fetcher_get_issues( $limit, $lang );

    if ( is_wp_error( $issues ) ) {
        return '<p class="fc-error-message">Error: Could not retrieve news issues. ' . esc_html($issues->get_error_message()) . '</p>';
    }

    if ( empty( $issues ) ) {
        return '<p>No news issues found for this language.</p>';
    }

    ob_start();
    ?>
    <div class="fc-issues-block">
        <?php if ( ! empty( $title ) ) : ?>
            <h2 class="fc-issues-title"><?php echo $title; ?></h2>
        <?php endif; ?>

        <div class="wpcap-grid">
            <div class="wpcap-grid-container">
                <?php foreach ( $issues as $issue ) :
                    $issue_id = esc_attr( $issue['id'] );
                    $headline = esc_html( $issue['headline'] );
                    $image_url = esc_url( $issue['image'] );
                    
                    // ** THE BIG CHANGE IS HERE **
                    // Find the WordPress post that corresponds to this Firebase issue
                    $post_id = firebase_connector_find_post_by_firebase_id( $issue_id );

                    // If we found a post, get its permalink. Otherwise, the link goes nowhere.
                    $post_link = $post_id ? get_permalink( $post_id ) : '#';
                    ?>
                    
                    <?php if ($post_id) : // Only render the item if a corresponding post exists ?>
                    <article id="post-<?php echo $issue_id; ?>" class="wpcap-post wpbf-post">
                        <div class="post-grid-inner">
                            <div class="post-grid-thumbnail">
                                <a href="<?php echo esc_url( $post_link ); ?>">
                                    <img loading="lazy" decoding="async" src="<?php echo $image_url; ?>" class="attachment-full size-full wp-post-image" alt="<?php echo $headline; ?>">
                                </a>
                            </div>
                            <div class="post-grid-text-wrap">
                                <h3 class="title">
                                    <a href="<?php echo esc_url( $post_link ); ?>"><?php echo $headline; ?></a>
                                </h3>
                            </div>
                        </div>
                    </article>
                    <?php endif; ?>

                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'firebase_issues_list', 'firebase_issues_list_shortcode' );


