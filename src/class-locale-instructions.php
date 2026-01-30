<?php
/**
 * Locale Instructions class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Locale Instructions class.
 *
 * Provides locale-specific translation guidelines distilled from
 * translate.wordpress.org glossary pages.
 */
class Locale_Instructions {

	/**
	 * Get default locale-specific instructions.
	 *
	 * Actionable LLM directives distilled from translate.wordpress.org glossary pages.
	 *
	 * @return array<string, string> Locale slug => instruction string.
	 */
	public static function get_default_instructions(): array {
		return array(
			'es' => 'Use informal "tú" (not "usted"). Use «» quotation marks. No mid-sentence capitalization except proper nouns.',
			'de' => 'Use informal "du" address.',
			'fr' => 'Use non-breaking spaces before `:;!?`. Use inclusive forms with `/` (e.g., administrateur/administratrice).',
			'it' => 'Conjugate verbs in second person singular present tense.',
			'hu' => 'Avoid direct second-person address (neither informal nor formal). Reformulate into neutral plural construction.',
			'zh' => 'Use formal written language. Follow "faithfulness, expressiveness, elegance" (信·达·雅). Pay attention to context and references.',
			'ko' => 'Follow Korean foreign word notation standards (외래어 표기법).',
			'ar' => 'Use the glossary equivalents to maintain consistency.',
			'pt' => 'Maintain consistency with the collaborative glossary across all projects.',
			'tr' => 'Use glossary equivalents when translating.',
			'sv' => 'Use standard Swedish translations for common terms listed in the glossary.',
			'ru' => 'Follow established glossary terminology for consistency.',
		);
	}

	/**
	 * Get locale-specific instructions for a given locale.
	 *
	 * Checks user overrides first, then falls back to defaults.
	 *
	 * @param string $locale The locale slug.
	 *
	 * @return string The instructions string, or empty string if none.
	 */
	public static function get_instructions( string $locale ): string {
		// Check user overrides.
		$overrides = get_option( 'gpoai_locale_instructions', array() );
		if ( is_array( $overrides ) && ! empty( $overrides[ $locale ] ) ) {
			return (string) $overrides[ $locale ];
		}

		// Fall back to defaults.
		$defaults = self::get_default_instructions();

		return $defaults[ $locale ] ?? '';
	}
}
