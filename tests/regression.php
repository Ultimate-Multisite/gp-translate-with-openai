<?php
/**
 * Focused regression checks for production bugs.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/class-automation.php';

use Meloniq\GpOpenaiTranslate\Automation;

/** Minimal GlotPress translation object used by the save regression. */
final class Regression_Translation {
	/** @var array<string, mixed> Values received by save(). */
	public array $saved = array();

	/**
	 * Simulate a successful GlotPress object save.
	 *
	 * @param array<string, mixed> $values Translation values.
	 * @return bool
	 */
	public function save( array $values ): bool {
		$this->saved = $values;
		return true;
	}
}

/** Exposes the focused save helper without widening the production API. */
final class Regression_Automation extends Automation {
	/**
	 * Invoke the protected production helper.
	 *
	 * @param object      $existing Existing translation.
	 * @param string      $singular Singular translation.
	 * @param string|null $plural   Plural translation.
	 * @return bool
	 */
	public static function save_human( object $existing, string $singular, ?string $plural ): bool {
		return parent::save_human_translation( $existing, $singular, $plural );
	}
}

$translation = new Regression_Translation();
$saved       = Regression_Automation::save_human( $translation, 'Human singular', null );

if ( ! $saved ) {
	throw new RuntimeException( 'Expected the existing translation object to be saved.' );
}

$expected = array(
	'translation_0' => 'Human singular',
	'translation_1' => null,
	'user_id'       => 0,
);

if ( $expected !== $translation->saved ) {
	throw new RuntimeException( 'Unexpected values passed to the translation object save method.' );
}

fwrite( STDOUT, "Regression checks passed.\n" );
