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
    $title = esc_html( $atts['title'] ); // Securely get the title
    
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
        
        <?php // ** NEW: Conditionally display the title ** ?>
        <?php if ( ! empty( $title ) ) : ?>
            <h2 class="fc-issues-title"><?php echo $title; ?></h2>
        <?php endif; ?>

        <div class="wpcap-grid">
            <div class="wpcap-grid-container">
                <?php foreach ( $issues as $issue ) :
                    $issue_id = esc_attr( $issue['id'] );
                    $headline = esc_html( $issue['headline'] );
                    $image_url = esc_url( $issue['image'] );
                    $single_issue_link = firebase_issues_fetcher_get_single_issue_url( $issue_id, $lang );
                    ?>
                    
                    <article id="post-<?php echo $issue_id; ?>" class="wpcap-post wpbf-post">
                        <div class="post-grid-inner">
                            <div class="post-grid-thumbnail">
                                <a href="<?php echo $single_issue_link; ?>">
                                    <img loading="lazy" decoding="async" src="<?php echo $image_url; ?>" class="attachment-full size-full wp-post-image" alt="<?php echo $headline; ?>">
                                </a>
                            </div>
                            <div class="post-grid-text-wrap">
                                <h3 class="title">
                                    <a href="<?php echo $single_issue_link; ?>"><?php echo $headline; ?></a>
                                </h3>
                            </div>
                        </div><!-- .post-grid-inner -->
                    </article>

                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode( 'firebase_issues_list', 'firebase_issues_list_shortcode' );


/**
 * ======================================================================
 * SHORTCODE: Display a single issue's details (FINAL - MATCHING THEME)
 * ======================================================================
 */
function firebase_single_issue_shortcode() {
    // ... (all the data fetching code at the top remains the same) ...
    $issue_id = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
    if ( empty( $issue_id ) ) { return '<p class="fc-error-message">Error: No issue ID provided.</p>'; }
    $issue = firebase_issues_fetcher_get_single_issue_details( $issue_id );
    if ( is_wp_error( $issue ) ) { return '<p class="fc-error-message">Error: Could not retrieve issue details. ' . esc_html($issue->get_error_message()) . '</p>'; }
    $headline = isset( $issue['headline'] ) ? esc_html( $issue['headline'] ) : 'No Headline';
    $main_image_url = isset( $issue['image'] ) ? esc_url( $issue['image'] ) : '';
    $main_image_credit = isset( $issue['imageCredit'] ) ? esc_html( $issue['imageCredit'] ) : '';
    $author = isset( $issue['author'] ) ? esc_html( $issue['author'] ) : 'Squirrel News Team';
    $teaser = isset( $issue['teaser'] ) ? wp_kses_post( $issue['teaser'] ) : '';
    $published_at_raw = isset( $issue['publishedAt'] ) ? $issue['publishedAt'] : time();
    $published_at_timestamp = null;
    if (is_array($published_at_raw) && isset($published_at_raw['_seconds'])) { $published_at_timestamp = $published_at_raw['_seconds']; } elseif (is_numeric($published_at_raw)) { $published_at_timestamp = $published_at_raw; } elseif (is_string($published_at_raw)) { $published_at_timestamp = strtotime($published_at_raw); }
    $date_formatted = ($published_at_timestamp) ? wp_date(get_option('date_format'), $published_at_timestamp) : 'Invalid Date';

    ob_start();
    ?>
    <div class="firebase-shortcode-wrapper">
        <article id="post-<?php echo esc_attr( $issue_id ); ?>" class="wpbf-post-layout-default wpbf-post-style-plain wpbf-post">
            <div class="wpbf-article-wrapper">
                <header class="article-header">
                    <!-- ... Header code (h1, meta, image) is unchanged ... -->
                    <h1 class="entry-title" itemprop="headline"><?php echo $headline; ?></h1>
                    <p class="article-meta">
                        <span class="article-author author vcard"><span itemprop="name"><?php echo $author; ?></span></span>
                        <span class="article-meta-separator"> | </span>
                        <span class="posted-on">Posted on</span>
                        <time class="article-time published" datetime="<?php echo esc_attr( date( 'c', $published_at_timestamp ) ); ?>" itemprop="datePublished"><?php echo $date_formatted; ?></time>
                    </p>
                    <?php if ( ! empty( $main_image_url ) ) : ?>
                        <div class="wpbf-post-image-wrapper"><img loading="lazy" src="<?php echo $main_image_url; ?>" class="wpbf-post-image wp-post-image" alt="<?php echo esc_attr($headline); ?>" itemprop="image" decoding="async"></div>
                    <?php endif; ?>
                </header>
                <section class="entry-content article-content" itemprop="text">
                    <?php if ( ! empty( $main_image_credit ) ) : ?><p class="featured-img-caption">Photo: <?php echo $main_image_credit; ?></p><?php endif; ?>
                    <?php if ( ! empty( $teaser ) ) : ?><p class="vorspann"><?php echo $teaser; ?></p><?php endif; ?>
                    <?php if ( ! empty( $issue['articles'] ) && is_array( $issue['articles'] ) ) :
                        $articles = $issue['articles'];
                        usort($articles, function($a, $b) { return ($a['position'] ?? 999) <=> ($b['position'] ?? 999); });
                        foreach ( $articles as $article ) :
                            $article_url = isset( $article['url'] ) ? esc_url( $article['url'] ) : '#';
                            $article_image_url = isset( $article['imageUrl'] ) ? esc_url( $article['imageUrl'] ) : '';
                            $article_title = isset( $article['title'] ) ? esc_html( $article['title'] ) : 'No Title';
                            $article_teaser = isset( $article['teaser'] ) ? esc_html( $article['teaser'] ) : '';
                            $article_source = isset( $article['source'] ) ? esc_html( $article['source'] ) : 'Unknown Source';
                            $article_credit = isset( $article['credit'] ) ? esc_html( $article['credit'] ) : '';
                    ?>
                    <div class="wp-block-group" style="margin-bottom:30px;">
                        <div class="wp-block-group__inner-container">
                            <div class="wp-block-columns is-layout-flex">
                                
                                <!-- NO INLINE STYLE HERE -->
                                <div class="wp-block-column">
                                    <a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">
                                        <figure class="wp-block-image size-large">
                                            <img decoding="async" src="<?php echo $article_image_url; ?>" class="news-teaser-img" alt="<?php echo esc_attr( $article_title ); ?>">
                                            <?php if ( ! empty( $article_credit ) ) : ?><figcaption class="teaser-caption">Photo: <?php echo $article_credit; ?></figcaption><?php endif; ?>
                                        </figure>
                                    </a>
                                </div>

                                <!-- NO INLINE STYLE HERE -->
                                <div class="wp-block-column">
                                    <a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">
                                        <h2 class="wp-block-heading"><?php echo $article_title; ?></h2>
                                        <p class="news-teaser"><?php echo $article_teaser; ?></p>
                                    </a>
                                    <p><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">Source: <?php echo $article_source; ?></a></p>
                                </div>

                            </div>
                        </div>
                    </div>
                    <?php endforeach; else : echo '<p>No articles found for this issue.</p>'; endif; ?>
                </section>
            </div>
        </article>
    </div>
    <?php
    return ob_get_clean();
}
// Make sure this line is still there!
add_shortcode( 'firebase_single_issue', 'firebase_single_issue_shortcode' );