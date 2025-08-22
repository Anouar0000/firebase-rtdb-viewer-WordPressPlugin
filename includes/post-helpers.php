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
    // --- All your language-aware logic at the top is perfect and unchanged ---
    $options = get_option('firebase_connector_settings');
    $current_lang = $options['lang'] ?? 'en';
    $translation_strings = [
        'photo'  => ($current_lang === 'de') ? 'Foto' : 'Photo',
        'source' => ($current_lang === 'de') ? 'Quelle' : 'Source',
    ];
    $mailerlite_form_id = ($current_lang === 'de') ? '3034691:n9b6n1' : '3345723:b6d6q7';
    $reusable_block_1_id = ($current_lang === 'de') ? '3174' : '2224';
    $reusable_block_2_id = ($current_lang === 'de') ? '1423' : '515';

    // Get standard issue data (unchanged)
    $main_image_credit = isset( $issue['imageCredit'] ) ? esc_html( $issue['imageCredit'] ) : '';
    $teaser = isset( $issue['teaser'] ) ? wp_kses_post( $issue['teaser'] ) : '';
    
    // --- START: Build the entire content as a single string ---
    $content_html = '';

    $content_html .= '<div class="firebase-post-content-wrapper">';

    if ( ! empty( $main_image_credit ) ) {
        $content_html .= '<p class="featured-img-caption">' . esc_html($translation_strings['photo']) . ': ' . $main_image_credit . '</p>';
    }
    if ( ! empty( $teaser ) ) {
        $content_html .= '<p class="vorspann">' . $teaser . '</p>';
    }
    
    if ( ! empty( $issue['articles'] ) && is_array( $issue['articles'] ) ) {
        $articles = $issue['articles'];
        usort($articles, function($a, $b) { return ($a['position'] ?? 999) <=> ($b['position'] ?? 999); });
        
        $article_counter = 0;
        $total_articles = count($articles);

        foreach ( $articles as $article ) {
            $article_counter++;
            $article_url = esc_url( $article['url'] ?? '#' );
            $article_image_url = esc_url( $article['imageUrl'] ?? '' );
            $article_title = esc_html( $article['title'] ?? 'No Title' );
            $article_teaser = wp_kses_post( $article['teaser'] ?? '' );
            $article_source = esc_html( $article['source'] ?? 'Unknown Source' );
            $article_credit = esc_html( $article['credit'] ?? '' );

            // Append the HTML for one article to our main string
            $content_html .= '<div class="wp-block-group" style="margin-bottom:30px;"><div class="wp-block-group__inner-container">';
            $content_html .= '<div class="wp-block-columns is-layout-flex">';
            
            // Image Column
            $content_html .= '<div class="wp-block-column">';
            $content_html .= '<a href="' . $article_url . '" target="_blank" rel="noreferrer noopener">';
            $content_html .= '<figure class="wp-block-image size-large"><img decoding="async" loading="lazy" src="' . $article_image_url . '" class="news-teaser-img" alt="' . esc_attr($article_title) . '">';
            if ( ! empty( $article_credit ) ) {
                $content_html .= '<figcaption class="teaser-caption">' . esc_html($translation_strings['photo']) . ': ' . $article_credit . '</figcaption>';
            }
            $content_html .= '</figure></a></div>';
            
            // Text Column
            $content_html .= '<div class="wp-block-column">';
            // ** THE FIX IS HERE: `<a>` is now INSIDE `<h2>` **
            $content_html .= '<h2 class="wp-block-heading"><a href="' . $article_url . '" target="_blank" rel="noreferrer noopener">' . $article_title . '</a></h2>';
            $content_html .= '<p class="news-teaser">' . $article_teaser . '</p>';
            $content_html .= '<p><a href="' . $article_url . '" target="_blank" rel="noreferrer noopener">' . esc_html($translation_strings['source']) . ': ' . $article_source . '</a></p>';
            $content_html .= '</div>';
            
            $content_html .= '</div></div></div>'; // Close columns, inner-container, and group

            // Check if we need to insert the newsletter form
            if ( $article_counter === 5 || ($article_counter === $total_articles && $total_articles < 5) ) {
                $content_html .= '<div class="ml-form-embed nl-cta" data-account="1712162:v1f8q9v0s8" data-form="' . esc_attr($mailerlite_form_id) . '"></div>';
            }
        }
    } else {
        $content_html .= '<div class="ml-form-embed nl-cta" data-account="1712162:v1f8q9v0s8" data-form="' . esc_attr($mailerlite_form_id) . '"></div>';
    }

    // Append the final static content
    $content_html .= '<!-- wp:group --><div class="wp-block-group"><div class="wp-block-group__inner-container">';
    $content_html .= '<!-- wp:shortcode -->[Sassy_Social_Share]<!-- /wp:shortcode -->';
    $content_html .= '<!-- wp:spacer {"height":"40px"} --><div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';
    $content_html .= '<!-- wp:block {"ref":' . absint($reusable_block_1_id) . '} /-->';
    $content_html .= '<!-- wp:spacer {"height":"40px"} --><div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';
    $content_html .= '<!-- wp:block {"ref":' . absint($reusable_block_2_id) . '} /-->';
    $content_html .= '</div></div><!-- /wp:group -->';

    $content_html .= '</div>'; // Close the main wrapper

    return $content_html;
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