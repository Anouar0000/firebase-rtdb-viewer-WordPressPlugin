<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'FIREBASE_CONNECTOR_IMAGE_HEALTH_META_KEY' ) ) {
    define( 'FIREBASE_CONNECTOR_IMAGE_HEALTH_META_KEY', '_firebase_connector_image_health' );
}

function firebase_connector_get_default_image_health() {
    return [
        'status' => 'not_checked',
        'label' => 'Not checked',
        'checked_at' => '',
        'broken_count' => 0,
        'fixable_count' => 0,
        'manual_review_count' => 0,
        'articles' => [],
    ];
}

function firebase_connector_get_image_health_summary( $post_id ) {
    if ( empty( $post_id ) ) {
        return firebase_connector_get_default_image_health();
    }

    $health = get_post_meta( $post_id, FIREBASE_CONNECTOR_IMAGE_HEALTH_META_KEY, true );
    if ( ! is_array( $health ) ) {
        $health = [];
    }

    $health = array_merge( firebase_connector_get_default_image_health(), $health );
    $labels = [
        'ok'            => 'OK',
        'broken'        => 'Broken image URL',
        'fix_available' => 'Broken image URL',
        'manual_review' => 'Needs manual review',
        'reviewed'      => 'Reviewed',
        'not_checked'   => 'Not checked',
    ];
    $health['label'] = $labels[ $health['status'] ] ?? $labels['not_checked'];

    return $health;
}

function firebase_connector_store_image_health( $post_id, $health ) {
    $health = array_merge( firebase_connector_get_default_image_health(), $health );
    $health['checked_at'] = current_time( 'mysql', true );
    update_post_meta( $post_id, FIREBASE_CONNECTOR_IMAGE_HEALTH_META_KEY, $health );

    return firebase_connector_get_image_health_summary( $post_id );
}

function firebase_connector_is_usable_image_url( $url ) {
    if ( ! is_string( $url ) || trim( $url ) === '' ) {
        return false;
    }

    $url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
    return (bool) preg_match( '#^https?://#i', $url );
}

function firebase_connector_remote_image_url_is_ok( $url ) {
    if ( ! firebase_connector_is_usable_image_url( $url ) ) {
        return false;
    }

    $args = [
        'timeout'     => 8,
        'redirection' => 3,
        'sslverify'   => false,
    ];

    $response = wp_remote_head( $url, $args );
    $status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

    if ( $status_code === 405 || $status_code === 403 || $status_code === 0 ) {
        $response = wp_remote_get( $url, array_merge( $args, [ 'limit_response_size' => 1024 ] ) );
        $status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    }

    if ( $status_code >= 200 && $status_code < 400 ) {
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( is_string( $content_type ) && $content_type !== '' ) {
            $content_type = strtolower( trim( $content_type ) );
            if ( strpos( $content_type, 'text/' ) === 0 || strpos( $content_type, 'application/xhtml' ) !== false ) {
                return false; // Soft 404 or redirect to an HTML page
            }
        }
        return true;
    }

    return false;
}

function firebase_connector_extract_article_images_from_content( $content ) {
    $images = [];
    if ( ! is_string( $content ) || $content === '' ) {
        return $images;
    }

    if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
        return $images;
    }

    foreach ( $matches[0] as $tag ) {
        if ( ! preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $tag, $class_match ) ) {
            continue;
        }
        if ( ! preg_match( '/\bnews-teaser-img\b/', $class_match[2] ) ) {
            continue;
        }
        if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src_match ) ) {
            continue;
        }

        $images[] = [
            'index' => count( $images ),
            'src'   => trim( html_entity_decode( $src_match[2], ENT_QUOTES, 'UTF-8' ) ),
        ];
    }

    return $images;
}

function firebase_connector_get_sorted_issue_articles( $issue_details ) {
    $articles = [];
    if ( is_array( $issue_details ) && ! empty( $issue_details['articles'] ) && is_array( $issue_details['articles'] ) ) {
        $articles = $issue_details['articles'];
    }

    usort( $articles, function( $a, $b ) {
        return ( $a['position'] ?? 999 ) <=> ( $b['position'] ?? 999 );
    } );

    return $articles;
}

function firebase_connector_build_image_health( $post_id, $issue_id, $mark_unresolved_manual_review = false ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'firebase_image_post_missing', 'Post not found.' );
    }

    $article_images = firebase_connector_extract_article_images_from_content( $post->post_content );
    if ( empty( $article_images ) ) {
        return firebase_connector_store_image_health( $post_id, [
            'status' => $mark_unresolved_manual_review ? 'manual_review' : 'broken',
            'manual_review_count' => $mark_unresolved_manual_review ? 1 : 0,
            'articles' => [[
                'index' => 0,
                'status' => $mark_unresolved_manual_review ? 'manual_review' : 'broken',
                'reason' => 'No article images found in the post content.',
            ]],
        ] );
    }

    $issue_details = firebase_issues_fetcher_get_single_issue_details( $issue_id );
    if ( is_wp_error( $issue_details ) ) {
        return firebase_connector_store_image_health( $post_id, [
            'status' => 'broken',
            'broken_count' => count( $article_images ),
            'manual_review_count' => $mark_unresolved_manual_review ? count( $article_images ) : 0,
            'articles' => array_map( function( $image ) use ( $mark_unresolved_manual_review ) {
                return [
                    'index' => $image['index'],
                    'current_url' => $image['src'],
                    'firebase_url' => '',
                    'status' => $mark_unresolved_manual_review ? 'manual_review' : 'broken',
                    'reason' => 'Could not fetch Firebase issue details.',
                ];
            }, $article_images ),
        ] );
    }

    $firebase_articles = firebase_connector_get_sorted_issue_articles( $issue_details );
    $results = [];
    $broken_count = 0;
    $fixable_count = 0;
    $manual_review_count = 0;

    foreach ( $article_images as $image ) {
        $index = (int) $image['index'];
        $current_url = $image['src'];
        $firebase_url = isset( $firebase_articles[ $index ]['imageUrl'] ) ? trim( (string) $firebase_articles[ $index ]['imageUrl'] ) : '';
        $is_placeholder = ( trim( html_entity_decode( $current_url, ENT_QUOTES, 'UTF-8' ) ) === firebase_connector_image_placeholder_url() );
        $current_ok = ! $is_placeholder && firebase_connector_remote_image_url_is_ok( $current_url );
        $status = 'ok';
        $reason = '';

        if ( ! $current_ok ) {
            $broken_count++;
            if ( firebase_connector_is_usable_image_url( $firebase_url ) && $firebase_url !== $current_url && firebase_connector_remote_image_url_is_ok( $firebase_url ) ) {
                $status = 'fix_available';
                $fixable_count++;
            } else {
                $status = ( $mark_unresolved_manual_review || $is_placeholder ) ? 'manual_review' : 'broken';
                if ( $status === 'manual_review' ) {
                    $manual_review_count++;
                }
                $reason = $is_placeholder ? 'Placeholder needs manual review.' : 'Firebase has no different usable image URL for this article.';
            }
        }

        $article_url = isset( $firebase_articles[ $index ]['url'] ) ? trim( (string) $firebase_articles[ $index ]['url'] ) : '';
        $article_title = isset( $firebase_articles[ $index ]['title'] ) ? trim( (string) $firebase_articles[ $index ]['title'] ) : '';

        $results[] = [
            'index' => $index,
            'current_url' => $current_url,
            'firebase_url' => $firebase_url,
            'article_url' => $article_url,
            'article_title' => $article_title,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    $summary_status = 'ok';
    if ( $fixable_count > 0 ) {
        $summary_status = 'fix_available';
    } elseif ( $manual_review_count > 0 ) {
        $summary_status = 'manual_review';
    } elseif ( $broken_count > 0 ) {
        $summary_status = 'broken';
    }

    return firebase_connector_store_image_health( $post_id, [
        'status' => $summary_status,
        'broken_count' => $broken_count,
        'fixable_count' => $fixable_count,
        'manual_review_count' => $manual_review_count,
        'articles' => $results,
    ] );
}

function firebase_connector_replace_article_image_urls( $content, $replacements ) {
    if ( empty( $replacements ) ) {
        return $content;
    }

    $article_index = -1;
    return preg_replace_callback( '/<img\b[^>]*>/i', function( $matches ) use ( $replacements, &$article_index ) {
        $tag = $matches[0];
        if ( ! preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $tag, $class_match ) || ! preg_match( '/\bnews-teaser-img\b/', $class_match[2] ) ) {
            return $tag;
        }

        if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag ) ) {
            return $tag;
        }

        $article_index++;
        if ( ! isset( $replacements[ $article_index ] ) ) {
            return $tag;
        }

        $replacement_url = (string) $replacements[ $article_index ];
        $new_url = strpos( $replacement_url, 'data:image/svg+xml' ) === 0 ? $replacement_url : esc_url( $replacement_url );
        
        $tag = preg_replace( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', 'src="' . esc_attr( $new_url ) . '"', $tag, 1 );
        $tag = preg_replace( '/\s+srcset\s*=\s*(["\'])(.*?)\1/is', '', $tag );
        $tag = preg_replace( '/\s+sizes\s*=\s*(["\'])(.*?)\1/is', '', $tag );
        
        return $tag;
    }, $content );
}

function firebase_connector_fix_post_article_images( $post_id, $issue_id ) {
    $health = firebase_connector_build_image_health( $post_id, $issue_id, true );
    if ( is_wp_error( $health ) ) {
        return $health;
    }

    $replacements = [];
    $manual_review_count = 0;
    $final_articles = [];
    $placeholder_url = firebase_connector_image_placeholder_url();

    foreach ( $health['articles'] as $article ) {
        $index = (int) ( $article['index'] ?? 0 );
        $status = $article['status'] ?? 'ok';
        $firebase_url = $article['firebase_url'] ?? '';

        if ( $status === 'fix_available' && firebase_connector_is_usable_image_url( $firebase_url ) ) {
            $replacements[ $index ] = $firebase_url;
            $article['current_url'] = $firebase_url;
            $article['status'] = 'ok';
            $article['reason'] = '';
        } elseif ( in_array( $status, [ 'manual_review', 'broken' ], true ) ) {
            $replacements[ $index ] = $placeholder_url;
            
            // Preserve the original broken URL in the database just in case
            if ( ! isset( $article['original_broken_url'] ) && $article['current_url'] !== $placeholder_url ) {
                $article['original_broken_url'] = $article['current_url'];
            }
            
            $article['current_url'] = $placeholder_url;
            $article['status'] = 'manual_review';
            $article['reason'] = $article['reason'] ?: 'Firebase has no usable replacement image URL for this article.';
            $manual_review_count++;
        }

        $final_articles[] = $article;
    }

    if ( ! empty( $replacements ) ) {
        $post = get_post( $post_id );
        $updated_content = firebase_connector_replace_article_image_urls( $post->post_content, $replacements );
        if ( $updated_content !== $post->post_content ) {
            $updated = wp_update_post( [
                'ID' => $post_id,
                'post_content' => wp_slash( $updated_content ),
            ], true );

            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }
    }

    return firebase_connector_store_image_health( $post_id, [
        'status' => $manual_review_count > 0 ? 'manual_review' : 'ok',
        'broken_count' => 0,
        'fixable_count' => 0,
        'manual_review_count' => $manual_review_count,
        'articles' => $final_articles,
    ] );
}

function firebase_connector_mark_post_images_reviewed( $post_id ) {
    $health = firebase_connector_get_image_health_summary( $post_id );
    $health['status'] = 'reviewed';
    $health['reviewed_at'] = current_time( 'mysql', true );

    return firebase_connector_store_image_health( $post_id, $health );
}

function firebase_connector_image_placeholder_url() {
    return 'https://squirrel-news.net/wp-content/uploads/2026/07/placeholder.jpg';
}

function firebase_connector_render_manual_review_image_placeholders( $content ) {
    if ( is_admin() || ! is_singular( 'post' ) ) {
        return $content;
    }

    $post_id = get_the_ID();
    $health = firebase_connector_get_image_health_summary( $post_id );
    if ( empty( $health['articles'] ) || ! is_array( $health['articles'] ) ) {
        return $content;
    }

    $placeholder_indexes = [];
    foreach ( $health['articles'] as $article ) {
        if ( in_array( $article['status'] ?? '', [ 'manual_review' ], true ) ) {
            $placeholder_indexes[ (int) $article['index'] ] = firebase_connector_image_placeholder_url();
        }
    }

    if ( empty( $placeholder_indexes ) ) {
        return $content;
    }

    return firebase_connector_replace_article_image_urls( $content, $placeholder_indexes );
}
add_filter( 'the_content', 'firebase_connector_render_manual_review_image_placeholders', 20 );
