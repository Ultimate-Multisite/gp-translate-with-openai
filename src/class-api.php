<?php
/**
 * API class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

use Orhanerday\OpenAi\OpenAi;

/**
 * API class for OpenAI API utilities.
 */
class Api {

	/**
	 * Transient key for caching models.
	 *
	 * @var string
	 */
	const MODELS_TRANSIENT_KEY = 'gpoai_available_models';

	/**
	 * Cache expiry in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_EXPIRY = HOUR_IN_SECONDS;

	/**
	 * Default OpenAI models as fallback.
	 *
	 * @var array
	 */
	const DEFAULT_MODELS = array(
		'gpt-3.5-turbo',
		'gpt-4',
		'gpt-4-turbo',
		'gpt-4o',
		'gpt-4o-mini',
	);

	/**
	 * Get available models from the API.
	 *
	 * @param bool $force_refresh Force a refresh from the API.
	 *
	 * @return array|\WP_Error Array of model IDs or WP_Error on failure.
	 */
	public static function get_available_models( bool $force_refresh = false ) {
		// Generate cache key based on current configuration.
		$base_url  = Config::get_base_url();
		$api_key   = Config::get_api_key();
		$cache_key = self::MODELS_TRANSIENT_KEY . '_' . md5( $base_url . $api_key );

		// Check cache first unless forcing refresh.
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		// If no API key and no custom base URL, return defaults.
		// Custom endpoints (e.g. Ollama) may not require an API key.
		if ( empty( $api_key ) && empty( $base_url ) ) {
			return self::DEFAULT_MODELS;
		}

		// Fetch from API.
		$result = self::fetch_models_from_api( $api_key, $base_url );

		// If fetch failed, return the error.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache the results.
		set_transient( $cache_key, $result, self::CACHE_EXPIRY );

		return $result;
	}

	/**
	 * Fetch models from the OpenAI-compatible API.
	 *
	 * @param string $api_key  The API key.
	 * @param string $base_url The base URL (empty for default OpenAI).
	 *
	 * @return array|\WP_Error Array of model IDs or WP_Error on failure.
	 */
	protected static function fetch_models_from_api( string $api_key, string $base_url = '' ) {
		// Use a placeholder key for endpoints that don't require authentication (e.g. Ollama).
		$openai = new OpenAi( ! empty( $api_key ) ? $api_key : 'ollama' );

		if ( ! empty( $base_url ) ) {
			$openai->setBaseURL( $base_url );
		}

		try {
			$response = $openai->listModels();
			$data     = json_decode( $response, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new \WP_Error(
					'invalid_response',
					sprintf(
						/* translators: %s: JSON error message */
						__( 'Invalid JSON response from API: %s', 'gp-translate-with-openai' ),
						json_last_error_msg()
					)
				);
			}

			if ( isset( $data['error'] ) ) {
				$error_msg = is_array( $data['error'] ) ? ( $data['error']['message'] ?? wp_json_encode( $data['error'] ) ) : $data['error'];
				return new \WP_Error( 'api_error', $error_msg );
			}

			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				return new \WP_Error(
					'unexpected_response',
					__( 'Unexpected API response format. The "data" field is missing.', 'gp-translate-with-openai' )
				);
			}

			$models = array();
			foreach ( $data['data'] as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[] = $model['id'];
				}
			}

			if ( empty( $models ) ) {
				return new \WP_Error( 'no_models', __( 'API returned no models.', 'gp-translate-with-openai' ) );
			}

			// Sort models alphabetically.
			sort( $models );

			// Filter to only include chat-capable models for OpenAI.
			if ( empty( $base_url ) ) {
				$models = self::filter_chat_models( $models );
			}

			return $models;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'connection_failed', $e->getMessage() );
		}
	}

	/**
	 * Filter to only include chat-capable models.
	 *
	 * @param array $models Array of model IDs.
	 *
	 * @return array Filtered array of model IDs.
	 */
	protected static function filter_chat_models( array $models ): array {
		$chat_prefixes = array(
			'gpt-3.5',
			'gpt-4',
			'gpt-5',
			'o1',
			'o3',
			'chatgpt',
		);

		$filtered = array();
		foreach ( $models as $model ) {
			foreach ( $chat_prefixes as $prefix ) {
				if ( strpos( $model, $prefix ) === 0 ) {
					$filtered[] = $model;
					break;
				}
			}
		}

		// If no chat models found, return all models.
		if ( empty( $filtered ) ) {
			return $models;
		}

		return $filtered;
	}

	/**
	 * Clear the models cache.
	 *
	 * @return void
	 */
	public static function clear_models_cache(): void {
		global $wpdb;

		// Delete all model cache transients.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::MODELS_TRANSIENT_KEY . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_timeout_' . self::MODELS_TRANSIENT_KEY . '%'
			)
		);
	}

	/**
	 * Test the API connection.
	 *
	 * @param string $api_key  The API key to test.
	 * @param string $base_url The base URL to test.
	 *
	 * @return array{success: bool, message: string, models_count: int}
	 */
	public static function test_connection( string $api_key, string $base_url = '' ): array {
		if ( empty( $api_key ) && empty( $base_url ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'API key is required.', 'gp-translate-with-openai' ),
				'models_count' => 0,
			);
		}

		$models = self::fetch_models_from_api( $api_key, $base_url );

		if ( is_wp_error( $models ) ) {
			return array(
				'success'      => false,
				'message'      => $models->get_error_message(),
				'models_count' => 0,
			);
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: number of models */
				__( 'Connection successful. Found %d available models.', 'gp-translate-with-openai' ),
				count( $models )
			),
			'models_count' => count( $models ),
		);
	}
}
