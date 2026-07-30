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
	 * Accumulated token usage across translate_batch/translate_strings calls.
	 *
	 * @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
	 */
	private array $accumulated_usage = [
		'prompt_tokens'     => 0,
		'completion_tokens' => 0,
		'total_tokens'      => 0,
	];

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
	 * Get the accumulated token usage since the last reset.
	 *
	 * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
	 */
	public function get_accumulated_usage(): array {
		return $this->accumulated_usage;
	}

	/**
	 * Reset accumulated token usage counters.
	 *
	 * Call this before starting a batch of translations to get accurate
	 * per-job token counts.
	 *
	 * @return void
	 */
	public function reset_usage(): void {
		$this->accumulated_usage = [
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		];
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
		$locale_instructions = $this->build_locale_instructions( $locale, $project_id );

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
	 * Maximum number of strings to send in a single batched API request.
	 *
	 * @var int
	 */
	const BATCH_REQUEST_SIZE = 20;

	/**
	 * Build glossary-aware chat messages for a batch translation request.
	 *
	 * External translation providers can reuse this helper to share the same
	 * locale, context, glossary, and locale-instruction prompt construction as
	 * gp-openai-translate while sending the request through their own transport.
	 *
	 * @param array  $strings    Array of strings to translate.
	 * @param string $locale     The locale to translate to.
	 * @param array  $contexts   Optional array of translator comment contexts, keyed by index.
	 * @param int    $project_id Optional project ID (used for glossary context).
	 *
	 * @return array<int,array{role:string,content:string}> OpenAI-compatible chat messages.
	 */
	public function build_batch_messages( array $strings, string $locale, array $contexts = array(), int $project_id = 0 ): array {
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

		// Build glossary from the union of all matching terms across all strings.
		$glossary_text = '';
		if ( Config::get_use_glossary() ) {
			$all_terms = array();
			foreach ( $strings as $text ) {
				$matching = Glossary::find_matching_terms( $text, $locale );
				if ( ! empty( $matching ) ) {
					foreach ( $matching as $term ) {
						// Deduplicate by source term.
						$key = $term['term'] ?? $term['source'] ?? '';
						if ( ! empty( $key ) ) {
							$all_terms[ $key ] = $term;
						}
					}
				}
			}
			if ( ! empty( $all_terms ) ) {
				$glossary_text = Glossary::format_for_prompt( array_values( $all_terms ) );
			}
		}

		// Build per-string context annotations.
		$context_parts = array();
		foreach ( $contexts as $index => $ctx ) {
			if ( ! empty( $ctx ) && isset( $strings[ $index ] ) ) {
				$context_parts[] = sprintf( 'String %d context: %s.', $index, $ctx );
			}
		}
		$context_text = implode( ' ', $context_parts );

		// Build locale instructions.
		$locale_instructions = $this->build_locale_instructions( $locale, $project_id );

		// Build system prompt — neighboring strings omitted since the batch itself provides context.
		$system_prompt = Config::get_system_prompt();
		$system_prompt = str_replace(
			array( '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}', '{CONTEXT}', '{GLOSSARY}', '{LOCALE_INSTRUCTIONS}', '{NEIGHBORING_STRINGS}' ),
			array( 'English', $locale_name, $context_text, $glossary_text, $locale_instructions, '' ),
			$system_prompt
		);
		$system_prompt = preg_replace( '/\s+/', ' ', trim( $system_prompt ) );

		// Append batch-specific instructions to the system prompt.
		$system_prompt .= ' You will receive multiple numbered strings to translate. '
			. 'Return ONLY a JSON object mapping each number to its translation. '
			. 'Example: {"0": "translated text", "1": "another translation"}. '
			. 'Do not include any explanation, markdown formatting, or code blocks.';

		// Build the numbered user message.
		$lines = array();
		foreach ( $strings as $index => $text ) {
			$lines[] = sprintf( '%d: %s', $index, $text );
		}

		return array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
			array(
				'role'    => 'user',
				'content' => implode( "\n", $lines ),
			),
		);
	}

	/**
	 * Translate multiple strings in a single API request.
	 *
	 * Sends up to BATCH_REQUEST_SIZE strings to the API in one call, asking the model
	 * to return a JSON object mapping numeric indices to translations. Falls back to
	 * single-string translation if the batched response cannot be parsed.
	 *
	 * @param array  $strings      Array of strings to translate.
	 * @param string $locale       The locale to translate to.
	 * @param array  $contexts     Optional array of translator comment contexts, keyed by index.
	 * @param int    $project_id   Optional project ID (used for glossary context).
	 *
	 * @return array Array of translated strings in the same order as input, or originals on failure.
	 */
	protected function translate_strings( array $strings, string $locale, array $contexts = array(), int $project_id = 0 ): array {
		if ( empty( $strings ) || ! Locales::is_supported( $locale ) ) {
			return $strings;
		}

		$api_key = Config::get_api_key();
		$openai  = new OpenAi( ! empty( $api_key ) ? $api_key : 'ollama' );

		$base_url = Config::get_base_url();
		if ( ! empty( $base_url ) ) {
			$openai->setBaseURL( $base_url );
		}

		$messages = $this->build_batch_messages( $strings, $locale, $contexts, $project_id );

		// Scale max_tokens proportionally to the number of strings.
		$max_tokens = min( 4096, 200 * count( $strings ) );

		$request = array(
			'model'             => Config::get_model(),
			'messages'          => $messages,
			'temperature'       => Config::get_temperature(),
			'max_tokens'        => $max_tokens,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
		);

		self::debug( 'BATCH_REQUEST', array(
			'string_count' => count( $strings ),
			'max_tokens'   => $max_tokens,
			'body'         => $request,
		) );

		try {
			$chat     = $openai->chat( $request );
			$response = json_decode( $chat );
		} catch ( \Exception $e ) {
			self::debug( 'BATCH_ERROR', $e->getMessage() );
			return $strings;
		}

		self::debug( 'BATCH_RESPONSE', array(
			'raw'    => $chat,
			'parsed' => $response,
		) );

		// Check for API error.
		if ( isset( $response->error ) ) {
			self::debug( 'BATCH_ERROR', array(
				'code'    => $response->error->code ?? 'unknown',
				'message' => $response->error->message ?? 'Unknown error',
			) );
			return $strings;
		}

		// Validate response structure.
		if ( ! $response || ! isset( $response->choices[0]->message->content ) ) {
			self::debug( 'BATCH_ERROR', 'Invalid response structure' );
			return $strings;
		}

		$content = trim( $response->choices[0]->message->content );

		// Strip markdown code fences if present.
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		$parsed = json_decode( $content, true );

		self::debug( 'BATCH_PARSED', $parsed );

		if ( ! is_array( $parsed ) ) {
			self::debug( 'BATCH_ERROR', 'Failed to parse JSON response, falling back to single-string translation' );
			// Fallback: translate each string individually.
			$fallback = array();
			foreach ( $strings as $index => $text ) {
				$ctx        = $contexts[ $index ] ?? '';
				$fallback[] = $this->translate( $text, $locale, $ctx, 0, $project_id );
			}
			return $fallback;
		}

		// Map parsed results back to the original indices.
		$results = array();
		foreach ( $strings as $index => $text ) {
			$key = (string) $index;
			if ( isset( $parsed[ $key ] ) && ! empty( $parsed[ $key ] ) ) {
				$results[] = (string) $parsed[ $key ];
			} else {
				// If a specific string is missing from the response, translate it individually.
				self::debug( 'BATCH_MISSING', sprintf( 'Index %d missing from batch response, translating individually', $index ) );
				$ctx       = $contexts[ $index ] ?? '';
				$results[] = $this->translate( $text, $locale, $ctx, 0, $project_id );
			}
		}

		// Accumulate token usage from this API call.
		if ( isset( $response->usage ) ) {
			$this->accumulated_usage['prompt_tokens']     += (int) ( $response->usage->prompt_tokens ?? 0 );
			$this->accumulated_usage['completion_tokens'] += (int) ( $response->usage->completion_tokens ?? 0 );
			$this->accumulated_usage['total_tokens']      += (int) ( $response->usage->total_tokens ?? 0 );
		}

		self::debug( 'BATCH_RESULT', array(
			'input_count'  => count( $strings ),
			'output_count' => count( $results ),
			'usage'        => $response->usage ?? null,
		) );

		return $results;
	}

	/**
	 * Build locale and project-specific prompt instructions.
	 *
	 * @param string $locale     The locale to translate to.
	 * @param int    $project_id Optional GlotPress project ID.
	 *
	 * @return string Prompt instructions.
	 */
	protected function build_locale_instructions( string $locale, int $project_id = 0 ): string {
		$instructions = Config::get_locale_instructions( $locale );
		$brand_rule   = $this->get_project_brand_instruction( $project_id );

		if ( '' === $brand_rule ) {
			return $instructions;
		}

		return trim( $instructions . ' ' . $brand_rule );
	}

	/**
	 * Get a project-specific plugin name preservation instruction.
	 *
	 * GlotPress project names are populated from imported plugin metadata, such as
	 * the WordPress plugin header's "Plugin Name" value. Use that stored metadata
	 * instead of reparsing plugin files during translation requests.
	 *
	 * @param int $project_id GlotPress project ID.
	 *
	 * @return string Prompt instruction, or an empty string when unavailable.
	 */
	protected function get_project_brand_instruction( int $project_id ): string {
		if ( $project_id <= 0 || ! class_exists( 'GP' ) || ! isset( GP::$project ) ) {
			return '';
		}

		$project = GP::$project->get( $project_id );
		if ( ! $project || empty( $project->name ) ) {
			return '';
		}

		$plugin_name = html_entity_decode( wp_strip_all_tags( (string) $project->name ), ENT_QUOTES, 'UTF-8' );
		$plugin_name = preg_replace( '/\s+/', ' ', trim( $plugin_name ) );

		if ( '' === $plugin_name || false !== strpos( $plugin_name, '{{' ) || strlen( $plugin_name ) > 120 ) {
			return '';
		}

		if ( ! preg_match( '/[A-Za-z0-9]/', $plugin_name ) ) {
			return '';
		}

		$encoded_name = wp_json_encode( $plugin_name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded_name ) {
			return '';
		}

		return sprintf(
			'Plugin name rule: when the exact plugin name %1$s appears in the source text, preserve it exactly as %1$s in the translation. Do not translate, reorder, abbreviate, or substitute this plugin name.',
			$encoded_name
		);
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
		$locale_instructions = $this->build_locale_instructions( $locale, $project_id );

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
	 * Strings are batched into groups of BATCH_REQUEST_SIZE and sent as a single API
	 * request per group. This reduces API calls by up to 20x compared to per-string requests.
	 *
	 * @param string $locale      The locale to translate to.
	 * @param array  $strings     The strings to translate.
	 * @param array  $contexts    The contexts for each string.
	 * @param array  $original_ids The original IDs for each string.
	 * @param int    $project_id  The project ID.
	 *
	 * @return array|WP_Error The translated strings or an error.
	 */
	protected function openai_translate_batch( $locale, $strings, $contexts = array(), $original_ids = array(), $project_id = 0 ) {
		if ( ! Locales::is_supported( $locale ) ) {
			return new WP_Error( 'gpoai_translate', sprintf( "The locale %s isn't supported by OpenAI.", $locale ) );
		}

		if ( count( $strings ) === 0 ) {
			return new WP_Error( 'gpoai_translate', 'No strings found to translate.' );
		}

		if ( count( $strings ) > 50 ) {
			return new WP_Error( 'gpoai_translate', 'Only 50 strings allowed.' );
		}

		// Split strings into chunks of BATCH_REQUEST_SIZE and translate each chunk
		// in a single API request.
		$translated_strings = array();
		$chunks             = array_chunk( $strings, self::BATCH_REQUEST_SIZE, true );

		foreach ( $chunks as $chunk ) {
			// Build contexts subset for this chunk.
			$chunk_contexts = array();
			foreach ( array_keys( $chunk ) as $index ) {
				if ( isset( $contexts[ $index ] ) && ! empty( $contexts[ $index ] ) ) {
					$chunk_contexts[ $index ] = $contexts[ $index ];
				}
			}

			// Re-index the chunk to 0-based for translate_strings().
			$chunk_values   = array_values( $chunk );
			$chunk_keys     = array_keys( $chunk );
			$reindexed_ctxs = array();
			foreach ( $chunk_contexts as $orig_index => $ctx ) {
				$new_index = array_search( $orig_index, $chunk_keys, true );
				if ( false !== $new_index ) {
					$reindexed_ctxs[ $new_index ] = $ctx;
				}
			}

			$chunk_results = $this->translate_strings( $chunk_values, $locale, $reindexed_ctxs, (int) $project_id );

			// Map results back to original indices.
			foreach ( $chunk_keys as $position => $original_index ) {
				$translated_strings[ $original_index ] = $chunk_results[ $position ] ?? $strings[ $original_index ];
			}
		}

		// Re-index to sequential array.
		ksort( $translated_strings );
		$translated_strings = array_values( $translated_strings );

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
		$locale_instructions = $this->build_locale_instructions( $locale, $project_id );

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
	 * Handles reasoning model output (e.g. DeepSeek) which may include chain-of-thought
	 * before the actual translation. Strips <think>...</think> blocks and detects
	 * leaked reasoning by looking for common reasoning phrases, falling back to the
	 * last non-empty paragraph as the actual translation.
	 *
	 * @param string $text The string to clean.
	 *
	 * @return string
	 */
	protected function clean_translation( $text ) {
		// Strip <think>...</think> blocks emitted by reasoning models (e.g. DeepSeek-R1).
		$text = preg_replace( '/<think>.*?<\/think>/si', '', $text );
		$text = trim( $text );

		// Detect leaked reasoning: if the text contains common reasoning phrases,
		// extract only the last non-empty paragraph as the actual translation.
		$reasoning_pattern = '/\b(I need to translate|Let me (translate|break|think|analyze|consider)|'
			. 'Step by step|First,? (the|I|let|we)|Now,? (let|I|we)|'
			. 'The (original|instruction|glossary|sentence|text) (says?|is|has|contains|specifies?)|'
			. 'In Romanian,|Translate (each|step|the)|'
			. 'According to the glossary|The glossary (says?|specifies?)|'
			. 'I (must|should|will|am going to|need to) (translate|use|follow|adhere|stick|proceed|check))\b/i';

		if ( preg_match( $reasoning_pattern, $text ) ) {
			// Split on double newlines (paragraphs) and take the last non-empty one.
			$paragraphs = preg_split( '/\n{2,}/', $text );
			$paragraphs = array_filter( array_map( 'trim', $paragraphs ) );
			if ( ! empty( $paragraphs ) ) {
				$text = end( $paragraphs );
			}
		}

		// Fix printf placeholders broken by the model inserting spaces (e.g. "% s" -> "%s").
		$text = preg_replace_callback(
			'/% (s|d)/i',
			function ( $m ) { // phpcs:ignore
				return '%' . strtolower( $m[1] );
			},
			$text
		);
		$text = preg_replace_callback(
			'/% (\d+) \$ (s|d)/i',
			function ( $m ) { // phpcs:ignore
				return '%' . $m[1] . '$' . strtolower( $m[2] );
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
