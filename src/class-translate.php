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
	public function translate_batch( $locale, $strings, $contexts = array(), $original_ids = array(), $project_id = 0 ) {
		if ( is_object( $locale ) ) {
			$locale = $locale->slug;
		}

		return $this->openai_translate_batch( $locale, $strings, $contexts, $original_ids, $project_id );
	}

	/**
	 * Get neighboring original strings from the same project for context.
	 *
	 * @param int $original_id The current original ID.
	 * @param int $project_id  The project ID.
	 * @param int $count       Number of neighbors to fetch on each side.
	 *
	 * @return string Formatted neighboring strings text, or empty string.
	 */
	public function get_neighboring_strings( int $original_id, int $project_id, int $count = 5 ): string {
		global $wpdb;

		if ( ! $original_id || ! $project_id ) {
			return '';
		}

		$table = GP::$original->table;

		// Get preceding originals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT singular FROM {$table}
				WHERE project_id = %d AND id < %d AND status = '+active' AND plural IS NULL
				ORDER BY id DESC LIMIT %d",
				$project_id,
				$original_id,
				$count
			)
		);

		// Get following originals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT singular FROM {$table}
				WHERE project_id = %d AND id > %d AND status = '+active' AND plural IS NULL
				ORDER BY id ASC LIMIT %d",
				$project_id,
				$original_id,
				$count
			)
		);

		$neighbors = array_merge( array_reverse( $before ), $after );

		if ( empty( $neighbors ) ) {
			return '';
		}

		$quoted = array_map( function( $s ) {
			return '"' . $s . '"';
		}, $neighbors );

		return implode( ', ', $quoted );
	}

	/**
	 * Translate the text (Source language is always English).
	 *
	 * @param string    $text         The text to translate.
	 * @param string    $locale       The locale to translate to.
	 * @param string    $context      Optional translators comment context.
	 * @param int       $original_id  Optional original ID for neighboring strings context.
	 * @param int       $project_id   Optional project ID for neighboring strings context.
	 * @param ?string   $model        Optional model override. Defaults to config value.
	 * @param ?string   $prompt       Optional system prompt override. Defaults to config value.
	 * @param ?bool     $use_glossary Optional glossary override. Defaults to config value.
	 *
	 * @return string
	 */
	public function translate( string $text, string $locale, string $context = '', int $original_id = 0, int $project_id = 0, ?string $model = null, ?string $prompt = null, ?bool $use_glossary = null ): string {
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
		$glossary_text    = '';
		$effective_glossary = $use_glossary ?? Config::get_use_glossary();
		if ( $effective_glossary ) {
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

		// Build neighboring strings context.
		$neighboring_text = '';
		if ( $original_id && $project_id ) {
			$neighboring_text = $this->get_neighboring_strings( $original_id, $project_id );
		}

		// Build locale instructions replacement.
		$locale_instructions = Config::get_locale_instructions( $locale );

		// Build system prompt from template with placeholder replacement.
		$system_prompt = $prompt ?? Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}', '{LOCALE_INSTRUCTIONS}', '{NEIGHBORING_STRINGS}' ),
			array( 'English', $locale_name, $context_text, $glossary_text, $locale_instructions, $neighboring_text ),
			$system_prompt
		);
		// Clean up extra whitespace from empty placeholders.
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		// build request.
		$request = array(
			'model'             => $model ?? Config::get_model(),
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
	 * @param string  $text         The text to translate.
	 * @param string  $locale       The locale to translate to.
	 * @param string  $context      Optional translators comment context.
	 * @param int     $original_id  Optional original ID for neighboring strings context.
	 * @param int     $project_id   Optional project ID for neighboring strings context.
	 * @param ?string $model        Optional model override. Defaults to config value.
	 * @param ?string $prompt       Optional system prompt override. Defaults to config value.
	 * @param ?bool   $use_glossary Optional glossary override. Defaults to config value.
	 *
	 * @return array{endpoint: string, headers: array, body: array}|null Null if locale unsupported.
	 */
	protected function build_request_payload( string $text, string $locale, string $context = '', int $original_id = 0, int $project_id = 0, ?string $model = null, ?string $prompt = null, ?bool $use_glossary = null ): ?array {
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
		$glossary_text    = '';
		$effective_glossary = $use_glossary ?? Config::get_use_glossary();
		if ( $effective_glossary ) {
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

		// Build neighboring strings context.
		$neighboring_text = '';
		if ( $original_id && $project_id ) {
			$neighboring_text = $this->get_neighboring_strings( $original_id, $project_id );
		}

		// Build locale instructions replacement.
		$locale_instructions = Config::get_locale_instructions( $locale );

		// Build system prompt from template with placeholder replacement.
		$system_prompt = $prompt ?? Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}', '{LOCALE_INSTRUCTIONS}', '{NEIGHBORING_STRINGS}' ),
			array( 'English', $locale_name, $context_text, $glossary_text, $locale_instructions, $neighboring_text ),
			$system_prompt
		);
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		$endpoint = 'https://api.openai.com/v1/chat/completions';
		if ( ! empty( $base_url ) ) {
			$endpoint = rtrim( $base_url, '/' ) . '/v1/chat/completions';
		}

		$body = array(
			'model'             => $model ?? Config::get_model(),
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
	protected function openai_translate_batch( $locale, $strings, $contexts = array(), $original_ids = array(), $project_id = 0 ) {
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
				$orig_id              = $original_ids[ $index ] ?? 0;
				$translated_strings[] = $this->translate( $string, $locale, $context, (int) $orig_id, (int) $project_id );
			}
		} else {
			// Build all payloads.
			$payloads = array();
			foreach ( $strings as $index => $text ) {
				$orig_id = $original_ids[ $index ] ?? 0;
				$payload = $this->build_request_payload( $text, $locale, $contexts[ $index ] ?? '', (int) $orig_id, (int) $project_id );
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
	 * Translate a plural string, returning all plural forms for the locale.
	 *
	 * @param string $singular    The singular form.
	 * @param string $plural      The plural form.
	 * @param string $locale      The locale to translate to.
	 * @param int    $nplurals    Number of plural forms for the locale.
	 * @param string $context     Optional translators comment context.
	 * @param int    $original_id Optional original ID for neighboring strings context.
	 * @param int    $project_id  Optional project ID for neighboring strings context.
	 *
	 * @return array|string Array of plural form translations indexed 0..nplurals-1, or original singular on failure.
	 */
	public function translate_plural( string $singular, string $plural, string $locale, int $nplurals, string $context = '', int $original_id = 0, int $project_id = 0 ) {
		if ( ! Locales::is_supported( $locale ) ) {
			return $singular;
		}

		$api_key = Config::get_api_key();
		$openai  = new OpenAi( ! empty( $api_key ) ? $api_key : 'ollama' );

		$base_url = Config::get_base_url();
		if ( ! empty( $base_url ) ) {
			$openai->setBaseURL( $base_url );
		}

		// Get locale name.
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

		// Build glossary.
		$glossary_text = '';
		if ( Config::get_use_glossary() ) {
			$matching_terms = Glossary::find_matching_terms( $singular . ' ' . $plural, $locale );
			if ( ! empty( $matching_terms ) ) {
				$glossary_text = Glossary::format_for_prompt( $matching_terms );
			}
		}

		// Build context.
		$context_text = '';
		if ( ! empty( $context ) ) {
			$context_text = sprintf( 'Context: %s.', $context );
		}

		// Build neighboring strings.
		$neighboring_text = '';
		if ( $original_id && $project_id ) {
			$neighboring_text = $this->get_neighboring_strings( $original_id, $project_id );
		}

		// Build locale instructions.
		$locale_instructions = Config::get_locale_instructions( $locale );

		// Build system prompt.
		$system_prompt = Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}', '{LOCALE_INSTRUCTIONS}', '{NEIGHBORING_STRINGS}' ),
			array( 'English', $locale_name, $context_text, $glossary_text, $locale_instructions, $neighboring_text ),
			$system_prompt
		);
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		// Build plural-specific user message.
		$form_labels = array();
		for ( $i = 0; $i < $nplurals; $i++ ) {
			$form_labels[] = 'form' . $i;
		}
		$user_message = sprintf(
			"Translate this plural string. The English singular is: \"%s\" and the English plural is: \"%s\". " .
			"This locale requires %d plural forms. Return ONLY a JSON object with keys %s containing each translated plural form. " .
			"Do not include any explanation, markdown formatting, or code blocks — return only the raw JSON object.",
			$singular,
			$plural,
			$nplurals,
			implode( ', ', array_map( function ( $l ) { return '"' . $l . '"'; }, $form_labels ) )
		);

		$request = array(
			'model'             => Config::get_model(),
			'messages'          => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),
			'temperature'       => Config::get_temperature(),
			'max_tokens'        => 1000,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
		);

		self::debug( 'PLURAL_REQUEST', $request );

		try {
			$chat     = $openai->chat( $request );
			$response = json_decode( $chat );
		} catch ( \Exception $e ) {
			self::debug( 'PLURAL_ERROR', $e->getMessage() );
			return $singular;
		}

		if ( isset( $response->error ) || ! isset( $response->choices[0]->message->content ) ) {
			self::debug( 'PLURAL_ERROR', $response->error ?? 'Invalid response' );
			return $singular;
		}

		$content = trim( $response->choices[0]->message->content );

		// Strip markdown code fences if present.
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		$parsed = json_decode( $content, true );

		self::debug( 'PLURAL_RESULT', array(
			'raw'    => $content,
			'parsed' => $parsed,
		) );

		if ( ! is_array( $parsed ) ) {
			return $singular;
		}

		// Build result array indexed 0..nplurals-1.
		$result = array();
		for ( $i = 0; $i < $nplurals; $i++ ) {
			$key = 'form' . $i;
			if ( isset( $parsed[ $key ] ) && ! empty( $parsed[ $key ] ) ) {
				$result[ $i ] = $parsed[ $key ];
			} else {
				// Fallback: if the model returned different keys, try numeric index.
				return $singular;
			}
		}

		return $result;
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

		// Get locale plural info.
		$locale_obj = GP_Locales::by_slug( is_object( $locale ) ? $locale->slug : $locale );
		$nplurals   = $locale_obj ? $locale_obj->nplurals : 2;

		// Separate singular and plural originals.
		$singular_originals = array();
		$plural_originals   = array();

		foreach ( $bulk['row-ids'] as $row_id ) {
			$original_id = gp_array_get( explode( '-', $row_id ), 0 );
			$original    = GP::$original->get( $original_id );

			if ( ! $original ) {
				++$count['skipped'];
				continue;
			}

			if ( $original->plural ) {
				$plural_originals[] = $original;
			} else {
				$singular_originals[] = $original;
				$singulars[]          = $original->singular;
				$contexts[]           = $original->comment ?? '';
				$original_ids[]       = $original_id;
			}
		}

		// Batch translate singular strings.
		if ( ! empty( $singulars ) ) {
			$results = $this->translate_batch( $locale, $singulars, $contexts, $original_ids, $project->id );

			if ( is_wp_error( $results ) ) {
				gp_notice_set( $results->get_error_message(), 'error' );
				return;
			}

			$items = gp_array_zip( $original_ids, $singulars, $results );

			if ( $items ) {
				foreach ( $items as $item ) {
					list( $original_id, $singular, $translation ) = $item;

					if ( is_wp_error( $translation ) ) {
						++$count['err_api'];
						continue;
					}

					$warnings = GP::$translation_warnings->check( $singular, null, array( $translation ), $locale );

					$data = array(
						'original_id'        => $original_id,
						'user_id'            => get_current_user_id(),
						'translation_set_id' => $translation_set->id,
						'translation_0'      => $translation,
						'status'             => 'fuzzy',
						'warnings'           => $warnings,
					);

					$inserted = GP::$translation->create( $data );
					if ( $inserted ) {
						++$count['added'];
					} else {
						++$count['err_add'];
					}
				}
			}
		}

		// Translate plural strings one by one.
		foreach ( $plural_originals as $original ) {
			$result = $this->translate_plural(
				$original->singular,
				$original->plural,
				is_object( $locale ) ? $locale->slug : $locale,
				$nplurals,
				$original->comment ?? '',
				(int) $original->id,
				$project->id
			);

			if ( ! is_array( $result ) ) {
				++$count['err_api'];
				continue;
			}

			$data = array(
				'original_id'        => $original->id,
				'user_id'            => get_current_user_id(),
				'translation_set_id' => $translation_set->id,
				'status'             => 'fuzzy',
			);

			$translation_array = array();
			for ( $i = 0; $i < $nplurals; $i++ ) {
				$data[ 'translation_' . $i ] = $result[ $i ] ?? '';
				$translation_array[]         = $result[ $i ] ?? '';
			}

			$warnings         = GP::$translation_warnings->check( $original->singular, $original->plural, $translation_array, $locale );
			$data['warnings'] = $warnings;

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
