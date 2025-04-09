<?php
/**
 * Codex AI functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Codex_AI
 * @since Codex AI 1.0.0
 */

// Ensure the code only runs in WordPress
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches AI-generated search suggestions from multiple services.
 *
 * This function handles an AJAX request to gather search suggestions from
 * multiple AI and external APIs. It sanitizes the input query and returns
 * a merged list of suggestions.
 *
 * @since Codex AI 1.0.0
 * @return void Outputs a JSON response containing search suggestions.
 */
function codex_ai_search_suggestions() {
	// Sanitize and validate the query parameter.
	$query = isset( $_GET['query'] ) ? sanitize_text_field( wp_unslash( $_GET['query'] ) ) : '';
	
	if ( empty( $query ) ) {
		wp_send_json( [] );
	}

	// Attempt to fetch suggestions from multiple sources.
	$suggestions = codex_get_all_suggestions( $query );

	// Limit the number of results and output as JSON.
	wp_send_json( array_slice( array_unique( $suggestions ), 0, 10 ) );
}
add_action( 'wp_ajax_codex_ai_search_suggestions', 'codex_ai_search_suggestions' );
add_action( 'wp_ajax_nopriv_codex_ai_search_suggestions', 'codex_ai_search_suggestions' );

/**
 * Centralized function to fetch suggestions from all APIs.
 *
 * @param string $query The user’s search query.
 * @return array Combined suggestions from all sources.
 */
function codex_get_all_suggestions( $query ) {
	return array_merge(
		codex_fetch_chatgpt_suggestions( $query ),
		codex_fetch_perplexity_suggestions( $query ),
		codex_fetch_google_suggestions( $query ),
		codex_fetch_gemini_suggestions( $query ),
		codex_fetch_claude_suggestions( $query )
	);
}

/**
 * Helper function to make API requests.
 *
 * @param string $url The API endpoint URL.
 * @param string $method The HTTP method (GET or POST).
 * @param array $headers The request headers.
 * @param array $body The request body for POST requests.
 * @return array The decoded API response.
 */
function codex_fetch_api_response( $url, $method = 'GET', $headers = [], $body = [] ) {
	$args = [
		'method'  => $method,
		'headers' => $headers,
	];

	if ( ! empty( $body ) ) {
		$args['body'] = json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	// Handle errors and return an empty array if the request fails.
	if ( is_wp_error( $response ) ) {
		error_log( 'API request failed: ' . $response->get_error_message() );
		return [];
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( $http_code !== 200 ) {
		error_log( 'API returned non-200 status code: ' . $http_code );
		return [];
	}

	$body = wp_remote_retrieve_body( $response );
	return json_decode( $body, true );
}

/**
 * Fetch suggestions from ChatGPT.
 *
 * @param string $query The user’s search query.
 * @return array Array of suggestions from ChatGPT.
 */
function codex_fetch_chatgpt_suggestions( $query ) {
	$api_key = getenv( 'OPENAI_API_KEY' );
	$url     = 'https://api.openai.com/v1/completions';

	$request_body = [
		'model'      => 'gpt-4.5-turbo',
		'prompt'     => "Provide 5 related search queries for: " . $query,
		'max_tokens' => 50,
	];

	$headers = [
		'Authorization' => "Bearer $api_key",
		'Content-Type'  => 'application/json',
	];

	$data = codex_fetch_api_response( $url, 'POST', $headers, $request_body );
	return ! empty( $data['choices'][0]['text'] ) ? explode( "\n", trim( $data['choices'][0]['text'] ) ) : [];
}

/**
 * Fetch suggestions from Perplexity.
 *
 * @param string $query The user’s search query.
 * @return array Array of suggestions from Perplexity.
 */
function codex_fetch_perplexity_suggestions( $query ) {
	$api_key = getenv( 'PERPLEXITY_API_KEY' );
	$url     = "https://api.perplexity.ai/search?q=" . urlencode( $query ) . "&key=$api_key";

	$data = codex_fetch_api_response( $url );
	return ! empty( $data['suggestions'] ) ? $data['suggestions'] : [];
}

/**
 * Fetch suggestions from Google.
 *
 * @param string $query The user’s search query.
 * @return array Array of suggestions from Google.
 */
function codex_fetch_google_suggestions( $query ) {
	$api_key = getenv( 'GOOGLE_SEARCH_API_KEY' );
	$url     = "https://www.googleapis.com/customsearch/v1?q=" . urlencode( $query ) . "&key=$api_key";

	$data = codex_fetch_api_response( $url );
	return ! empty( $data['items'] ) ? array_column( $data['items'], 'title' ) : [];
}

/**
 * Fetch suggestions from Gemini.
 *
 * @param string $query The user’s search query.
 * @return array Array of suggestions from Gemini.
 */
function codex_fetch_gemini_suggestions( $query ) {
	$api_key = getenv( 'GEMINI_API_KEY' );
	$url     = "https://gemini.google.com/api/search?q=" . urlencode( $query ) . "&key=$api_key";

	$data = codex_fetch_api_response( $url );
	return ! empty( $data['suggestions'] ) ? $data['suggestions'] : [];
}

/**
 * Fetch suggestions from Claude.
 *
 * @param string $query The user’s search query.
 * @return array Array of suggestions from Claude.
 */
function codex_fetch_claude_suggestions( $query ) {
	$api_key = getenv( 'CLAUDE_API_KEY' );
	$url     = 'https://api.anthropic.com/v1/completions';

	$request_body = [
		'model'      => 'claude-2',
		'prompt'     => "Provide 5 search suggestions for: " . $query,
		'max_tokens' => 50,
	];

	$headers = [
		'Authorization' => "Bearer $api_key",
		'Content-Type'  => 'application/json',
	];

	$data = codex_fetch_api_response( $url, 'POST', $headers, $request_body );
	return ! empty( $data['choices'][0]['text'] ) ? explode( "\n", trim( $data['choices'][0]['text'] ) ) : [];
}
