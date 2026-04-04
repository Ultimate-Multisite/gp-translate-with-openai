<?php
/**
 * Locales class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Locales class.
 */
class Locales {

	/**
	 * Is the locale supported?
	 *
	 * @param string $locale The locale to check.
	 *
	 * @return bool
	 */
	public static function is_supported( string $locale ): bool {
		$locales = self::get_data();

		return in_array( $locale, $locales, true );
	}

	/**
	 * Get the supported locales.
	 *
	 * @see https://platform.openai.com/docs/guides/speech-to-text/supported-languages
	 *
	 * @return array
	 */
	public static function get_data(): array {
		$locales = array(
			'af',    // Afrikaans.
			'am',    // Amharic.
			'ar',    // Arabic.
			'as',    // Assamese.
			'hy',    // Armenian.
			'az',    // Azerbaijani.
			'azb',   // South Azerbaijani.
			'be',    // Belarusian.
			'bel',   // Belarusian (variant).
			'bn',    // Bengali.
			'bn-in', // Bengali (India).
			'bo',    // Tibetan.
			'bs',    // Bosnian.
			'bg',    // Bulgarian.
			'ca',    // Catalan.
			'ceb',   // Cebuano.
			'ckb',   // Central Kurdish (Sorani).
			'co',    // Corsican.
			'zh',    // Chinese.
			'zh-cn', // Chinese (Simplified).
			'zh-hk', // Chinese (Hong Kong).
			'zh-tw', // Chinese (Traditional).
			'hr',    // Croatian.
			'cs',    // Czech.
			'da',    // Danish.
			'nl',    // Dutch.
			'nl-be', // Dutch (Belgium).
			'en',    // English.
			'en-au', // English (Australia).
			'en-ca', // English (Canada).
			'en-gb', // English (UK).
			'eo',    // Esperanto.
			'et',    // Estonian.
			'eu',    // Basque.
			'fi',    // Finnish.
			'fr',    // French.
			'fr-be', // French (Belgium).
			'fr-ca', // French (Canada).
			'fy',    // Frisian.
			'ga',    // Irish.
			'gd',    // Scottish Gaelic.
			'gl',    // Galician.
			'gu',    // Gujarati.
			'haw',   // Hawaiian.
			'de',    // German.
			'de-at', // German (Austria).
			'de-ch', // German (Switzerland).
			'el',    // Greek.
			'he',    // Hebrew.
			'hi',    // Hindi.
			'hu',    // Hungarian.
			'ibo',   // Igbo.
			'is',    // Icelandic.
			'id',    // Indonesian.
			'it',    // Italian.
			'ja',    // Japanese.
			'jv',    // Javanese.
			'ka',    // Georgian.
			'kir',   // Kyrgyz.
			'kk',    // Kazakh.
			'km',    // Khmer.
			'kn',    // Kannada.
			'ko',    // Korean.
			'la',    // Latin.
			'lo',    // Lao.
			'lv',    // Latvian.
			'lt',    // Lithuanian.
			'mg',    // Malagasy.
			'mk',    // Macedonian.
			'ml',    // Malayalam.
			'mlt',   // Maltese.
			'mn',    // Mongolian.
			'mr',    // Marathi.
			'mri',   // Maori.
			'ms',    // Malay.
			'mya',   // Burmese.
			'nb',    // Norwegian Bokmål.
			'ne',    // Nepali.
			'nn',    // Norwegian Nynorsk.
			'no',    // Norwegian.
			'ory',   // Odia.
			'pa',    // Punjabi.
			'ps',    // Pashto.
			'fa',    // Persian.
			'pl',    // Polish.
			'pt',    // Portuguese.
			'pt-br', // Portuguese (Brazil).
			'ro',    // Romanian.
			'ru',    // Russian.
			'sr',    // Serbian.
			'si',    // Sinhala.
			'sk',    // Slovak.
			'sl',    // Slovenian.
			'sna',   // Shona.
			'snd',   // Sindhi.
			'so',    // Somali.
			'sq',    // Albanian.
			'su',    // Sundanese.
			'es',    // Spanish.
			'es-ar', // Spanish (Argentina).
			'es-cl', // Spanish (Chile).
			'es-co', // Spanish (Colombia).
			'es-mx', // Spanish (Mexico).
			'es-pe', // Spanish (Peru).
			'es-ve', // Spanish (Venezuela).
			'sw',    // Swahili.
			'sv',    // Swedish.
			'ta',    // Tamil.
			'ta-lk', // Tamil (Sri Lanka).
			'te',    // Telugu.
			'tg',    // Tajik.
			'th',    // Thai.
			'tir',   // Tigrinya.
			'tl',    // Tagalog.
			'tr',    // Turkish.
			'tt',    // Tatar.
			'tuk',   // Turkmen.
			'ug',    // Uyghur.
			'uk',    // Ukrainian.
			'ur',    // Urdu.
			'uz',    // Uzbek.
			'vi',    // Vietnamese.
			'cy',    // Welsh.
			'xho',   // Xhosa.
			'yi',    // Yiddish.
			'yor',   // Yoruba.
			'zul',   // Zulu.
		);

		return $locales;
	}

	/**
	 * Get the supported locales with names.
	 *
	 * @return array Associative array of locale slug => locale name.
	 */
	public static function get_supported_locales(): array {
		$locale_names = array(
			'af'    => 'Afrikaans',
			'am'    => 'Amharic',
			'ar'    => 'Arabic',
			'as'    => 'Assamese',
			'hy'    => 'Armenian',
			'az'    => 'Azerbaijani',
			'azb'   => 'South Azerbaijani',
			'be'    => 'Belarusian',
			'bel'   => 'Belarusian',
			'bn'    => 'Bengali',
			'bn-in' => 'Bengali (India)',
			'bo'    => 'Tibetan',
			'bs'    => 'Bosnian',
			'bg'    => 'Bulgarian',
			'ca'    => 'Catalan',
			'ceb'   => 'Cebuano',
			'ckb'   => 'Central Kurdish',
			'co'    => 'Corsican',
			'zh'    => 'Chinese',
			'zh-cn' => 'Chinese (Simplified)',
			'zh-hk' => 'Chinese (Hong Kong)',
			'zh-tw' => 'Chinese (Traditional)',
			'hr'    => 'Croatian',
			'cs'    => 'Czech',
			'da'    => 'Danish',
			'nl'    => 'Dutch',
			'nl-be' => 'Dutch (Belgium)',
			'en'    => 'English',
			'en-au' => 'English (Australia)',
			'en-ca' => 'English (Canada)',
			'en-gb' => 'English (UK)',
			'eo'    => 'Esperanto',
			'et'    => 'Estonian',
			'eu'    => 'Basque',
			'fi'    => 'Finnish',
			'fr'    => 'French',
			'fr-be' => 'French (Belgium)',
			'fr-ca' => 'French (Canada)',
			'fy'    => 'Frisian',
			'ga'    => 'Irish',
			'gd'    => 'Scottish Gaelic',
			'gl'    => 'Galician',
			'gu'    => 'Gujarati',
			'haw'   => 'Hawaiian',
			'de'    => 'German',
			'de-at' => 'German (Austria)',
			'de-ch' => 'German (Switzerland)',
			'el'    => 'Greek',
			'he'    => 'Hebrew',
			'hi'    => 'Hindi',
			'hu'    => 'Hungarian',
			'ibo'   => 'Igbo',
			'is'    => 'Icelandic',
			'id'    => 'Indonesian',
			'it'    => 'Italian',
			'ja'    => 'Japanese',
			'jv'    => 'Javanese',
			'ka'    => 'Georgian',
			'kir'   => 'Kyrgyz',
			'kk'    => 'Kazakh',
			'km'    => 'Khmer',
			'kn'    => 'Kannada',
			'ko'    => 'Korean',
			'la'    => 'Latin',
			'lo'    => 'Lao',
			'lv'    => 'Latvian',
			'lt'    => 'Lithuanian',
			'mg'    => 'Malagasy',
			'mk'    => 'Macedonian',
			'ml'    => 'Malayalam',
			'mlt'   => 'Maltese',
			'mn'    => 'Mongolian',
			'mr'    => 'Marathi',
			'mri'   => 'Maori',
			'ms'    => 'Malay',
			'mya'   => 'Burmese',
			'nb'    => 'Norwegian Bokmål',
			'ne'    => 'Nepali',
			'nn'    => 'Norwegian Nynorsk',
			'no'    => 'Norwegian',
			'ory'   => 'Odia',
			'pa'    => 'Punjabi',
			'ps'    => 'Pashto',
			'fa'    => 'Persian',
			'pl'    => 'Polish',
			'pt'    => 'Portuguese',
			'pt-br' => 'Portuguese (Brazil)',
			'ro'    => 'Romanian',
			'ru'    => 'Russian',
			'sr'    => 'Serbian',
			'si'    => 'Sinhala',
			'sk'    => 'Slovak',
			'sl'    => 'Slovenian',
			'sna'   => 'Shona',
			'snd'   => 'Sindhi',
			'so'    => 'Somali',
			'sq'    => 'Albanian',
			'su'    => 'Sundanese',
			'es'    => 'Spanish',
			'es-ar' => 'Spanish (Argentina)',
			'es-cl' => 'Spanish (Chile)',
			'es-co' => 'Spanish (Colombia)',
			'es-mx' => 'Spanish (Mexico)',
			'es-pe' => 'Spanish (Peru)',
			'es-ve' => 'Spanish (Venezuela)',
			'sw'    => 'Swahili',
			'sv'    => 'Swedish',
			'ta'    => 'Tamil',
			'ta-lk' => 'Tamil (Sri Lanka)',
			'te'    => 'Telugu',
			'tg'    => 'Tajik',
			'th'    => 'Thai',
			'tir'   => 'Tigrinya',
			'tl'    => 'Tagalog',
			'tr'    => 'Turkish',
			'tt'    => 'Tatar',
			'tuk'   => 'Turkmen',
			'ug'    => 'Uyghur',
			'uk'    => 'Ukrainian',
			'ur'    => 'Urdu',
			'uz'    => 'Uzbek',
			'vi'    => 'Vietnamese',
			'cy'    => 'Welsh',
			'xho'   => 'Xhosa',
			'yi'    => 'Yiddish',
			'yor'   => 'Yoruba',
			'zul'   => 'Zulu',
		);

		return $locale_names;
	}
}
