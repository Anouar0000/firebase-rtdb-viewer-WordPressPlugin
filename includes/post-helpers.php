<?php

if ( ! defined( 'ABSPATH' ) ) exit;

// --- Helper Functions ---

function firebase_connector_find_post_by_firebase_id( $firebase_id ) {
    $args = ['post_type' => 'post', 'meta_key' => FIREBASE_ISSUE_ID_META_KEY, 'meta_value' => $firebase_id, 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true];
    $query = new WP_Query($args);
    return $query->have_posts() ? $query->posts[0] : null;
}

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
            $article_counter = 0;
            foreach ( $articles as $article ) :
                $article_counter++;
                $article_url = esc_url( $article['url'] ?? '#' );
                $article_image_url = esc_url( $article['imageUrl'] ?? '' );
                $article_title = esc_html( $article['title'] ?? 'No Title' );
                $article_teaser = wp_kses_post( $article['teaser'] ?? '' );
                $article_source = esc_html( $article['source'] ?? 'Unknown Source' );
                $article_credit = esc_html( $article['credit'] ?? '' );
        ?>
        <div class="wp-block-group" style="margin-bottom:30px;"><div class="wp-block-group__inner-container"><div class="wp-block-columns is-layout-flex"><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><figure class="wp-block-image size-large"><img decoding="async" loading="lazy" src="<?php echo $article_image_url; ?>" class="news-teaser-img" alt="<?php echo esc_attr( $article_title ); ?>"><?php if ( ! empty( $article_credit ) ) : ?><figcaption class="teaser-caption">Photo: <?php echo $article_credit; ?></figcaption><?php endif; ?></figure></a></div><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><h2 class="wp-block-heading"><?php echo $article_title; ?></h2></a><p class="news-teaser"><?php echo $article_teaser; ?></p><p><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener">Source: <?php echo $article_source; ?></a></p></div></div></div></div>
        <?php
                if ( $article_counter === 5 ) :
        ?>
        <div class="ml-form-embed nl-cta" data-account="1712162:v1f8q9v0s8" data-form="3345723:b6d6q7"></div>
        <?php
                endif;
            endforeach;
        endif; 
        ?>
        [Sassy_Social_Share]
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- wp:block {"ref":2224} /-->
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- wp:block {"ref":515} /-->
    </div>
    <?php
    return ob_get_clean();
}

function firebase_connector_set_featured_image( $post_id, $image_url, $post_title ) {
    if ( empty( $image_url ) ) return;
    $existing_image_url = get_post_meta( $post_id, FIREBASE_IMAGE_URL_META_KEY, true );
    if ( $image_url === $existing_image_url ) return;
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    add_filter( 'http_request_timeout', function() { return 30; } );
    $attachment_id = media_sideload_image( $image_url, $post_id, $post_title, 'id' );
    remove_filter( 'http_request_timeout', function() { return 30; } );
    if ( ! is_wp_error( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $post_id, FIREBASE_IMAGE_URL_META_KEY, $image_url );
    } else {
        error_log("Firebase Connector: Failed to sideload image for post {$post_id}. URL: {$image_url}. Error: " . $attachment_id->get_error_message());
    }
}