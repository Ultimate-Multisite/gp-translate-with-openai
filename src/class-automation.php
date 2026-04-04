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
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->register_hooks();
	}

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
			as_schedule_single_action(
				time() + $delay,
				self::HOOK_TRANSLATE_BATCH,
				array(
					'project_id'         => $project_id,
					'translation_set_id' => $translation_set->id,
					'original_ids'       => $batch,
				),
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

		$locale    = $translation_set->locale;
		$translate = Translate::instance();

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
