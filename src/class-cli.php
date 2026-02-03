<?php
/**
 * CLI class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

use GP;
use WP_CLI;
use WP_CLI\Utils;

/**
 * CLI commands for GP Translate with OpenAI.
 */
class CLI {

	/**
	 * Enable debug output on the Translate class.
	 *
	 * @return void
	 */
	protected function enable_debug(): void {
		Translate::set_debug( true, function( string $label, $data ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%M[DEBUG] ' . $label . ':%n' ) );

			if ( is_string( $data ) ) {
				WP_CLI::log( '  ' . $data );
				return;
			}

			if ( is_array( $data ) || is_object( $data ) ) {
				$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				// Indent each line.
				$lines = explode( "\n", $json );
				foreach ( $lines as $line ) {
					WP_CLI::log( '  ' . $line );
				}
				return;
			}

			WP_CLI::log( '  ' . var_export( $data, true ) );
		} );
	}

	/**
	 * Test translation quality by comparing AI translations with human translations from WordPress.org.
	 *
	 * ## OPTIONS
	 *
	 * <locale>
	 * : The locale to test (e.g., de, fr, es).
	 *
	 * [--count=<number>]
	 * : Number of strings to test.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--model=<model>]
	 * : The model to use for translation. Defaults to configured model.
	 *
	 * [--prompt=<prompt>]
	 * : Custom prompt to use. Defaults to configured prompt.
	 *
	 * [--base-url=<url>]
	 * : Base URL for the API (e.g., http://localhost:11434 for Ollama).
	 *
	 * [--api-key=<key>]
	 * : API key to use. Defaults to configured key.
	 *
	 * [--no-glossary]
	 * : Disable glossary context.
	 *
	 * [--debug-requests]
	 * : Output the full API request and response for each translation.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Test 20 German translations
	 *     $ wp gpoai quality_test de
	 *
	 *     # Test with debug output
	 *     $ wp gpoai quality_test de --count=3 --debug-requests
	 *
	 *     # Test with Ollama and debug
	 *     $ wp gpoai quality_test de --base-url=http://localhost:11434 --model=llama3.2 --api-key=ollama --debug-requests
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function quality_test( $args, $assoc_args ) {
		$locale          = $args[0];
		$count           = (int) Utils\get_flag_value( $assoc_args, 'count', 20 );
		$model           = Utils\get_flag_value( $assoc_args, 'model', '' );
		$prompt          = Utils\get_flag_value( $assoc_args, 'prompt', '' );
		$base_url        = Utils\get_flag_value( $assoc_args, 'base-url', '' );
		$api_key         = Utils\get_flag_value( $assoc_args, 'api-key', '' );
		$no_glossary     = Utils\get_flag_value( $assoc_args, 'no-glossary', false );
		$debug_requests  = Utils\get_flag_value( $assoc_args, 'debug-requests', false );
		$format          = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( $debug_requests ) {
			$this->enable_debug();
		}

		// Validate locale.
		if ( ! Locales::is_supported( $locale ) ) {
			WP_CLI::error( sprintf( 'Locale "%s" is not supported.', $locale ) );
		}

		$count = max( 1, min( 500, $count ) );

		// Store original config.
		$original_config = array(
			'model'        => get_option( 'gpoai_model' ),
			'prompt'       => get_option( 'gpoai_custom_prompt' ),
			'base_url'     => get_option( 'gpoai_base_url' ),
			'api_key'      => get_option( 'gpoai_api_key' ),
			'use_glossary' => get_option( 'gpoai_use_glossary' ),
		);

		// Apply overrides.
		if ( ! empty( $model ) ) {
			update_option( 'gpoai_model', $model );
		}
		if ( ! empty( $prompt ) ) {
			update_option( 'gpoai_custom_prompt', $prompt );
		}
		if ( ! empty( $base_url ) ) {
			update_option( 'gpoai_base_url', $base_url );
		}
		if ( ! empty( $api_key ) ) {
			update_option( 'gpoai_api_key', $api_key );
		}
		if ( $no_glossary ) {
			update_option( 'gpoai_use_glossary', false );
		}

		// Show configuration.
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%BConfiguration:%n' ) );
		WP_CLI::log( sprintf( '  Locale:    %s', $locale ) );
		WP_CLI::log( sprintf( '  Model:     %s', Config::get_model() ) );
		WP_CLI::log( sprintf( '  Base URL:  %s', Config::get_base_url() ?: '(default OpenAI)' ) );
		WP_CLI::log( sprintf( '  Glossary:  %s', Config::get_use_glossary() ? 'enabled' : 'disabled' ) );
		WP_CLI::log( sprintf( '  Count:     %d', $count ) );
		WP_CLI::log( '' );

		// Fetch test strings.
		WP_CLI::log( 'Fetching test strings from WordPress.org...' );
		$quality_test = new Quality_Test();
		$strings      = $this->fetch_test_strings( $locale, $count );

		if ( empty( $strings ) ) {
			$this->restore_config( $original_config );
			WP_CLI::error( 'Could not fetch test strings from WordPress.org.' );
		}

		WP_CLI::log( sprintf( 'Fetched %d strings. Starting translation test...', count( $strings ) ) );
		WP_CLI::log( '' );

		// Process translations.
		$results   = array();
		$translate = Translate::instance();
		$progress  = Utils\make_progress_bar( 'Translating', count( $strings ) );

		foreach ( $strings as $index => $string ) {
			$ai_translation = $translate->translate( $string['source'], $locale );
			$similarity     = $this->calculate_similarity( $string['translation'], $ai_translation );

			$results[] = array(
				'#'          => $index + 1,
				'source'     => $this->truncate( $string['source'], 40 ),
				'human'      => $this->truncate( $string['translation'], 40 ),
				'ai'         => $this->truncate( $ai_translation, 40 ),
				'similarity' => $similarity . '%',
				'match'      => trim( $string['translation'] ) === trim( $ai_translation ) ? 'YES' : 'no',
				// Full values for JSON output.
				'_source'    => $string['source'],
				'_human'     => $string['translation'],
				'_ai'        => $ai_translation,
			);

			$progress->tick();
		}

		$progress->finish();

		// Restore original config.
		$this->restore_config( $original_config );

		// Calculate summary.
		$exact_matches   = count( array_filter( $results, fn( $r ) => $r['match'] === 'YES' ) );
		$avg_similarity  = array_sum( array_map( fn( $r ) => (int) $r['similarity'], $results ) ) / count( $results );
		$high_similarity = count( array_filter( $results, fn( $r ) => (int) $r['similarity'] >= 90 ) );
		$med_similarity  = count( array_filter( $results, fn( $r ) => (int) $r['similarity'] >= 70 && (int) $r['similarity'] < 90 ) );
		$low_similarity  = count( array_filter( $results, fn( $r ) => (int) $r['similarity'] < 70 ) );

		// Output results.
		WP_CLI::log( '' );

		if ( 'json' === $format ) {
			$json_results = array_map( function( $r ) {
				return array(
					'source'     => $r['_source'],
					'human'      => $r['_human'],
					'ai'         => $r['_ai'],
					'similarity' => (int) $r['similarity'],
					'match'      => $r['match'] === 'YES',
				);
			}, $results );

			WP_CLI::log( json_encode( array(
				'summary' => array(
					'total'           => count( $results ),
					'exact_matches'   => $exact_matches,
					'avg_similarity'  => round( $avg_similarity, 1 ),
					'high_similarity' => $high_similarity,
					'med_similarity'  => $med_similarity,
					'low_similarity'  => $low_similarity,
				),
				'results' => $json_results,
			), JSON_PRETTY_PRINT ) );
		} else {
			// Table or CSV format.
			$table_results = array_map( function( $r ) {
				unset( $r['_source'], $r['_human'], $r['_ai'] );
				return $r;
			}, $results );

			Utils\format_items( $format, $table_results, array( '#', 'source', 'human', 'ai', 'similarity', 'match' ) );

			// Summary.
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%BSummary:%n' ) );
			WP_CLI::log( sprintf( '  Strings tested:         %d', count( $results ) ) );
			WP_CLI::log( sprintf( '  Exact matches:          %d (%d%%)', $exact_matches, round( $exact_matches / count( $results ) * 100 ) ) );
			WP_CLI::log( sprintf( '  Average similarity:     %.1f%%', $avg_similarity ) );
			WP_CLI::log( WP_CLI::colorize( sprintf( '  High similarity (≥90%%): %%G%d%%n', $high_similarity ) ) );
			WP_CLI::log( WP_CLI::colorize( sprintf( '  Medium (70-89%%):        %%Y%d%%n', $med_similarity ) ) );
			WP_CLI::log( WP_CLI::colorize( sprintf( '  Low (<70%%):             %%R%d%%n', $low_similarity ) ) );
			WP_CLI::log( '' );
		}

		if ( $avg_similarity >= 80 ) {
			WP_CLI::success( sprintf( 'Quality test complete. Average similarity: %.1f%%', $avg_similarity ) );
		} else {
			WP_CLI::warning( sprintf( 'Quality test complete. Average similarity: %.1f%% (below 80%% threshold)', $avg_similarity ) );
		}
	}

	/**
	 * List available models from the configured API.
	 *
	 * ## OPTIONS
	 *
	 * [--base-url=<url>]
	 * : Base URL for the API.
	 *
	 * [--api-key=<key>]
	 * : API key to use.
	 *
	 * [--debug-requests]
	 * : Output the full API request and response.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - list
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List models from configured API
	 *     $ wp gpoai models
	 *
	 *     # List models from Ollama
	 *     $ wp gpoai models --base-url=http://localhost:11434 --api-key=ollama
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function models( $args, $assoc_args ) {
		$base_url        = Utils\get_flag_value( $assoc_args, 'base-url', '' );
		$api_key         = Utils\get_flag_value( $assoc_args, 'api-key', '' );
		$debug_requests  = Utils\get_flag_value( $assoc_args, 'debug-requests', false );
		$format          = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( $debug_requests ) {
			$this->enable_debug();
		}

		// Store and apply overrides.
		$original_base_url = get_option( 'gpoai_base_url' );
		$original_api_key  = get_option( 'gpoai_api_key' );

		if ( ! empty( $base_url ) ) {
			update_option( 'gpoai_base_url', $base_url );
		}
		if ( ! empty( $api_key ) ) {
			update_option( 'gpoai_api_key', $api_key );
		}

		// Clear cache and fetch models.
		Api::clear_models_cache();
		$models = Api::get_available_models( true );

		// Restore config.
		update_option( 'gpoai_base_url', $original_base_url );
		update_option( 'gpoai_api_key', $original_api_key );

		if ( is_wp_error( $models ) ) {
			WP_CLI::error( 'Could not fetch models: ' . $models->get_error_message() );
		}

		if ( 'list' === $format ) {
			foreach ( $models as $model ) {
				WP_CLI::log( $model );
			}
		} else {
			$items = array_map( fn( $m ) => array( 'model' => $m ), $models );
			Utils\format_items( $format, $items, array( 'model' ) );
		}

		WP_CLI::success( sprintf( 'Found %d models.', count( $models ) ) );
	}

	/**
	 * Translate a single string.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : The text to translate.
	 *
	 * <locale>
	 * : The target locale.
	 *
	 * [--model=<model>]
	 * : The model to use.
	 *
	 * [--base-url=<url>]
	 * : Base URL for the API.
	 *
	 * [--api-key=<key>]
	 * : API key to use.
	 *
	 * [--debug-requests]
	 * : Output the full API request and response.
	 *
	 * ## EXAMPLES
	 *
	 *     # Translate to German
	 *     $ wp gpoai translate "Hello, world!" de
	 *
	 *     # Translate with Ollama and debug output
	 *     $ wp gpoai translate "Hello" de --base-url=http://localhost:11434 --model=llama3.2 --debug-requests
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function translate( $args, $assoc_args ) {
		$text            = $args[0];
		$locale          = $args[1];
		$model           = Utils\get_flag_value( $assoc_args, 'model', '' );
		$base_url        = Utils\get_flag_value( $assoc_args, 'base-url', '' );
		$api_key         = Utils\get_flag_value( $assoc_args, 'api-key', '' );
		$debug_requests  = Utils\get_flag_value( $assoc_args, 'debug-requests', false );

		if ( $debug_requests ) {
			$this->enable_debug();
		}

		// Store original config.
		$original_config = array(
			'model'    => get_option( 'gpoai_model' ),
			'base_url' => get_option( 'gpoai_base_url' ),
			'api_key'  => get_option( 'gpoai_api_key' ),
		);

		// Apply overrides.
		if ( ! empty( $model ) ) {
			update_option( 'gpoai_model', $model );
		}
		if ( ! empty( $base_url ) ) {
			update_option( 'gpoai_base_url', $base_url );
		}
		if ( ! empty( $api_key ) ) {
			update_option( 'gpoai_api_key', $api_key );
		}

		$translator  = Translate::instance();
		$translation = $translator->translate( $text, $locale );

		// Restore config.
		$this->restore_config( $original_config );

		WP_CLI::log( $translation );
	}

	/**
	 * Fetch test strings from WordPress.org.
	 *
	 * @param string $locale The locale.
	 * @param int    $count  Number of strings.
	 *
	 * @return array
	 */
	protected function fetch_test_strings( string $locale, int $count ): array {
		$wporg_locale = $this->convert_locale_to_wporg( $locale );

		$po_url = sprintf(
			'https://translate.wordpress.org/projects/wp/dev/%s/default/export-translations/?format=po',
			$wporg_locale
		);

		$response = wp_remote_get( $po_url, array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$po_content = wp_remote_retrieve_body( $response );
		$strings    = $this->parse_po_file( $po_content );

		shuffle( $strings );

		return array_slice( $strings, 0, $count );
	}

	/**
	 * Parse PO file content.
	 *
	 * @param string $content PO file content.
	 *
	 * @return array
	 */
	protected function parse_po_file( string $content ): array {
		$strings = array();
		$blocks  = preg_split( '/\n\s*\n/', $content );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( empty( $block ) || strpos( $block, 'Project-Id-Version' ) !== false ) {
				continue;
			}

			$msgid  = $this->extract_po_string( $block, 'msgid' );
			$msgstr = $this->extract_po_string( $block, 'msgstr' );

			if ( empty( $msgid ) || empty( $msgstr ) || strlen( $msgid ) < 3 ) {
				continue;
			}

			if ( strpos( $block, 'msgid_plural' ) !== false ) {
				continue;
			}

			$strings[] = array(
				'source'      => $msgid,
				'translation' => $msgstr,
			);
		}

		return $strings;
	}

	/**
	 * Extract a string from PO block.
	 *
	 * @param string $block The PO block.
	 * @param string $key   The key (msgid or msgstr).
	 *
	 * @return string
	 */
	protected function extract_po_string( string $block, string $key ): string {
		$lines      = explode( "\n", $block );
		$in_key     = false;
		$full_value = '';

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^' . preg_quote( $key, '/' ) . '\s+"(.*)"$/', $line, $m ) ) {
				$in_key      = true;
				$full_value .= $m[1];
			} elseif ( $in_key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$full_value .= $m[1];
			} elseif ( $in_key && preg_match( '/^(msgid|msgstr|msgctxt)/', $line ) ) {
				break;
			}
		}

		return str_replace( array( '\\n', '\\t', '\\"', '\\\\' ), array( "\n", "\t", '"', '\\' ), $full_value );
	}

	/**
	 * Calculate similarity between two strings.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 *
	 * @return int Similarity percentage.
	 */
	protected function calculate_similarity( string $str1, string $str2 ): int {
		$str1 = trim( $str1 );
		$str2 = trim( $str2 );

		if ( $str1 === $str2 ) {
			return 100;
		}

		if ( empty( $str1 ) || empty( $str2 ) ) {
			return 0;
		}

		similar_text( $str1, $str2, $percent );

		return (int) round( $percent );
	}

	/**
	 * Convert locale to WordPress.org format.
	 *
	 * @param string $locale The locale.
	 *
	 * @return string
	 */
	protected function convert_locale_to_wporg( string $locale ): string {
		$mappings = array(
			'de' => 'de',
			'fr' => 'fr',
			'es' => 'es',
			'pt' => 'pt-br',
			'zh' => 'zh-cn',
			'no' => 'nb',
		);

		return $mappings[ $locale ] ?? $locale;
	}

	/**
	 * Truncate a string.
	 *
	 * @param string $str    The string.
	 * @param int    $length Max length.
	 *
	 * @return string
	 */
	protected function truncate( string $str, int $length ): string {
		$str = str_replace( array( "\n", "\r", "\t" ), ' ', $str );
		if ( mb_strlen( $str ) <= $length ) {
			return $str;
		}
		return mb_substr( $str, 0, $length - 3 ) . '...';
	}

	/**
	 * Import glossary entries from WordPress.org for a locale.
	 *
	 * ## OPTIONS
	 *
	 * <locale>
	 * : The locale to import glossary for (e.g., de, fr, es).
	 *
	 * [--all]
	 * : Import glossaries for all supported locales.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import German glossary
	 *     $ wp gpoai glossary_import de
	 *
	 *     # Import glossaries for all supported locales
	 *     $ wp gpoai glossary_import --all
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function glossary_import( $args, $assoc_args ) {
		$import_all = Utils\get_flag_value( $assoc_args, 'all', false );

		if ( $import_all ) {
			$locales = array_keys( Locales::get_supported_locales() );
		} else {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( 'Please provide a locale or use --all.' );
			}
			$locale = $args[0];
			if ( ! Locales::is_supported( $locale ) ) {
				WP_CLI::error( sprintf( 'Locale "%s" is not supported.', $locale ) );
			}
			$locales = array( $locale );
		}

		foreach ( $locales as $locale ) {
			$locale_name = Locales::get_supported_locales()[ $locale ] ?? $locale;
			WP_CLI::log( sprintf( 'Importing glossary for %s (%s)...', $locale_name, $locale ) );

			$result = Glossary::import_from_wporg( $locale );

			if ( -1 === $result ) {
				WP_CLI::warning( sprintf( 'Failed to import glossary for %s. GlotPress glossary classes not available.', $locale ) );
			} elseif ( 0 === $result ) {
				WP_CLI::log( sprintf( '  No entries found for %s.', $locale ) );
			} else {
				WP_CLI::success( sprintf( 'Imported %d entries for %s.', $result, $locale ) );
			}
		}
	}

	/**
	 * Translate all untranslated strings across all projects.
	 *
	 * By default, schedules batch translation jobs via Action Scheduler.
	 * Use --sync to translate synchronously in the current process.
	 *
	 * ## OPTIONS
	 *
	 * [--locale=<locale>]
	 * : Translate only this locale. Can be specified multiple times.
	 *
	 * [--project=<project_id>]
	 * : Translate only this project ID. Can be specified multiple times.
	 *
	 * [--all-locales]
	 * : Translate every translation set found, not just configured automation locales.
	 *
	 * [--sync]
	 * : Translate synchronously instead of scheduling via Action Scheduler.
	 *
	 * [--dry-run]
	 * : Show what would be translated without actually translating.
	 *
	 * [--debug-requests]
	 * : Output the full API request and response for each translation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Schedule translation for all projects and configured locales
	 *     $ wp gpoai translate_all
	 *
	 *     # Translate only German, synchronously
	 *     $ wp gpoai translate_all --locale=de --sync
	 *
	 *     # Dry run to see what would be translated
	 *     $ wp gpoai translate_all --dry-run
	 *
	 *     # Translate all locales for a specific project
	 *     $ wp gpoai translate_all --project=1 --all-locales
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function translate_all( $args, $assoc_args ) {
		$locale_filter   = Utils\get_flag_value( $assoc_args, 'locale', '' );
		$project_filter  = Utils\get_flag_value( $assoc_args, 'project', '' );
		$all_locales     = Utils\get_flag_value( $assoc_args, 'all-locales', false );
		$sync            = Utils\get_flag_value( $assoc_args, 'sync', false );
		$dry_run         = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$debug_requests  = Utils\get_flag_value( $assoc_args, 'debug-requests', false );

		if ( $debug_requests ) {
			$this->enable_debug();
		}

		// Get all projects.
		$projects = Automation::get_projects();
		if ( empty( $projects ) ) {
			WP_CLI::error( 'No GlotPress projects found.' );
		}

		// Filter projects if requested.
		if ( ! empty( $project_filter ) ) {
			$project_ids = array_map( 'intval', (array) $project_filter );
			$projects    = array_filter( $projects, function( $p ) use ( $project_ids ) {
				return in_array( (int) $p->id, $project_ids, true );
			} );
			if ( empty( $projects ) ) {
				WP_CLI::error( 'No matching projects found.' );
			}
		}

		// Determine target locales.
		$target_locales = array();
		if ( ! empty( $locale_filter ) ) {
			$target_locales = (array) $locale_filter;
		} elseif ( ! $all_locales ) {
			$target_locales = Config::get_automation_locales();
			if ( empty( $target_locales ) ) {
				WP_CLI::error( 'No automation locales configured. Use --locale=<locale> or --all-locales, or configure automation locales in settings.' );
			}
		}

		$automation    = new Automation();
		$total_strings = 0;
		$total_pairs   = 0;

		foreach ( $projects as $project ) {
			// Get translation sets for this project.
			$translation_sets = GP::$translation_set->by_project_id( $project->id );
			if ( ! $translation_sets ) {
				continue;
			}

			// If using specific locales, filter or create translation sets.
			if ( ! empty( $target_locales ) && ! $all_locales ) {
				$existing_locale_slugs = wp_list_pluck( $translation_sets, 'locale' );
				// Only process sets matching target locales.
				$translation_sets = array_filter( $translation_sets, function( $ts ) use ( $target_locales ) {
					return in_array( $ts->locale, $target_locales, true );
				} );
			}

			foreach ( $translation_sets as $ts ) {
				if ( ! empty( $target_locales ) && ! in_array( $ts->locale, $target_locales, true ) ) {
					continue;
				}

				// Get untranslated count.
				$untranslated = $this->count_untranslated( $project->id, $ts->id );
				if ( $untranslated <= 0 ) {
					continue;
				}

				$total_strings += $untranslated;
				++$total_pairs;

				WP_CLI::log( sprintf(
					'  %s / %s: %d untranslated strings',
					$project->name,
					$ts->locale,
					$untranslated
				) );

				if ( $dry_run ) {
					continue;
				}

				if ( $sync ) {
					$this->translate_project_sync( $project->id, $ts, $debug_requests );
				} else {
					$automation->schedule_project_translation( $project->id, $ts->locale );
				}
			}
		}

		WP_CLI::log( '' );

		if ( $dry_run ) {
			WP_CLI::success( sprintf(
				'Dry run complete. Found %d untranslated strings across %d project/locale pairs.',
				$total_strings,
				$total_pairs
			) );
			return;
		}

		if ( 0 === $total_pairs ) {
			WP_CLI::success( 'Nothing to translate. All strings are already translated.' );
			return;
		}

		if ( $sync ) {
			WP_CLI::success( sprintf(
				'Translated %d strings across %d project/locale pairs.',
				$total_strings,
				$total_pairs
			) );
		} else {
			WP_CLI::success( sprintf(
				'Scheduled translation for %d strings across %d project/locale pairs. Run Action Scheduler to process.',
				$total_strings,
				$total_pairs
			) );
		}
	}

	/**
	 * Count untranslated originals for a project and translation set.
	 *
	 * @param int $project_id         The project ID.
	 * @param int $translation_set_id The translation set ID.
	 *
	 * @return int
	 */
	protected function count_untranslated( int $project_id, int $translation_set_id ): int {
		global $wpdb;

		$originals_table    = GP::$original->table;
		$translations_table = GP::$translation->table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$originals_table} o
				LEFT JOIN {$translations_table} t ON o.id = t.original_id
					AND t.translation_set_id = %d
					AND t.status IN ('current', 'waiting', 'fuzzy')
				WHERE o.project_id = %d
					AND o.status = '+active'
					AND t.id IS NULL",
				$translation_set_id,
				$project_id
			)
		);
	}

	/**
	 * Translate a project/translation set synchronously with progress output.
	 *
	 * @param int    $project_id The project ID.
	 * @param object $translation_set The translation set object.
	 * @param bool   $debug Whether debug is enabled.
	 *
	 * @return void
	 */
	protected function translate_project_sync( int $project_id, $translation_set, bool $debug = false ): void {
		$automation = new Automation();
		$reflection = new \ReflectionMethod( $automation, 'get_untranslated_original_ids' );
		$reflection->setAccessible( true );
		$original_ids = $reflection->invoke( $automation, $project_id, $translation_set->id );

		if ( empty( $original_ids ) ) {
			return;
		}

		$batches  = array_chunk( $original_ids, Automation::BATCH_SIZE );
		$progress = Utils\make_progress_bar(
			sprintf( 'Translating %s/%s', $project_id, $translation_set->locale ),
			count( $original_ids )
		);

		foreach ( $batches as $batch ) {
			$automation->process_translation_batch( $project_id, $translation_set->id, $batch );
			for ( $i = 0; $i < count( $batch ); $i++ ) {
				$progress->tick();
			}
		}

		$progress->finish();
	}

	/**
	 * Restore original config.
	 *
	 * @param array $config Original config values.
	 *
	 * @return void
	 */
	protected function restore_config( array $config ): void {
		foreach ( $config as $key => $value ) {
			update_option( 'gpoai_' . $key, $value );
		}
	}
}
