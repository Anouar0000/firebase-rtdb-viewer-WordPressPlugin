<?php

if ( ! defined( 'ABSPATH' ) ) exit;

// Define the meta key for the image URL to prevent re-downloads
if ( ! defined( 'FIREBASE_IMAGE_URL_META_KEY' ) ) {
    define( 'FIREBASE_IMAGE_URL_META_KEY', '_firebase_image_source_url' );
}

// --- Helper Functions ---

function firebase_connector_find_post_by_firebase_id( $firebase_id ) {
    $args = ['post_type' => 'post', 'meta_key' => FIREBASE_ISSUE_ID_META_KEY, 'meta_value' => $firebase_id, 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true];
    $query = new WP_Query($args);
    return $query->have_posts() ? $query->posts[0] : null;
}

function firebase_connector_generate_post_content( $issue, $issue_id ) {
    // 1. Language-aware logic
    $options = get_option('firebase_connector_settings');
    $current_lang = $options['lang'] ?? 'en';

    $translation_strings = [
        'photo'  => ($current_lang === 'de') ? 'Foto' : 'Photo',
        'source' => ($current_lang === 'de') ? 'Quelle' : 'Source',
    ];

    // 2. Different MailerLite form IDs based on language
    $mailerlite_form_id = ($current_lang === 'de') ? '3034691:n9b6n1' : '3345723:b6d6q7';

    // 3. Different reusable block IDs based on language
    $reusable_block_1_id = ($current_lang === 'de') ? '3174' : '2224';
    $reusable_block_2_id = ($current_lang === 'de') ? '1423' : '515';


    // Get standard issue data
    $main_image_credit = isset( $issue['imageCredit'] ) ? esc_html( $issue['imageCredit'] ) : '';
    $teaser = isset( $issue['teaser'] ) ? wp_kses_post( $issue['teaser'] ) : '';
    
    ob_start();
    ?>
    <div class="firebase-post-content-wrapper">

        <?php if ( ! empty( $main_image_credit ) ) : ?>
            <p class="featured-img-caption"><?php echo esc_html($translation_strings['photo']); ?>: <?php echo $main_image_credit; ?></p>
        <?php endif; ?>

        <?php if ( ! empty( $teaser ) ) : ?><p class="vorspann"><?php echo $teaser; ?></p><?php endif; ?>
        
        <?php if ( ! empty( $issue['articles'] ) && is_array( $issue['articles'] ) ) :
            $articles = $issue['articles'];
            usort($articles, function($a, $b) { return ($a['position'] ?? 999) <=> ($b['position'] ?? 999); });
            
            $article_counter = 0;
            $total_articles = count($articles);

            foreach ( $articles as $article ) :
                $article_counter++;
                $article_url = esc_url( $article['url'] ?? '#' );
                $article_image_url = esc_url( $article['imageUrl'] ?? '' );
                $article_title = esc_html( $article['title'] ?? 'No Title' );
                $article_teaser = wp_kses_post( $article['teaser'] ?? '' );
                $article_source = esc_html( $article['source'] ?? 'Unknown Source' );
                $article_credit = esc_html( $article['credit'] ?? '' );
        ?>
        <div class="wp-block-group" style="margin-bottom:30px;"><div class="wp-block-group__inner-container"><div class="wp-block-columns is-layout-flex"><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><figure class="wp-block-image size-large"><img decoding="async" loading="lazy" src="<?php echo $article_image_url; ?>" class="news-teaser-img" alt="<?php echo esc_attr( $article_title ); ?>"><?php if ( ! empty( $article_credit ) ) : ?><figcaption class="teaser-caption"><?php echo esc_html($translation_strings['photo']); ?>: <?php echo $article_credit; ?></figcaption><?php endif; ?></figure></a></div><div class="wp-block-column"><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><h2 class="wp-block-heading"><?php echo $article_title; ?></h2></a><p class="news-teaser"><?php echo $article_teaser; ?></p><p><a href="<?php echo $article_url; ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html($translation_strings['source']); ?>: <?php echo $article_source; ?></a></p></div></div></div></div>
        
        <?php
                // --- THIS IS THE CORRECTED LOGIC ---
                // Insert the form if it's the 5th article OR if it's the LAST article and there are fewer than 5.
                if ( $article_counter === 5 || $article_counter === $total_articles && $total_articles < 5 ) :
        ?>
        <div class="ml-form-embed nl-cta" data-account="1712162:v1f8q9v0s8" data-form="<?php echo esc_attr($mailerlite_form_id); ?>"></div>
        <?php
                endif;
            endforeach;
        else :
            // If there are no articles at all, print the form immediately.
        ?>
            <div class="ml-form-embed nl-cta" data-account="1712162:v1f8q9v0s8" data-form="<?php echo esc_attr($mailerlite_form_id); ?>"></div>
        <?php
        endif; 
        ?>

        <!-- Final static content with language-aware block IDs -->
        <!-- wp:shortcode -->
        [Sassy_Social_Share]
        <!-- /wp:shortcode -->

        <!-- wp:spacer {"height":"40px"} -->
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- /wp:spacer -->

        <!-- wp:block {"ref":<?php echo absint($reusable_block_1_id); ?>} /-->

        <!-- wp:spacer {"height":"40px"} -->
        <div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
        <!-- /wp:spacer -->

        <!-- wp:block {"ref":<?php echo absint($reusable_block_2_id); ?>} /-->
    </div>
    <?php
    return ob_get_clean();
}


/**
 * ======================================================================
 * INTELLIGENT FEATURED IMAGE SETTER (TEST MODE: LOCAL SEARCH ONLY)
 * ======================================================================
 */
function firebase_connector_set_featured_image( $post_id, $image_url, $post_title ) {
    if ( empty($image_url) ) {
        return;
    }
    
    // Prevent re-processing the same image if the URL hasn't changed.
    $existing_image_url = get_post_meta( $post_id, FIREBASE_IMAGE_URL_META_KEY, true );
    if ( $image_url === $existing_image_url && has_post_thumbnail($post_id) ) {
        return;
    }

    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );

    $attachment_id = 0;
    
    // --- CDN-AWARE LOCAL IMAGE SEARCH ---
    
    // 1. Attempt to convert a potential CDN URL back to its original WordPress URL.
    $original_wp_url = '';
    $path_fragment = '/wp-content/uploads/';
    $fragment_pos = strpos($image_url, $path_fragment);

    if ($fragment_pos !== false) {
        $relative_path = substr($image_url, $fragment_pos);
        $original_wp_url = get_site_url(null, $relative_path);
    }

    // 2. Search the database using the converted original URL.
    if ( ! empty($original_wp_url) ) {
        global $wpdb;
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid = %s",
            $original_wp_url
        ) );
    }
    
    // --- FALLBACK TO DOWNLOADING ---

    // 3. If we STILL don't have an attachment_id (because it was a truly external image,
    // or the local search failed), then we proceed with downloading it.
    if ( ! $attachment_id ) {
        add_filter( 'http_request_timeout', function() { return 30; } );
        $sideload_result = media_sideload_image( $image_url, $post_id, $post_title, 'id' );
        remove_filter( 'http_request_timeout', function() { return 30; } );

        if ( ! is_wp_error( $sideload_result ) ) {
            $attachment_id = $sideload_result;
        } else {
            error_log("Firebase Connector: Failed to sideload image for post {$post_id}. URL: {$image_url}. Error: " . $sideload_result->get_error_message());
            return; // Stop if sideloading failed
        }
    }

    // --- FINAL STEP: SET THE THUMBNAIL ---

    // 4. If we have a valid attachment ID from either method, set it as the thumbnail.
    if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
        // Store the source URL so we can avoid re-processing it next time.
        update_post_meta( $post_id, FIREBASE_IMAGE_URL_META_KEY, $image_url );
    }
}