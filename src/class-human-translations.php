<?php
/**
 * Complete human imports and per-form provenance. Keys match the translation server.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/** Locale-complete human imports and mixed translation provenance. */
final class Human_Translations {
	public const HUMAN_FORMS = 'gratis_ai_ts_human_forms';
	public const AI_FORMS    = 'gratis_ai_ts_ai_forms';

	/**
	 * Count the forms required for this original and locale.
	 *
	 * @param object $original GlotPress original.
	 * @param object $set Translation set.
	 * @return int Required form count, or zero for an unknown locale.
	 */
	public static function count( object $original, object $set ): int {
		$locale = \GP_Locales::by_slug( $set->locale );
		return empty( $original->plural ) ? 1 : ( $locale ? (int) $locale->nplurals : 0 );
	}

	/**
	 * Read all nonempty slots, preserving a literal zero.
	 *
	 * @param object $row Translation row.
	 * @return array<string,string> Nonempty form fields.
	 */
	public static function fields( object $row ): array {
		$fields = array();
		for ( $i = 0; $i < \GP_Translation::$number_of_plural_translations; $i++ ) {
			$field = 'translation_' . $i;
			$value = $row->$field ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$fields[ $field ] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Check that every required slot is nonempty.
	 *
	 * @param array $fields Translation fields.
	 * @param int   $count Required form count.
	 * @return bool Whether all forms exist.
	 */
	public static function complete( array $fields, int $count ): bool {
		if ( $count < 1 || $count > \GP_Translation::$number_of_plural_translations ) {
			return false;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $fields[ 'translation_' . $i ] ) || '' === trim( $fields[ 'translation_' . $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Import all locale forms; never downgrade a complete current row.
	 *
	 * @param object    $original GlotPress original.
	 * @param object    $set Translation set.
	 * @param object    $entry Parsed human PO entry.
	 * @param bool      $replace Allow complete human imports to replace automated rows.
	 * @param bool|null $changed Whether a row changed; unchanged repeated imports do not count.
	 * @return bool Whether the import was safely handled.
	 */
	public static function import_entry( object $original, object $set, object $entry, bool $replace = false, ?bool &$changed = null ): bool {
		$changed = false;
		$fields  = array();
		$count   = self::count( $original, $set );
		if ( $count < 1 || $count > \GP_Translation::$number_of_plural_translations ) {
			return false;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$value = $entry->translations[ $i ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$fields[ 'translation_' . $i ] = $value;
			}
		}
		if ( ! $fields ) {
			return false;
		}
		$identity = array(
			'original_id'        => $original->id,
			'translation_set_id' => $set->id,
		);
		$current  = \GP::$translation->find_one( $identity + array( 'status' => 'current' ) );
		$complete = self::complete( $fields, $count );
		if ( $current && $complete ) {
			if ( self::complete( self::fields( $current ), $count ) && ( ! $replace || ( null !== $current->user_id && (int) $current->user_id > 0 ) ) ) {
				return true;
			}
			if ( ! $replace ) {
				$fields = self::fields( $current ) + $fields;
			}
			$changed = self::fields( $current ) !== $fields;
			if ( $changed && ! $current->save( $fields ) ) {
				return false;
			}
			self::record( $current, $fields, array() );
			gp_clean_translation_set_cache( (int) $set->id );
			return true;
		}
		if ( $current && self::fields( $current ) === $fields ) {
			if ( ! self::complete( $fields, $count ) ) {
				if ( ! $current->save( array( 'status' => 'waiting' ) ) ) {
					return false;
				}
				$changed = true;
				self::record( $current, $fields, array() );
				gp_clean_translation_set_cache( (int) $set->id );
			}
			return true;
		}
		$status = $complete ? 'current' : 'waiting';
		foreach ( \GP::$translation->find_many( $identity + array( 'status' => $status ) ) as $row ) {
			if ( self::fields( $row ) === $fields ) {
				return true;
			}
		}
		$row = \GP::$translation->create(
			$identity + $fields + array(
				'status'  => $status,
				'user_id' => 0,
			)
		);
		if ( ! is_object( $row ) ) {
			return false;
		}
		if ( ! self::record( $row, $fields, array() ) ) {
			$row->delete();
			return false;
		}
		gp_clean_translation_set_cache( (int) $set->id );
		$changed = true;
		return true;
	}

	/**
	 * Preserve human values, preferring current, then waiting, then fuzzy rows.
	 *
	 * @param object $original GlotPress original.
	 * @param object $set Translation set.
	 * @return array<string,string> Human values keyed by translation field.
	 */
	public static function human_forms( object $original, object $set ): array {
		$forms = array();
		foreach ( array( 'current', 'waiting', 'fuzzy' ) as $status ) {
			$rows = \GP::$translation->find_many(
				array(
					'original_id'        => $original->id,
					'translation_set_id' => $set->id,
					'status'             => $status,
				)
			);
			usort( $rows, static fn( $a, $b ) => (int) $b->id <=> (int) $a->id );
			foreach ( $rows as $row ) {
				$human = gp_get_meta( 'translation', (int) $row->id, self::HUMAN_FORMS );
				if ( ! is_array( $human ) ) {
					$human = null !== $row->user_id && 'ai' !== gp_get_meta( 'translation', (int) $row->id, 'gratis_ai_ts_source' )
						? self::fields( $row ) : array();
				}
				$forms += $human;
			}
		}
		return $forms;
	}

	/**
	 * Record the human values and the AI-filled slot indexes.
	 *
	 * @param object $row Translation row.
	 * @param array  $human Human field values.
	 * @param array  $ai AI-filled slot indexes.
	 * @return bool Whether all provenance writes succeeded.
	 */
	public static function record( object $row, array $human, array $ai ): bool {
		return false !== gp_update_meta( $row->id, self::HUMAN_FORMS, $human, 'translation' )
			&& false !== gp_update_meta( $row->id, self::AI_FORMS, $ai, 'translation' )
			&& false !== gp_update_meta( $row->id, 'gratis_ai_ts_source', $ai ? ( $human ? 'mixed' : 'ai' ) : 'wporg', 'translation' );
	}
}
