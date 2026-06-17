<?php
/**
 * Automation class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

use GP;

/**
 * Automation class for automated translation with Action Scheduler.
 */
class Automation {

	/**
	 * Hook name for batch translation.
	 *
	 * @var string
	 */
	const HOOK_TRANSLATE_BATCH = 'gpoai_translate_batch';

	/**
	 * Action Scheduler group name.
	 *
	 * @var string
	 */
	const GROUP_NAME = 'gpoai_automation';

	/**
	 * Batch size for translation.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 10;

	/**
	 * Number of translation sets to process per scheduled human sync action.
	 *
	 * The translate site can contain thousands of sets. Processing all of them in
	 * one Action Scheduler job exceeds the per-job timeout and leaves recurring
	 * failures behind, so scheduled syncs continue in small batches.
	 *
	 * @var int
	 */
	const HUMAN_SYNC_BATCH_SIZE = 5;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Hook name for human translation sync.
	 *
	 * @var string
	 */
	const HOOK_SYNC_HUMAN = 'gpoai_sync_human_translations';

	/**
	 * Hook name for daily glossary re-import.
	 *
	 * @var string
	 */
	const HOOK_SYNC_GLOSSARIES = 'gpoai_sync_glossaries';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Hook into GlotPress originals import.
		add_action( 'gp_originals_imported', array( $this, 'on_originals_imported' ), 10, 5 );

		// Register Action Scheduler callback.
		add_action( self::HOOK_TRANSLATE_BATCH, array( $this, 'process_translation_batch' ), 10, 3 );

		// Admin hooks for manual trigger.
		add_action( 'admin_post_gpoai_manual_translate', array( $this, 'handle_manual_translate' ) );

		// Periodic human translation sync: re-imports human translations from
		// wordpress.org, replacing AI translations where humans have caught up.
		add_action( self::HOOK_SYNC_HUMAN, array( __CLASS__, 'run_scheduled_human_sync' ), 10, 1 );

		// Daily glossary re-import from wordpress.org.
		add_action( self::HOOK_SYNC_GLOSSARIES, array( __CLASS__, 'run_scheduled_glossary_sync' ) );

		// Ensure the daily syncs are scheduled.
		add_action( 'init', array( $this, 'ensure_human_sync_scheduled' ), 10 );
		add_action( 'init', array( $this, 'ensure_glossary_sync_scheduled' ), 10 );
	}

	/**
	 * Ensure the daily human translation sync is scheduled.
	 *
	 * @return void
	 */
	public function ensure_human_sync_scheduled(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK_SYNC_HUMAN, array(), self::GROUP_NAME ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_SYNC_HUMAN, array(), self::GROUP_NAME );
		}
	}

	/**
	 * Ensure the daily glossary sync is scheduled.
	 *
	 * @return void
	 */
	public function ensure_glossary_sync_scheduled(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK_SYNC_GLOSSARIES, array(), self::GROUP_NAME ) ) {
			// Offset by 2 hours from human sync to spread load.
			as_schedule_recurring_action( time() + ( 2 * HOUR_IN_SECONDS ), DAY_IN_SECONDS, self::HOOK_SYNC_GLOSSARIES, array(), self::GROUP_NAME );
		}
	}

	/**
	 * Run the scheduled glossary sync.
	 *
	 * Called by Action Scheduler daily. Re-imports glossary entries from
	 * wordpress.org for all locales that have a glossary in GlotPress.
	 *
	 * @return void
	 */
	public static function run_scheduled_glossary_sync(): void {
		if ( ! class_exists( 'GP' ) ) {
			return;
		}

		$locales  = array_keys( Locales::get_supported_locales() );
		$imported = 0;
		$updated  = 0;

		foreach ( $locales as $locale ) {
			$result = Glossary::import_from_wporg( $locale );
			if ( $result > 0 ) {
				$imported += $result;
				++$updated;
			}
		}

		if ( $imported > 0 ) {
			error_log( sprintf( '[gpoai] Glossary sync: imported %d entries across %d locales.', $imported, $updated ) );
		}
	}

	/**
	 * Run the scheduled human translation sync.
	 *
	 * Called by Action Scheduler daily. Syncs human translations from
	 * wordpress.org for all existing GlotPress projects.
	 *
	 * @return void
	 */
	public static function run_scheduled_human_sync( int $offset = 0 ): void {
		$stats = self::sync_human_translations( null, $offset, self::HUMAN_SYNC_BATCH_SIZE );

		if ( $stats['has_more'] && function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			$next_args = array( $stats['next_offset'] );

			if ( false === as_next_scheduled_action( self::HOOK_SYNC_HUMAN, $next_args, self::GROUP_NAME ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_SYNC_HUMAN, $next_args, self::GROUP_NAME );
			}
		}

		if ( $stats['replaced'] > 0 || $stats['imported'] > 0 || $stats['has_more'] ) {
			error_log( sprintf(
				'[gp-openai-translate] Human sync: offset %d, %d projects, %d sets, %d replaced, %d imported, has_more=%s',
				$stats['offset'],
				$stats['projects'],
				$stats['sets'],
				$stats['replaced'],
				$stats['imported'],
				$stats['has_more'] ? 'yes' : 'no'
			) );
		}
	}

	/**
	 * Handle when new originals are imported into GlotPress.
	 *
	 * @param int   $project_id      The project ID.
	 * @param int   $originals_added Number of originals added.
	 * @param int   $originals_existing Number of existing originals.
	 * @param int   $originals_fuzzied Number of fuzzied originals.
	 * @param int   $originals_obsoleted Number of obsoleted originals.
	 *
	 * @return void
	 */
	public function on_originals_imported( $project_id, $originals_added, $originals_existing, $originals_fuzzied, $originals_obsoleted ): void {
		// Check if automation is enabled.
		if ( ! Config::get_automation_enabled() ) {
			return;
		}

		// Check if we have new originals.
		if ( $originals_added <= 0 ) {
			return;
		}

		// Get target locales.
		$target_locales = Config::get_automation_locales();
		if ( empty( $target_locales ) ) {
			return;
		}

		// Schedule translation for each locale.
		foreach ( $target_locales as $locale ) {
			$this->schedule_project_translation( $project_id, $locale );
		}
	}

	/**
	 * Schedule translation jobs for a project and locale.
	 *
	 * @param int    $project_id The project ID.
	 * @param string $locale     The target locale.
	 *
	 * @return void
	 */
	public function schedule_project_translation( int $project_id, string $locale ): void {
		// Check if Action Scheduler is available.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Find the translation set for this project and locale.
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project_id, 'default', $locale );

		if ( ! $translation_set ) {
			// Try to create a translation set if it doesn't exist.
			$translation_set = $this->create_translation_set( $project_id, $locale );
			if ( ! $translation_set ) {
				return;
			}
		}

		// Get untranslated originals.
		$untranslated_original_ids = $this->get_untranslated_original_ids( $project_id, $translation_set->id );

		if ( empty( $untranslated_original_ids ) ) {
			return;
		}

		// Split into batches.
		$batches    = array_chunk( $untranslated_original_ids, self::BATCH_SIZE );
		$delay      = 0;
		$delay_step = 60; // 1 minute between batches.

		foreach ( $batches as $batch ) {
			$args = array(
				'project_id'         => $project_id,
				'translation_set_id' => $translation_set->id,
				'original_ids'       => $batch,
			);

			if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::HOOK_TRANSLATE_BATCH, $args, self::GROUP_NAME ) ) {
				continue;
			}

			as_schedule_single_action(
				time() + $delay,
				self::HOOK_TRANSLATE_BATCH,
				$args,
				self::GROUP_NAME
			);

			$delay += $delay_step;
		}
	}

	/**
	 * Process a batch of translations.
	 *
	 * @param int   $project_id         The project ID.
	 * @param int   $translation_set_id The translation set ID.
	 * @param array $original_ids       Array of original IDs to translate.
	 *
	 * @return void
	 */
	public function process_translation_batch( int $project_id, int $translation_set_id, array $original_ids ): void {
		// Get the translation set.
		$translation_set = GP::$translation_set->get( $translation_set_id );
		if ( ! $translation_set ) {
			return;
		}

		$locale    = (string) $translation_set->locale;
		$translate = Translate::instance();

		// Import existing human translations from wordpress.org before AI translating.
		// Human translations are always preferred — AI only fills genuine gaps.
		$project = GP::$project->get( $project_id );
		if ( $project ) {
			$textdomain = $project->slug;
			$wp_locale = self::get_wp_locale_for_glotpress_locale( $locale );
			self::import_wporg_translations( $project, $translation_set, $textdomain, $wp_locale );
		}

		// Get locale plural info.
		$locale_obj = \GP_Locales::by_slug( $locale );
		$nplurals   = $locale_obj ? $locale_obj->nplurals : 2;

		// Separate singular and plural originals, filtering out already-translated ones.
		$singular_originals = array();
		$plural_originals   = array();

		foreach ( $original_ids as $original_id ) {
			$original = GP::$original->get( $original_id );
			if ( ! $original ) {
				continue;
			}

			// Check if already translated.
			$existing = GP::$translation->find_one(
				array(
					'original_id'        => $original_id,
					'translation_set_id' => $translation_set_id,
					'status'             => array( 'current', 'waiting', 'fuzzy' ),
				)
			);

			if ( $existing ) {
				continue;
			}

			if ( $original->plural ) {
				$plural_originals[] = $original;
			} else {
				$singular_originals[] = $original;
			}
		}

		// Batch translate singular strings using translate_batch().
		if ( ! empty( $singular_originals ) ) {
			$strings      = array();
			$contexts     = array();
			$sing_orig_ids = array();

			foreach ( $singular_originals as $original ) {
				$strings[]      = $original->singular;
				$contexts[]     = $original->comment ?? '';
				$sing_orig_ids[] = (int) $original->id;
			}

			$results = $translate->translate_batch( $locale, $strings, $contexts, $sing_orig_ids, $project_id );

			if ( ! is_wp_error( $results ) ) {
				foreach ( $singular_originals as $index => $original ) {
					$translation = $results[ $index ] ?? '';

					if ( empty( $translation ) ) {
						continue;
					}

					// Check for warnings.
					$warnings = GP::$translation_warnings->check( $original->singular, null, array( $translation ), $locale_obj );

					// Run safety checks to determine status.
					$status = self::validate_translation( $original->singular, $translation );

					// If GlotPress detected warnings, downgrade to waiting.
					if ( 'current' === $status && ! empty( $warnings ) ) {
						$status = 'waiting';
					}

					GP::$translation->create(
						array(
							'original_id'        => $original->id,
							'translation_set_id' => $translation_set_id,
							'translation_0'      => $translation,
							'status'             => $status,
							'user_id'            => 0,
							'warnings'           => $warnings,
						)
					);
				}
			}
		}

		// Translate plural strings one by one (these require special JSON response format).
		foreach ( $plural_originals as $original ) {
			$result = $translate->translate_plural(
				$original->singular,
				$original->plural,
				$locale,
				$nplurals,
				$original->comment ?? '',
				(int) $original->id,
				$project_id
			);

			if ( ! is_array( $result ) ) {
				continue;
			}

			$data = array(
				'original_id'        => $original->id,
				'translation_set_id' => $translation_set_id,
				'status'             => 'waiting',
				'user_id'            => 0,
			);

			for ( $i = 0; $i < $nplurals; $i++ ) {
				$data[ 'translation_' . $i ] = $result[ $i ] ?? '';
			}

			$translation_array = array();
			for ( $i = 0; $i < $nplurals; $i++ ) {
				$translation_array[] = $result[ $i ] ?? '';
			}
			$warnings         = GP::$translation_warnings->check( $original->singular, $original->plural, $translation_array, $locale_obj );
			$data['warnings'] = $warnings;

			GP::$translation->create( $data );
		}
	}

	/**
	 * Validate a translation against the original for safety.
	 *
	 * Checks for missing placeholders, HTML tags, and other structural issues.
	 * Returns 'current' if the translation passes all checks, 'waiting' otherwise.
	 *
	 * @param string $original    The original string.
	 * @param string $translation The translated string.
	 *
	 * @return string 'current' or 'waiting'.
	 */
	public static function validate_translation( string $original, string $translation ): string {
		// Check placeholders like %s, %d, %1$s, %2$d, etc.
		preg_match_all( '/%(?:\d+\$)?[sdfFe]/', $original, $orig_placeholders );
		preg_match_all( '/%(?:\d+\$)?[sdfFe]/', $translation, $trans_placeholders );

		$orig_sorted  = $orig_placeholders[0];
		$trans_sorted = $trans_placeholders[0];
		sort( $orig_sorted );
		sort( $trans_sorted );

		if ( $orig_sorted !== $trans_sorted ) {
			return 'waiting';
		}

		// Check HTML tags (opening and closing).
		preg_match_all( '/<\/?[a-zA-Z][^>]*>/', $original, $orig_tags );
		preg_match_all( '/<\/?[a-zA-Z][^>]*>/', $translation, $trans_tags );

		$orig_tag_sorted  = $orig_tags[0];
		$trans_tag_sorted = $trans_tags[0];
		sort( $orig_tag_sorted );
		sort( $trans_tag_sorted );

		if ( $orig_tag_sorted !== $trans_tag_sorted ) {
			return 'waiting';
		}

		// Check that URLs in the original are preserved.
		preg_match_all( '/https?:\/\/[^\s<>"\']+/', $original, $orig_urls );
		if ( ! empty( $orig_urls[0] ) ) {
			foreach ( $orig_urls[0] as $url ) {
				if ( strpos( $translation, $url ) === false ) {
					return 'waiting';
				}
			}
		}

		return 'current';
	}

	/**
	 * Get original IDs that don't have translations for a translation set.
	 *
	 * @param int $project_id         The project ID.
	 * @param int $translation_set_id The translation set ID.
	 *
	 * @return array Array of original IDs.
	 */
	protected function get_untranslated_original_ids( int $project_id, int $translation_set_id ): array {
		global $wpdb;

		$originals_table    = GP::$original->table;
		$translations_table = GP::$translation->table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT o.id FROM {$originals_table} o
				LEFT JOIN {$translations_table} t ON o.id = t.original_id
					AND t.translation_set_id = %d
					AND t.status IN ('current', 'waiting', 'fuzzy')
				WHERE o.project_id = %d
					AND o.status = '+active'
					AND t.id IS NULL
				ORDER BY o.id ASC",
				$translation_set_id,
				$project_id
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Create a translation set for a project and locale.
	 *
	 * @param int    $project_id The project ID.
	 * @param string $locale     The locale slug.
	 *
	 * @return object|null The translation set or null on failure.
	 */
	protected function create_translation_set( int $project_id, string $locale ) {
		// Get the locale object.
		$locale_obj = \GP_Locales::by_slug( $locale );
		if ( ! $locale_obj ) {
			return null;
		}

		// Create the translation set.
		$translation_set = GP::$translation_set->create(
			array(
				'project_id' => $project_id,
				'locale'     => $locale,
				'slug'       => 'default',
				'name'       => $locale_obj->english_name,
			)
		);

		return $translation_set;
	}

	/**
	 * Handle manual translation trigger from admin.
	 *
	 * @return void
	 */
	public function handle_manual_translate(): void {
		// Verify nonce.
		if ( ! isset( $_POST['gpoai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gpoai_nonce'] ) ), 'gpoai_manual_translate' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gp-translate-with-openai' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gp-translate-with-openai' ) );
		}

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$locale     = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';

		if ( ! $project_id || empty( $locale ) ) {
			wp_safe_redirect( add_query_arg( 'gpoai_error', 'missing_params', wp_get_referer() ) );
			exit;
		}

		// Schedule the translation.
		$this->schedule_project_translation( $project_id, $locale );

		wp_safe_redirect( add_query_arg( 'gpoai_success', 'scheduled', wp_get_referer() ) );
		exit;
	}

	/**
	 * Import existing human translations from wordpress.org into GlotPress.
	 *
	 * Downloads the official .po file for the given locale and imports any
	 * current translations into the GlotPress translation set. This ensures
	 * AI only fills genuine gaps, not strings already translated by humans.
	 *
	 * Human translations are always preferred over AI — if a human translation
	 * exists, it takes precedence. This method is idempotent: it skips strings
	 * that already have a 'current' translation in GlotPress.
	 *
	 * @param object $project         GlotPress project.
	 * @param object $translation_set GlotPress translation set.
	 * @param string $textdomain      Plugin textdomain (slug).
	 * @param string $wp_locale       WordPress locale (e.g. 'ro_RO', 'fr_FR').
	 *
	 * @return int Number of human translations imported.
	 */
	public static function import_wporg_translations( object $project, object $translation_set, string $textdomain, string $wp_locale ): int {
		$po = self::download_and_parse_wporg_po( $textdomain, $wp_locale );
		if ( ! $po ) {
			return 0; // No official translation available.
		}

		// Import into GlotPress.
		$imported = 0;
		foreach ( $po->entries as $entry ) {
			if ( empty( $entry->translations[0] ) ) {
				continue;
			}

			// Find the matching original in GlotPress.
			$original = GP::$original->find_one(
				array(
					'project_id' => $project->id,
					'singular'   => $entry->singular,
					'status'     => '+active',
				)
			);

			if ( ! $original ) {
				continue;
			}

			// Check if already has a current translation.
			$existing = GP::$translation->find_one(
				array(
					'original_id'        => $original->id,
					'translation_set_id' => $translation_set->id,
					'status'             => 'current',
				)
			);

			if ( $existing ) {
				continue;
			}

			$data = array(
				'original_id'        => $original->id,
				'translation_set_id' => $translation_set->id,
				'translation_0'      => $entry->translations[0],
				'status'             => 'current',
				'user_id'            => 0,
			);

			if ( ! empty( $entry->translations[1] ) ) {
				$data['translation_1'] = $entry->translations[1];
			}

			GP::$translation->create( $data );
			++$imported;
		}

		return $imported;
	}

	/**
	 * Sync human translations from wordpress.org for all existing projects.
	 *
	 * Iterates all GlotPress projects and their translation sets, re-importing
	 * human translations from wordpress.org. If a human translation now exists
	 * for a string that was previously AI-translated (user_id = 0), the AI
	 * translation is replaced with the human one.
	 *
	 * @param string|null $limit_locale Optional: only sync this WP locale (e.g. 'ro_RO').
	 * @param int         $offset       Number of eligible sets to skip before processing.
	 * @param int         $limit        Maximum eligible sets to process in this call.
	 *
	 * @return array{projects: int, sets: int, imported: int, replaced: int, offset: int, limit: int, next_offset: int, has_more: bool}
	 */
	public static function sync_human_translations( ?string $limit_locale = null, int $offset = 0, int $limit = self::HUMAN_SYNC_BATCH_SIZE ): array {
		$stats = array(
			'projects'    => 0,
			'sets'        => 0,
			'imported'    => 0,
			'replaced'    => 0,
			'offset'      => max( 0, $offset ),
			'limit'       => max( 1, $limit ),
			'next_offset' => 0,
			'has_more'    => false,
		);

		$projects = self::get_projects();
		if ( empty( $projects ) ) {
			return $stats;
		}

		$eligible_sets_seen = 0;
		$processed_projects = array();

		foreach ( $projects as $project ) {
			// Only process plugin projects (path starts with 'plugins/').
			if ( strpos( $project->path, 'plugins/' ) !== 0 ) {
				continue;
			}

			$textdomain = $project->slug;
			$translation_sets = GP::$translation_set->by_project_id( $project->id );

			if ( empty( $translation_sets ) ) {
				continue;
			}

			foreach ( $translation_sets as $translation_set ) {
				$locale_obj = \GP_Locales::by_slug( $translation_set->locale );
				if ( ! $locale_obj || empty( $locale_obj->wp_locale ) ) {
					continue;
				}

				$wp_locale = $locale_obj->wp_locale;

				// If limiting to a specific locale, skip others.
				if ( null !== $limit_locale && $wp_locale !== $limit_locale ) {
					continue;
				}

				if ( $eligible_sets_seen < $stats['offset'] ) {
					++$eligible_sets_seen;
					continue;
				}

				if ( $stats['sets'] >= $stats['limit'] ) {
					$stats['next_offset'] = $eligible_sets_seen;
					$stats['has_more']    = true;

					return $stats;
				}

				++$stats['sets'];
				++$eligible_sets_seen;

				if ( ! isset( $processed_projects[ $project->id ] ) ) {
					$processed_projects[ $project->id ] = true;
					++$stats['projects'];
				}

				// First, replace any AI-translated strings (user_id = 0) where
				// a human translation is now available from wp.org.
				$replaced = self::replace_ai_with_human( $project, $translation_set, $textdomain, $wp_locale );
				$stats['replaced'] += $replaced;

				// Then import any remaining human translations that are new.
				$imported = self::import_wporg_translations( $project, $translation_set, $textdomain, $wp_locale );
				$stats['imported'] += $imported;
			}
		}

		$stats['next_offset'] = 0;

		return $stats;
	}

	/**
	 * Resolve a GlotPress locale slug to a WordPress locale string.
	 *
	 * Some GlotPress locales do not define wp_locale. Falling back to the slug
	 * keeps wordpress.org import lookups best-effort without passing null to
	 * methods that require a string.
	 *
	 * @param string $locale GlotPress locale slug.
	 *
	 * @return string WordPress locale or fallback locale slug.
	 */
	protected static function get_wp_locale_for_glotpress_locale( string $locale ): string {
		$locale_obj = \GP_Locales::by_slug( $locale );
		if ( $locale_obj && ! empty( $locale_obj->wp_locale ) ) {
			return (string) $locale_obj->wp_locale;
		}

		return $locale;
	}

	/**
	 * Replace AI-translated strings with human translations from wordpress.org.
	 *
	 * Finds strings that were AI-translated (user_id = 0) and replaces them
	 * with human translations when available. This ensures human translations
	 * always take precedence as they are updated on wordpress.org.
	 *
	 * @param object $project         GlotPress project.
	 * @param object $translation_set GlotPress translation set.
	 * @param string $textdomain      Plugin textdomain (slug).
	 * @param string $wp_locale       WordPress locale (e.g. 'ro_RO').
	 *
	 * @return int Number of AI translations replaced with human ones.
	 */
	public static function replace_ai_with_human( object $project, object $translation_set, string $textdomain, string $wp_locale ): int {
		$po = self::download_and_parse_wporg_po( $textdomain, $wp_locale );
		if ( ! $po ) {
			return 0;
		}

		$replaced = 0;
		foreach ( $po->entries as $entry ) {
			if ( empty( $entry->translations[0] ) ) {
				continue;
			}

			$original = GP::$original->find_one(
				array(
					'project_id' => $project->id,
					'singular'   => $entry->singular,
					'status'     => '+active',
				)
			);

			if ( ! $original ) {
				continue;
			}

			// Find current AI translation (user_id = 0 means automated/AI).
			$existing = GP::$translation->find_one(
				array(
					'original_id'        => $original->id,
					'translation_set_id' => $translation_set->id,
					'status'             => 'current',
				)
			);

			if ( ! $existing ) {
				continue; // No current translation — import_wporg_translations handles this.
			}

			// Only replace if the current translation was AI-generated (user_id = 0)
			// and the human translation differs.
			if ( (int) $existing->user_id !== 0 ) {
				continue; // Human-set translation — don't overwrite.
			}

			if ( $existing->translation_0 === $entry->translations[0] ) {
				continue; // Same translation — no need to replace.
			}

			// Replace AI translation with human translation.
			GP::$translation->update(
				$existing,
				array(
					'translation_0' => $entry->translations[0],
					'translation_1' => ! empty( $entry->translations[1] ) ? $entry->translations[1] : null,
					'user_id'       => 0, // Still system-imported, but from human source.
				)
			);
			++$replaced;
		}

		return $replaced;
	}

	/**
	 * Download and parse a .po file from wordpress.org for a plugin and locale.
	 *
	 * Queries the wordpress.org translations API to find the correct download
	 * URL (which requires the exact plugin version, not "stable"), downloads
	 * the zip, extracts the .po, and returns a parsed PO object.
	 *
	 * @param string $textdomain Plugin textdomain (slug).
	 * @param string $wp_locale  WordPress locale (e.g. 'ro_RO').
	 *
	 * @return \PO|null Parsed PO object, or null on failure.
	 */
	protected static function download_and_parse_wporg_po( string $textdomain, string $wp_locale ): ?\PO {
		// Use WordPress core's translations_api() to get the correct package URL.
		// This is the same function WordPress uses internally for translation
		// updates — handles versioning, user agent, and URL construction.
		if ( ! function_exists( 'translations_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$api = translations_api( 'plugins', array( 'slug' => $textdomain ) );
		if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
			return null;
		}

		// Find the package URL for the requested locale.
		$package_url = null;
		foreach ( $api['translations'] as $entry ) {
			if ( ( $entry['language'] ?? '' ) === $wp_locale && ! empty( $entry['package'] ) ) {
				$package_url = $entry['package'];
				break;
			}
		}

		if ( ! $package_url ) {
			return null; // No translation available for this locale.
		}

		// Download the translation zip.
		$response = wp_remote_get( $package_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$zip_content = wp_remote_retrieve_body( $response );
		if ( empty( $zip_content ) ) {
			return null;
		}

		// Write zip to temp file and extract the .po file.
		$tmp_zip = get_temp_dir() . $textdomain . '-' . $wp_locale . '-wporg.zip';
		file_put_contents( $tmp_zip, $zip_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_zip ) ) {
			wp_delete_file( $tmp_zip );
			return null;
		}

		$po_content = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( substr( $name, -3 ) === '.po' ) {
				$po_content = $zip->getFromIndex( $i );
				break;
			}
		}
		$zip->close();
		wp_delete_file( $tmp_zip );

		if ( empty( $po_content ) ) {
			return null;
		}

		// Write .po to temp file for PO parser.
		$tmp_po = get_temp_dir() . $textdomain . '-' . $wp_locale . '-wporg.po';
		file_put_contents( $tmp_po, $po_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( ! class_exists( 'PO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/po.php';
		}

		$po = new \PO();
		if ( ! $po->import_from_file( $tmp_po ) ) {
			wp_delete_file( $tmp_po );
			return null;
		}
		wp_delete_file( $tmp_po );

		return $po;
	}

	/**
	 * Get all GlotPress projects.
	 *
	 * @return array Array of projects.
	 */
	public static function get_projects(): array {
		if ( ! class_exists( 'GP' ) || ! isset( GP::$project ) ) {
			return array();
		}

		$projects = GP::$project->all();

		return $projects ? $projects : array();
	}

	/**
	 * Get the count of pending automation jobs.
	 *
	 * @return int Number of pending jobs.
	 */
	public static function get_pending_jobs_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK_TRANSLATE_BATCH,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => self::GROUP_NAME,
			),
			'ids'
		);

		return count( $pending );
	}

	/**
	 * Cancel all pending automation jobs.
	 *
	 * @return int Number of cancelled jobs.
	 */
	public static function cancel_all_pending_jobs(): int {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}

		$cancelled = as_unschedule_all_actions( self::HOOK_TRANSLATE_BATCH, array(), self::GROUP_NAME );

		return $cancelled;
	}
}
