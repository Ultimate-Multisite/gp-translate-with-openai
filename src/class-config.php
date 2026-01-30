<?php
/**
 * Config class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Config class.
 */
class Config {

	/**
	 * Get API key.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		// User API key has priority.
		if ( self::get_user_api_key() ) {
			return self::get_user_api_key();
		}

		// Get the global key.
		return get_option( 'gpoai_api_key' );
	}

	/**
	 * Get user API key.
	 *
	 * @return string
	 */
	public static function get_user_api_key(): string {
		$user_id = self::get_current_user_id();

		// No user ID.
		if ( ! $user_id ) {
			return '';
		}

		// Get the user meta.
		$user_api_key = get_user_meta( $user_id, 'gpoai_api_key', true );

		return $user_api_key;
	}

	/**
	 * Get the model.
	 *
	 * @return string
	 */
	public static function get_model(): string {
		// User model has priority.
		if ( self::get_user_model() ) {
			return self::get_user_model();
		}

		// Get the global model.
		return get_option( 'gpoai_model' );
	}

	/**
	 * Get user model.
	 *
	 * @return string
	 */
	public static function get_user_model(): string {
		$user_id = self::get_current_user_id();

		// No user ID.
		if ( ! $user_id ) {
			return '';
		}

		// Get the user meta.
		$user_model = get_user_meta( $user_id, 'gpoai_model', true );

		return $user_model;
	}

	/**
	 * Get the temperature.
	 *
	 * @return float
	 */
	public static function get_temperature(): float {
		// User temperature has priority.
		if ( self::get_user_temperature() ) {
			return self::get_user_temperature();
		}

		// Get the global temperature.
		return (float) get_option( 'gpoai_temperature' );
	}

	/**
	 * Get user temperature.
	 *
	 * @return float
	 */
	public static function get_user_temperature(): float {
		$user_id = self::get_current_user_id();

		// No user ID.
		if ( ! $user_id ) {
			return 0.0;
		}

		// Get the user meta.
		$user_temperature = get_user_meta( $user_id, 'gpoai_temperature', true );

		return (float) $user_temperature;
	}

	/**
	 * Default system prompt template.
	 *
	 * @var string
	 */
	const DEFAULT_SYSTEM_PROMPT = 'You are a professional translation engine. Translating a WordPress plugin.
Translate the text from {SOURCE_LANGUAGE} to {TARGET_LANGUAGE}.
Return ONLY the translated text.
Use natural language.
Do not explain.
Do not add quotes.
Preserve punctuation, formatting, HTML, placeholders (%s, %1$s, %2$d), and variables exactly.
{LOCALE_INSTRUCTIONS}
{GLOSSARY}
Neighboring strings from the same project for context: {NEIGHBORING_STRINGS}
{CONTEXT}';

	/**
	 * Get system prompt.
	 *
	 * @return string
	 */
	public static function get_system_prompt(): string {
		// User prompt has priority.
		$user_prompt = self::get_user_system_prompt();
		if ( ! empty( $user_prompt ) ) {
			return $user_prompt;
		}

		// Get the global prompt.
		$prompt = get_option( 'gpoai_custom_prompt', '' );

		if ( empty( $prompt ) ) {
			return self::DEFAULT_SYSTEM_PROMPT;
		}

		return $prompt;
	}

	/**
	 * Get user system prompt.
	 *
	 * @return string
	 */
	public static function get_user_system_prompt(): string {
		$user_id = self::get_current_user_id();

		// No user ID.
		if ( ! $user_id ) {
			return '';
		}

		// Get the user meta.
		$user_custom_prompt = get_user_meta( $user_id, 'gpoai_custom_prompt', true );

		return (string) $user_custom_prompt;
	}

	/**
	 * Get locale-specific translation instructions.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return string Formatted instructions or empty string.
	 */
	public static function get_locale_instructions( string $locale ): string {
		$instructions = Locale_Instructions::get_instructions( $locale );

		if ( empty( $instructions ) ) {
			return '';
		}

		return $instructions;
	}

	/**
	 * Get custom prompt.
	 *
	 * @deprecated Use get_system_prompt() instead.
	 *
	 * @return string
	 */
	public static function get_custom_prompt(): string {
		return self::get_system_prompt();
	}

	/**
	 * Get current user ID.
	 *
	 * @return int
	 */
	public static function get_current_user_id(): int {
		$user_id = 0;

		if ( is_user_logged_in() ) {
			$user    = wp_get_current_user();
			$user_id = $user->ID;
		}

		return $user_id;
	}

	/**
	 * Get base URL.
	 *
	 * @return string
	 */
	public static function get_base_url(): string {
		// User base URL has priority.
		$user_base_url = self::get_user_base_url();
		if ( ! empty( $user_base_url ) ) {
			return $user_base_url;
		}

		// Get the global base URL.
		return (string) get_option( 'gpoai_base_url', '' );
	}

	/**
	 * Get user base URL.
	 *
	 * @return string
	 */
	public static function get_user_base_url(): string {
		$user_id = self::get_current_user_id();

		// No user ID.
		if ( ! $user_id ) {
			return '';
		}

		// Get the user meta.
		$user_base_url = get_user_meta( $user_id, 'gpoai_base_url', true );

		return (string) $user_base_url;
	}

	/**
	 * Get whether to use glossary.
	 *
	 * @return bool
	 */
	public static function get_use_glossary(): bool {
		return (bool) get_option( 'gpoai_use_glossary', true );
	}

	/**
	 * Get whether automation is enabled.
	 *
	 * @return bool
	 */
	public static function get_automation_enabled(): bool {
		return (bool) get_option( 'gpoai_automation_enabled', false );
	}

	/**
	 * Get maximum concurrent requests.
	 *
	 * @return int
	 */
	public static function get_max_concurrent_requests(): int {
		$value = (int) get_option( 'gpoai_max_concurrent_requests', 1 );

		return max( 1, min( 20, $value ) );
	}

	/**
	 * Get automation locales.
	 *
	 * @return array
	 */
	public static function get_automation_locales(): array {
		$locales = get_option( 'gpoai_automation_locales', array() );

		if ( ! is_array( $locales ) ) {
			return array();
		}

		return $locales;
	}
}
