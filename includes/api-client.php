<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * This file contains functions for interacting with Firebase Cloud Functions.
 */

/**
 * Fetches issues from the Firebase Cloud Function for listing.
 *
 * @param int    $limit The maximum number of issues to retrieve.
 * @param string $lang  The language code for the issues.
 * @return array|WP_Error An array of issues on success, WP_Error on failure.
 */
function firebase_issues_fetcher_get_issues( $limit, $lang ) {
    $options = get_option( 'firebase_connector_settings' );
    $api_token = $options['api_token'] ?? '';

    if (empty( $api_token ) ) {
        return new WP_Error( 'firebase_config_missing', 'Firebase API Token is not configured.' );
    }

    $function_url = FIREBASE_CONNECTOR_ISSUES_LIST_URL;

    $query_params = array(
        'lang'  => $lang,
        'limit' => $limit,
    );
    $request_url = add_query_arg( $query_params, $function_url );

    $args = array(
        'headers' => array(
            'token' => $api_token,
        ),
        'timeout' => 15,
    );

    $response = wp_remote_get( $request_url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'Firebase issues fetcher WP_Error: ' . $response->get_error_message() );
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code !== 200 ) {
        error_log( 'Firebase issues fetcher HTTP error: Status ' . $status_code . ' Body: ' . $body );
        return new WP_Error( 'firebase_http_error', 'Failed to fetch issues from Firebase. HTTP Status: ' . $status_code );
    }

    $issues = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Firebase issues fetcher JSON decode error: ' . json_last_error_msg() . ' Body: ' . $body );
        return new WP_Error( 'firebase_json_error', 'Invalid JSON response from Firebase Cloud Function.' );
    }

    if ( ! is_array( $issues ) ) {
        error_log( 'Firebase issues fetcher unexpected data format: ' . print_r($issues, true) );
        return new WP_Error( 'firebase_data_format_error', 'Unexpected data format received from Firebase Cloud Function.' );
    }

    return $issues;
}

function firebase_issues_fetcher_get_issues_page( $limit, $lang, $page_token = '' ) {
    $options = get_option( 'firebase_connector_settings' );
    $api_token = $options['api_token'] ?? '';

    if ( empty( $api_token ) ) {
        return new WP_Error( 'firebase_config_missing', 'Firebase API Token is not configured.' );
    }

    $query_params = array(
        'lang'  => $lang,
        'limit' => $limit,
        'paged' => 1,
    );
    if ( ! empty( $page_token ) ) {
        $query_params['pageToken'] = $page_token;
    }

    $request_url = add_query_arg( $query_params, FIREBASE_CONNECTOR_ISSUES_LIST_URL );
    $response = wp_remote_get( $request_url, array(
        'headers' => array(
            'token' => $api_token,
        ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( 'Firebase paged issues fetcher WP_Error: ' . $response->get_error_message() );
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code !== 200 ) {
        error_log( 'Firebase paged issues fetcher HTTP error: Status ' . $status_code . ' Body: ' . $body );
        return new WP_Error( 'firebase_http_error', 'Failed to fetch paged issues from Firebase. HTTP Status: ' . $status_code );
    }

    $payload = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Firebase paged issues fetcher JSON decode error: ' . json_last_error_msg() . ' Body: ' . $body );
        return new WP_Error( 'firebase_json_error', 'Invalid JSON response from Firebase Cloud Function.' );
    }

    if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
        return array(
            'items'           => $payload['items'],
            'next_page_token' => $payload['nextPageToken'] ?? '',
            'has_more'        => ! empty( $payload['hasMore'] ),
        );
    }

    if ( is_array( $payload ) ) {
        return array(
            'items'           => $payload,
            'next_page_token' => '',
            'has_more'        => false,
        );
    }

    return new WP_Error( 'firebase_data_format_error', 'Unexpected paged issue data format received from Firebase.' );
}



/**
 * Generates the URL for a single issue page.
 *
 * @param string $issue_id The ID of the issue.
 * @param string $lang     The language of the issue.
 * @return string The URL for the single issue page.
 */
function firebase_issues_fetcher_get_single_issue_url( $issue_id, $lang ) {
    $options = get_option( 'firebase_connector_settings' );
    $single_page_slug = isset( $options['single_page_slug'] ) ? $options['single_page_slug'] : 'issue-detail';

    // Construct the URL to the single issue page with query parameters
    $single_issue_link = add_query_arg(
        array(
            'id'   => $issue_id,
            'lang' => $lang, // Pass language to the single page too, useful if needed for context
        ),
        home_url( '/' . $single_page_slug . '/' )
    );

    return $single_issue_link;
}


/**
 * Fetches a single issue's details from the Firebase Cloud Function.
 *
 * @param string $issue_id The ID of the issue to retrieve.
 * @return array|WP_Error An array of issue details on success, WP_Error on failure.
 */
function firebase_issues_fetcher_get_single_issue_details( $issue_id ) {
    $options = get_option( 'firebase_connector_settings' );
    $api_token = $options['api_token'] ?? '';

    if (empty( $api_token ) ) {
        return new WP_Error( 'firebase_config_missing', 'Firebase API Token is not configured for single issue fetch.' );
    }

    $function_url = FIREBASE_CONNECTOR_ISSUE_DETAILS_URL;

    // Build query parameters
    $query_params = array(
        'id'  => $issue_id,
    );
    $request_url = add_query_arg( $query_params, $function_url );

    $args = array(
        'headers' => array(
            'token' => $api_token,
        ),
        'timeout' => 15,
    );

    $response = wp_remote_get( $request_url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'Firebase single issue fetcher WP_Error: ' . $response->get_error_message() );
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code !== 200 ) {
        error_log( 'Firebase single issue fetcher HTTP error: Status ' . $status_code . ' Body: ' . $body );
        return new WP_Error( 'firebase_http_error', 'Failed to fetch single issue from Firebase. HTTP Status: ' . $status_code );
    }

    $issue_data = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Firebase single issue fetcher JSON decode error: ' . json_last_error_msg() . ' Body: ' . $body );
        return new WP_Error( 'firebase_json_error', 'Invalid JSON response from Firebase Cloud Function for single issue.' );
    }

    // Your Cloud Function returns { id: ..., data: {...} }
    if ( ! is_array( $issue_data ) || ! isset( $issue_data['data'] ) ) {
        error_log( 'Firebase single issue fetcher unexpected data format: ' . print_r($issue_data, true) );
        return new WP_Error( 'firebase_data_format_error', 'Unexpected data format received for single issue.' );
    }

    return $issue_data['data']; // Return just the 'data' part of the response
}
