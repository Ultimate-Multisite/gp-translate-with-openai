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
	 * @return array Array of model IDs.
	 */
	public static function get_available_models( bool $force_refresh = false ): array {
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

		// If no API key, return defaults.
		if ( empty( $api_key ) ) {
			return self::DEFAULT_MODELS;
		}

		// Fetch from API.
		$models = self::fetch_models_from_api( $api_key, $base_url );

		// If fetch failed, return defaults.
		if ( empty( $models ) ) {
			return self::DEFAULT_MODELS;
		}

		// Cache the results.
		set_transient( $cache_key, $models, self::CACHE_EXPIRY );

		return $models;
	}

	/**
	 * Fetch models from the OpenAI-compatible API.
	 *
	 * @param string $api_key  The API key.
	 * @param string $base_url The base URL (empty for default OpenAI).
	 *
	 * @return array Array of model IDs.
	 */
	protected static function fetch_models_from_api( string $api_key, string $base_url = '' ): array {
		$openai = new OpenAi( $api_key );

		if ( ! empty( $base_url ) ) {
			$openai->setBaseURL( $base_url );
		}

		try {
			$response = $openai->listModels();
			$data     = json_decode( $response, true );

			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				return array();
			}

			$models = array();
			foreach ( $data['data'] as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[] = $model['id'];
				}
			}

			// Sort models alphabetically.
			sort( $models );

			// Filter to only include chat-capable models for OpenAI.
			if ( empty( $base_url ) ) {
				$models = self::filter_chat_models( $models );
			}

			return $models;
		} catch ( \Exception $e ) {
			return array();
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
		if ( empty( $api_key ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'API key is required.', 'gp-translate-with-openai' ),
				'models_count' => 0,
			);
		}

		$models = self::fetch_models_from_api( $api_key, $base_url );

		if ( empty( $models ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'Failed to connect to the API or no models available.', 'gp-translate-with-openai' ),
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
