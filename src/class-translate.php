<?php
/**
 * Translate class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

use Orhanerday\OpenAi\OpenAi;
use GP;
use GP_Locales;
use WP_Error;

/**
 * Translate class.
 */
class Translate {

	/**
	 * Singleton instance.
	 *
	 * @var Translate
	 */
	private static $instance;

	/**
	 * Whether debug mode is enabled.
	 *
	 * @var bool
	 */
	private static bool $debug = false;

	/**
	 * Debug callback for outputting debug information.
	 *
	 * @var callable|null
	 */
	private static $debug_callback = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Translate
	 */
	public static function instance(): Translate {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enable or disable debug mode.
	 *
	 * @param bool          $enabled  Whether to enable debug mode.
	 * @param callable|null $callback Optional callback for debug output. Receives (string $label, mixed $data).
	 *
	 * @return void
	 */
	public static function set_debug( bool $enabled, ?callable $callback = null ): void {
		self::$debug          = $enabled;
		self::$debug_callback = $callback;
	}

	/**
	 * Output debug information.
	 *
	 * @param string $label The label for the debug output.
	 * @param mixed  $data  The data to output.
	 *
	 * @return void
	 */
	protected static function debug( string $label, $data ): void {
		if ( ! self::$debug ) {
			return;
		}

		if ( self::$debug_callback ) {
			call_user_func( self::$debug_callback, $label, $data );
		}
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * This function is used to bulk translate a set of strings.
	 *
	 * @param string|object $locale The locale to translate to.
	 * @param array         $strings The strings to translate.
	 *
	 * @return array|WP_Error The translated strings or an error.
	 */
	public function translate_batch( $locale, $strings, $contexts = array() ) {
		if ( is_object( $locale ) ) {
			$locale = $locale->slug;
		}

		return $this->openai_translate_batch( $locale, $strings, $contexts );
	}

	/**
	 * Translate the text (Source language is always English).
	 *
	 * @param string $text    The text to translate.
	 * @param string $locale  The locale to translate to.
	 * @param string $context Optional translators comment context.
	 *
	 * @return string
	 */
	public function translate( string $text, string $locale, string $context = '' ): string {
		// Check if the locale is supported.
		if ( ! Locales::is_supported( $locale ) ) {
			return $text;
		}

		$api_key = Config::get_api_key();
		$openai  = new OpenAi( $api_key );

		// Set custom base URL if configured.
		$base_url = Config::get_base_url();
		if ( ! empty( $base_url ) ) {
			$openai->setBaseURL( $base_url );
		}

		// Get locale name for the prompt.
		$locale_name = '';
		if ( class_exists( 'GP_Locales' ) ) {
			$locale_obj = GP_Locales::by_slug( $locale );
			if ( $locale_obj ) {
				$locale_name = $locale_obj->english_name;
			}
		}

		// Fallback to our locales list.
		if ( empty( $locale_name ) ) {
			$supported_locales = Locales::get_supported_locales();
			$locale_name       = $supported_locales[ $locale ] ?? $locale;
		}

		// Build glossary replacement.
		$glossary_text = '';
		if ( Config::get_use_glossary() ) {
			$matching_terms = Glossary::find_matching_terms( $text, $locale );
			if ( ! empty( $matching_terms ) ) {
				$glossary_text = Glossary::format_for_prompt( $matching_terms );
			}
		}

		// Build context replacement.
		$context_text = '';
		if ( ! empty( $context ) ) {
			$context_text = sprintf( 'Context: %s.', $context );
		}

		// Build system prompt from template with placeholder replacement.
		$system_prompt = Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}' ),
			array( 'English', $locale_name, $context_text, $glossary_text ),
			$system_prompt
		);
		// Clean up extra whitespace from empty placeholders.
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		// build request.
		$request = array(
			'model'             => Config::get_model(),
			'messages'          => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $text,
				),
			),
			'temperature'       => Config::get_temperature(),
			'max_tokens'        => 1000,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
		);

		// Determine the endpoint URL for debug output.
		$endpoint_url = 'https://api.openai.com/v1/chat/completions';
		if ( ! empty( $base_url ) ) {
			$endpoint_url = rtrim( $base_url, '/' ) . '/v1/chat/completions';
		}

		self::debug( 'REQUEST', array(
			'endpoint' => $endpoint_url,
			'method'   => 'POST',
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . substr( $api_key, 0, 8 ) . '...',
			),
			'body'     => $request,
		) );

		// get response.
		$chat     = $openai->chat( $request );
		$response = json_decode( $chat );

		self::debug( 'RESPONSE', array(
			'raw'    => $chat,
			'parsed' => $response,
		) );

		// check for api error.
		if ( isset( $response->error->code ) ) {
			self::debug( 'ERROR', array(
				'code'    => $response->error->code,
				'message' => $response->error->message ?? 'Unknown error',
			) );
			// insufficient_quota, context_length_exceeded.
			return $text;
		}

		// check if response is valid.
		if ( ! $response || ! isset( $response->choices ) || ! is_array( $response->choices ) ) {
			self::debug( 'ERROR', 'Invalid response structure' );
			return $text;
		}

		// get translation.
		$translation = $response->choices[0]->message->content;

		self::debug( 'RESULT', array(
			'source'      => $text,
			'translation' => $translation,
			'model'       => $response->model ?? 'unknown',
			'usage'       => $response->usage ?? null,
		) );

		// check if something has left.
		if ( empty( $translation ) ) {
			return $text;
		}

		return $translation;
	}

	/**
	 * Build the request payload for a single translation.
	 *
	 * @param string $text    The text to translate.
	 * @param string $locale  The locale to translate to.
	 * @param string $context Optional translators comment context.
	 *
	 * @return array{endpoint: string, headers: array, body: array}|null Null if locale unsupported.
	 */
	protected function build_request_payload( string $text, string $locale, string $context = '' ): ?array {
		if ( ! Locales::is_supported( $locale ) ) {
			return null;
		}

		$api_key  = Config::get_api_key();
		$base_url = Config::get_base_url();

		// Get locale name for the prompt.
		$locale_name = '';
		if ( class_exists( 'GP_Locales' ) ) {
			$locale_obj = GP_Locales::by_slug( $locale );
			if ( $locale_obj ) {
				$locale_name = $locale_obj->english_name;
			}
		}

		if ( empty( $locale_name ) ) {
			$supported_locales = Locales::get_supported_locales();
			$locale_name       = $supported_locales[ $locale ] ?? $locale;
		}

		// Build glossary replacement.
		$glossary_text = '';
		if ( Config::get_use_glossary() ) {
			$matching_terms = Glossary::find_matching_terms( $text, $locale );
			if ( ! empty( $matching_terms ) ) {
				$glossary_text = Glossary::format_for_prompt( $matching_terms );
			}
		}

		// Build context replacement.
		$context_text = '';
		if ( ! empty( $context ) ) {
			$context_text = sprintf( 'Context: %s.', $context );
		}

		// Build system prompt from template with placeholder replacement.
		$system_prompt = Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}' ),
			array( 'English', $locale_name, $context_text, $glossary_text ),
			$system_prompt
		);
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		$endpoint = 'https://api.openai.com/v1/chat/completions';
		if ( ! empty( $base_url ) ) {
			$endpoint = rtrim( $base_url, '/' ) . '/v1/chat/completions';
		}

		$body = array(
			'model'             => Config::get_model(),
			'messages'          => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $text,
				),
			),
			'temperature'       => Config::get_temperature(),
			'max_tokens'        => 1000,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
		);

		return array(
			'endpoint' => $endpoint,
			'headers'  => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $api_key,
			),
			'body'     => $body,
		);
	}

	/**
	 * Execute multiple translation requests concurrently using curl_multi.
	 *
	 * @param array $payloads Associative array of index => payload from build_request_payload().
	 *
	 * @return array Associative array of index => translated string.
	 */
	protected function execute_concurrent_requests( array $payloads ): array {
		$multi   = curl_multi_init();
		$handles = array();
		$results = array();

		foreach ( $payloads as $index => $payload ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $payload['endpoint'] );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $payload['body'] ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $payload['headers'] );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
			curl_multi_add_handle( $multi, $ch );
			$handles[ $index ] = $ch;
		}

		// Execute all requests.
		$running = null;
		do {
			curl_multi_exec( $multi, $running );
			if ( $running > 0 ) {
				curl_multi_select( $multi );
			}
		} while ( $running > 0 );

		// Collect results.
		foreach ( $handles as $index => $ch ) {
			$response_body = curl_multi_getcontent( $ch );
			$original_text = $payloads[ $index ]['body']['messages'][1]['content'];

			$response = json_decode( $response_body );

			if ( isset( $response->error->code ) ) {
				$results[ $index ] = $original_text;
			} elseif ( ! $response || ! isset( $response->choices ) || ! is_array( $response->choices ) ) {
				$results[ $index ] = $original_text;
			} else {
				$translation = $response->choices[0]->message->content;
				$results[ $index ] = ! empty( $translation ) ? $translation : $original_text;
			}

			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $multi );

		return $results;
	}

	/**
	 * This function is used to bulk translate a set of strings using OpenAI.
	 *
	 * @param string $locale The locale to translate to.
	 * @param array  $strings The strings to translate.
	 * @param array  $contexts The contexts for each string.
	 *
	 * @return array|WP_Error The translated strings or an error.
	 */
	protected function openai_translate_batch( $locale, $strings, $contexts = array() ) {
		if ( ! Locales::is_supported( $locale ) ) {
			return new WP_Error( 'gpoai_translate', sprintf( "The locale %s isn't supported by OpenAI.", $locale ) );
		}

		// If we don't have any strings, throw an error.
		if ( count( $strings ) === 0 ) {
			return new WP_Error( 'gpoai_translate', 'No strings found to translate.' );
		}

		// If we have too many strings, throw an error.
		if ( count( $strings ) > 50 ) {
			return new WP_Error( 'gpoai_translate', 'Only 50 strings allowed.' );
		}

		$max_concurrent = Config::get_max_concurrent_requests();

		if ( $max_concurrent <= 1 || ! function_exists( 'curl_multi_init' ) ) {
			// Sequential fallback.
			$translated_strings = array();
			foreach ( $strings as $index => $string ) {
				$context              = $contexts[ $index ] ?? '';
				$translated_strings[] = $this->translate( $string, $locale, $context );
			}
		} else {
			// Build all payloads.
			$payloads = array();
			foreach ( $strings as $index => $text ) {
				$payload = $this->build_request_payload( $text, $locale, $contexts[ $index ] ?? '' );
				if ( null === $payload ) {
					$payloads[ $index ] = null;
				} else {
					$payloads[ $index ] = $payload;
				}
			}

			// Separate valid payloads from nulls.
			$valid_payloads = array_filter( $payloads, function( $p ) {
				return null !== $p;
			} );

			// Execute in chunks.
			$translated_strings = array();
			foreach ( $strings as $index => $text ) {
				$translated_strings[ $index ] = $text; // Default to original.
			}

			foreach ( array_chunk( $valid_payloads, $max_concurrent, true ) as $chunk ) {
				$chunk_results = $this->execute_concurrent_requests( $chunk );
				foreach ( $chunk_results as $index => $translation ) {
					$translated_strings[ $index ] = $translation;
				}
			}

			// Re-index to sequential array.
			ksort( $translated_strings );
			$translated_strings = array_values( $translated_strings );
		}

		// Merge the originals and translations arrays.
		$items = gp_array_zip( $strings, $translated_strings );
		if ( ! $items ) {
			return new WP_Error( 'gpoai_translate', 'Error merging arrays' );
		}

		// Loop through the items and clean up the responses.
		$translations = array();
		foreach ( $items as $item ) {
			list( $string, $translation ) = $item;
			$translation                  = $this->clean_translation( $translation );
			$translations[]               = $translation;
		}

		return $translations;
	}

	/**
	 * Cleans up the translation string.
	 *
	 * @param string $text The string to clean.
	 *
	 * @return string
	 */
	protected function clean_translation( $text ) {
		$text = preg_replace_callback(
			'/% (s|d)/i',
			function ( $m ) { // phpcs:ignore
				return '"%".strtolower($m[1])';
			},
			$text
		);
		$text = preg_replace_callback(
			'/% (\d+) \$ (s|d)/i',
			function ( $m ) { // phpcs:ignore
				return '"%".$m[1]."\\$".strtolower($m[2])';
			},
			$text
		);

		return $text;
	}

	/**
	 * Handles bulk translation action.
	 *
	 * @param object $project The current project object.
	 * @param object $locale The current locale object.
	 * @param object $translation_set The current translation set object.
	 * @param array  $bulk The current bulk action array.
	 *
	 * @return void
	 */
	public function gp_translation_set_bulk_action_post( $project, $locale, $translation_set, $bulk ) {
		// Status counters.
		$count            = array();
		$count['err_api'] = 0;
		$count['err_add'] = 0;
		$count['added']   = 0;
		$count['skipped'] = 0;

		$singulars    = array();
		$contexts     = array();
		$original_ids = array();

		// Loop through each of the passed in strings and translate them.
		foreach ( $bulk['row-ids'] as $row_id ) {
			// Split the $row_id by '-' and get the first one (which will be the id of the original).
			$original_id = gp_array_get( explode( '-', $row_id ), 0 );
			// Get the original based on the above id.
			$original = GP::$original->get( $original_id );

			// If there is no original or it's a plural, skip it.
			if ( ! $original || $original->plural ) {
				++$count['skipped'];
				continue;
			}

			// Add the original to the queue to translate.
			$singulars[]    = $original->singular;
			$contexts[]     = $original->comment ?? '';
			$original_ids[] = $original_id;
		}

		// Translate all the originals that we found.
		$results = $this->translate_batch( $locale, $singulars, $contexts );

		// Did we get an error?
		if ( is_wp_error( $results ) ) {
			gp_notice_set( $results->get_error_message(), 'error' );
			return;
		}

		// Merge the results back in to the original id's and singulars
		// This will create an array like ($items = array( array( id, single, result), array( id, single, result), ... ).
		$items = gp_array_zip( $original_ids, $singulars, $results );

		// If we have no items, something went wrong and stop processing.
		if ( ! $items ) {
			return;
		}

		// Loop through the items and store them in the database.
		foreach ( $items as $item ) {
			// Break up the item back in to individual components.
			list( $original_id, $singular, $translation ) = $item;

			// Did we get an error?
			if ( is_wp_error( $translation ) ) {
				++$count['err_api'];
				continue;
			}

			$warnings = GP::$translation_warnings->check( $singular, null, array( $translation ), $locale );

			// Build a data array to store.
			$data                       = array();
			$data['original_id']        = $original_id;
			$data['user_id']            = get_current_user_id();
			$data['translation_set_id'] = $translation_set->id;
			$data['translation_0']      = $translation;
			$data['status']             = 'fuzzy';
			$data['warnings']           = $warnings;

			// Insert the item in to the database.
			$inserted = GP::$translation->create( $data );
			if ( $inserted ) {
				++$count['added'];
			} else {
				++$count['err_add'];
			}
		}

		$this->set_bulk_action_notice( $count );
	}

	/**
	 * Set notice for bulk action.
	 *
	 * @param array $count The count array.
	 *
	 * @return void
	 */
	protected function set_bulk_action_notice( $count ) {
		// If there are no errors, display how many translations were added.
		if ( 0 === $count['err_api'] && 0 === $count['err_add'] ) {
			// translators: %d is the number of translations added.
			gp_notice_set( sprintf( __( '%d fuzzy translation from OpenAI were added.', 'gp-translate-with-openai' ), $count['added'] ) );
			return;
		}

		$messages = array();

		if ( $count['added'] ) {
			// translators: %d is the number of translations added.
			$messages[] = sprintf( __( 'Added: %d.', 'gp-translate-with-openai' ), $count['added'] );
		}

		if ( $count['err_api'] ) {
			// translators: %d is the number of errors from OpenAI.
			$messages[] = sprintf( __( 'Error from OpenAI: %d.', 'gp-translate-with-openai' ), $count['err_api'] );
		}

		if ( $count['err_add'] ) {
			// translators: %d is the number of errors adding translations.
			$messages[] = sprintf( __( 'Error adding: %d.', 'gp-translate-with-openai' ), $count['err_add'] );
		}

		if ( $count['skipped'] ) {
			// translators: %d is the number of skipped translations.
			$messages[] = sprintf( __( 'Skipped: %d.', 'gp-translate-with-openai' ), $count['skipped'] );
		}

		// Create a message string and add it to the GlotPress notices.
		gp_notice_set( implode( ' ', $messages ), 'error' );
	}
}
