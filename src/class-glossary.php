<?php
/**
 * Glossary class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

use GP;
use GP_Glossary;
use GP_Glossary_Entry;
use GP_Locales;

/**
 * Glossary class for handling GlotPress glossary integration.
 */
class Glossary {

	/**
	 * Transient prefix for caching.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'gpoai_glossary_';

	/**
	 * Cache expiry in seconds (24 hours).
	 *
	 * @var int
	 */
	const CACHE_EXPIRY = DAY_IN_SECONDS;

	/**
	 * Get glossary entries for a specific locale.
	 *
	 * Uses GlotPress native glossary tables directly.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return array Array of glossary entries.
	 */
	public static function get_entries_for_locale( string $locale ): array {
		// Check transient cache first.
		$cache_key = self::TRANSIENT_PREFIX . $locale;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$entries = array();

		// Require GlotPress.
		if ( ! class_exists( 'GP' ) || ! class_exists( 'GP_Glossary_Entry' ) ) {
			return $entries;
		}

		// Get the locale object from GlotPress.
		$locale_obj = GP_Locales::by_slug( $locale );
		if ( ! $locale_obj ) {
			return $entries;
		}

		// Get the locale-level glossary (project_id = 0).
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( 0, 'default', $locale );
		if ( ! $translation_set ) {
			return $entries;
		}

		$glossary = GP::$glossary->by_set_id( $translation_set->id );
		if ( ! $glossary ) {
			return $entries;
		}

		$glossary_entries = GP::$glossary_entry->find_many( array( 'glossary_id' => $glossary->id ) );
		if ( empty( $glossary_entries ) ) {
			return $entries;
		}

		foreach ( $glossary_entries as $entry ) {
			$entries[] = array(
				'term'           => $entry->term,
				'translation'    => $entry->translation,
				'part_of_speech' => $entry->part_of_speech,
				'comment'        => $entry->comment,
			);
		}

		// Cache the results.
		set_transient( $cache_key, $entries, self::CACHE_EXPIRY );

		return $entries;
	}

	/**
	 * Find matching glossary terms in the given text.
	 *
	 * @param string $text   The text to search for terms.
	 * @param string $locale The target locale.
	 *
	 * @return array Array of matching glossary entries.
	 */
	public static function find_matching_terms( string $text, string $locale ): array {
		// If GlotPress is not available, return empty array.
		if ( ! class_exists( 'GP' ) ) {
			return array();
		}

		$entries        = self::get_entries_for_locale( $locale );
		$matching_terms = array();
		$seen           = array();
		$text_lower     = mb_strtolower( $text );

		foreach ( $entries as $entry ) {
			if ( empty( $entry['term'] ) || empty( $entry['translation'] ) ) {
				continue;
			}

			// Dedup by term + translation + part_of_speech.
			$key = mb_strtolower( $entry['term'] ) . '|' . mb_strtolower( $entry['translation'] ) . '|' . ( $entry['part_of_speech'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$term_lower = mb_strtolower( $entry['term'] );

			// Use word boundary matching (case-insensitive).
			$pattern = '/\b' . preg_quote( $term_lower, '/' ) . '\b/ui';

			if ( preg_match( $pattern, $text_lower ) ) {
				$matching_terms[] = $entry;
				$seen[ $key ]     = true;
			}
		}

		return $matching_terms;
	}

	/**
	 * Format glossary entries for inclusion in the translation prompt.
	 *
	 * @param array $entries Array of glossary entries.
	 *
	 * @return string Formatted glossary context for the prompt.
	 */
	public static function format_for_prompt( array $entries ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		$formatted_entries = array();
		foreach ( $entries as $entry ) {
			$term_string = sprintf( '"%s" = "%s"', $entry['term'], $entry['translation'] );
			if ( ! empty( $entry['part_of_speech'] ) ) {
				$term_string .= sprintf( ' (%s)', $entry['part_of_speech'] );
			}
			if ( ! empty( $entry['comment'] ) ) {
				$term_string .= sprintf( ' [%s]', $entry['comment'] );
			}
			$formatted_entries[] = $term_string;
		}

		return 'Use these glossary terms: ' . implode( ', ', $formatted_entries ) . '.';
	}

	/**
	 * Import glossary entries from WordPress.org into GlotPress native glossary.
	 *
	 * Downloads the CSV from translate.wordpress.org and imports entries
	 * using the same approach as GlotPress's native import.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return int Number of entries imported, or -1 on error.
	 */
	public static function import_from_wporg( string $locale ): int {
		// Require GlotPress.
		if ( ! class_exists( 'GP' ) || ! class_exists( 'GP_Glossary' ) || ! class_exists( 'GP_Glossary_Entry' ) ) {
			return -1;
		}

		// Find or create a glossary for this locale.
		$glossary = self::get_or_create_glossary_for_locale( $locale );
		if ( ! $glossary ) {
			return -1;
		}

		// Download the CSV from WordPress.org.
		$csv_content = self::download_wporg_glossary_csv( $locale );
		if ( empty( $csv_content ) ) {
			return 0;
		}

		// Write to a temp file so we can use fgetcsv like GlotPress does.
		$tmp_file = wp_tempnam( 'gpoai_glossary_' );
		file_put_contents( $tmp_file, $csv_content );

		$imported = self::import_csv_to_glossary( $tmp_file, $glossary->id, $locale );

		unlink( $tmp_file );

		// Clear cache for this locale.
		delete_transient( self::TRANSIENT_PREFIX . $locale );

		// Update last import timestamp.
		$import_times            = get_option( 'gpoai_glossary_import_times', array() );
		$import_times[ $locale ] = time();
		update_option( 'gpoai_glossary_import_times', $import_times );

		return $imported;
	}

	/**
	 * Import a CSV file into a GlotPress glossary using native GlotPress methods.
	 *
	 * Mirrors GlotPress's own read_glossary_entries_from_file() logic.
	 *
	 * @param string $file        Path to the CSV file.
	 * @param int    $glossary_id The glossary ID.
	 * @param string $locale      The locale slug.
	 *
	 * @return int Number of entries imported.
	 */
	protected static function import_csv_to_glossary( string $file, int $glossary_id, string $locale ): int {
		$f = fopen( $file, 'r' );
		if ( ! $f ) {
			return 0;
		}

		$imported = 0;

		// Read and validate header.
		$header = fgetcsv( $f, 0, ',' );
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			fclose( $f );
			return 0;
		}

		// Resolve user ID once. In CLI context get_current_user_id() returns 0.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$user_id = ! empty( $admins ) ? (int) $admins[0] : 1;
		}

		// WordPress.org CSV header is: en, <locale>, pos, description.
		// GlotPress validates that header[1] matches locale slug.

		while ( ( $data = fgetcsv( $f, 0, ',' ) ) !== false ) {
			if ( count( $data ) < 4 ) {
				continue;
			}

			// Match GlotPress's native logic: if more than 4 columns, splice.
			if ( count( $data ) > 4 ) {
				$data = array_splice( $data, 2, -2 );
			}

			$entry_data = array(
				'glossary_id'    => $glossary_id,
				'term'           => $data[0],
				'translation'    => $data[1],
				'part_of_speech' => $data[2],
				'comment'        => $data[3],
				'last_edited_by' => $user_id,
			);

			// Use GlotPress native validation.
			$new_entry = new GP_Glossary_Entry( $entry_data );
			if ( ! $new_entry->validate() ) {
				continue;
			}

			// Check if entry already exists (GlotPress native duplicate check).
			$existing = GP::$glossary_entry->find_one( $entry_data );
			if ( $existing ) {
				continue;
			}

			$created = GP::$glossary_entry->create_and_select( $new_entry );
			if ( $created ) {
				++$imported;
			}
		}

		fclose( $f );

		return $imported;
	}

	/**
	 * Download glossary CSV from WordPress.org.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return string CSV content or empty string on failure.
	 */
	public static function download_wporg_glossary_csv( string $locale ): string {
		$wporg_locale = self::convert_locale_to_wporg( $locale );
		if ( empty( $wporg_locale ) ) {
			return '';
		}

		$url = sprintf(
			'https://translate.wordpress.org/locale/%s/default/glossary/-export/',
			$wporg_locale
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'text/csv',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch glossary data from WordPress.org as parsed array.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return array Array of glossary entries.
	 */
	public static function fetch_wporg_glossary( string $locale ): array {
		$csv_content = self::download_wporg_glossary_csv( $locale );
		if ( empty( $csv_content ) ) {
			return array();
		}

		return self::parse_csv_glossary( $csv_content );
	}

	/**
	 * Parse CSV glossary content into an array.
	 *
	 * @param string $csv_content The CSV content.
	 *
	 * @return array Array of glossary entries.
	 */
	protected static function parse_csv_glossary( string $csv_content ): array {
		$entries = array();
		$lines   = explode( "\n", $csv_content );

		$header = null;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$row = str_getcsv( $line, ',', '"', '' );

			// First non-empty line is the header.
			if ( null === $header ) {
				$header = array_map( 'strtolower', $row );
				continue;
			}

			if ( count( $row ) < 2 ) {
				continue;
			}

			// WordPress.org CSV: en, <locale>, pos, description (positional).
			$entries[] = array(
				'term'           => $row[0] ?? '',
				'translation'    => $row[1] ?? '',
				'part_of_speech' => $row[2] ?? '',
				'comment'        => $row[3] ?? '',
			);
		}

		return array_filter( $entries, fn( $e ) => ! empty( $e['term'] ) );
	}

	/**
	 * Convert a GlotPress locale slug to WordPress.org format.
	 *
	 * @param string $locale The GlotPress locale slug.
	 *
	 * @return string The WordPress.org locale slug.
	 */
	protected static function convert_locale_to_wporg( string $locale ): string {
		$mappings = array(
			'de' => 'de',
			'fr' => 'fr',
			'es' => 'es',
			'it' => 'it',
			'nl' => 'nl',
			'pt' => 'pt-br',
			'ru' => 'ru',
			'ja' => 'ja',
			'zh' => 'zh-cn',
			'ko' => 'ko',
			'ar' => 'ar',
			'he' => 'he',
			'pl' => 'pl',
			'tr' => 'tr',
			'sv' => 'sv',
			'da' => 'da',
			'fi' => 'fi',
			'no' => 'nb',
			'cs' => 'cs',
			'hu' => 'hu',
			'ro' => 'ro',
			'sk' => 'sk',
			'uk' => 'uk',
			'bg' => 'bg',
			'hr' => 'hr',
			'el' => 'el',
			'id' => 'id',
			'vi' => 'vi',
			'th' => 'th',
			'hi' => 'hi',
		);

		return $mappings[ $locale ] ?? $locale;
	}

	/**
	 * Get or create a GlotPress native glossary for a specific locale.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return GP_Glossary|null The glossary object or null on failure.
	 */
	protected static function get_or_create_glossary_for_locale( string $locale ) {
		// Use project_id = 0 for locale-level glossary (GlotPress convention).
		// This is the glossary shown at /languages/{locale}/default/glossary/.
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( 0, 'default', $locale );

		if ( ! $translation_set ) {
			return null;
		}

		// Check for existing glossary.
		$glossary = GP::$glossary->by_set_id( $translation_set->id );
		if ( $glossary ) {
			return $glossary;
		}

		// Create a new glossary for this locale translation set.
		return GP::$glossary->create(
			array(
				'translation_set_id' => $translation_set->id,
			)
		);
	}

	/**
	 * Get the last import time for a locale.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return int|null Unix timestamp or null if never imported.
	 */
	public static function get_last_import_time( string $locale ): ?int {
		$import_times = get_option( 'gpoai_glossary_import_times', array() );

		return $import_times[ $locale ] ?? null;
	}

	/**
	 * Clear the glossary cache for a specific locale.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return void
	 */
	public static function clear_cache( string $locale ): void {
		delete_transient( self::TRANSIENT_PREFIX . $locale );
	}

	/**
	 * Clear all glossary caches.
	 *
	 * @return void
	 */
	public static function clear_all_caches(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::TRANSIENT_PREFIX . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_timeout_' . self::TRANSIENT_PREFIX . '%'
			)
		);
	}
}
