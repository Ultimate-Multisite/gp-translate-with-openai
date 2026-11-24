<?php
/** Human import/sync regressions using in-memory GlotPress models and a local PO ZIP. */
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/class-human-translations.php';
require_once dirname( __DIR__ ) . '/src/class-automation.php';

use Meloniq\GpOpenaiTranslate\Automation;
use Meloniq\GpOpenaiTranslate\Human_Translations as Policy;

final class GP_Locales {
	public static function by_slug( $slug ) {
		return (object) array(
			'nplurals'  => 'ar' === $slug ? 6 : 3,
			'wp_locale' => 'ar' === $slug ? 'ar' : 'bs_BA',
		); }
}
class GP_Translation {
	public static $number_of_plural_translations = 6;
}
#[AllowDynamicProperties]
final class Row {
	public function __construct( $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value; } }
	public function save( $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		} return true; }
	public function delete() {
		unset( GP::$translation->rows[ $this->id ] );
		return true; }
}
final class Store {
	public $rows = array();
	public function find_many( $where ) {
		return array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $where ) {
					foreach ( $where as $key => $value ) {
						if ( ( $row->$key ?? null ) != $value ) {
								return false; }
					}
					return true;
				}
			)
		);
	}
	public function find_one( $where ) {
		return $this->find_many( $where )[0] ?? null; }
	public function create( $data ) {
		$id                       = count( $this->rows ) + 1;
		return $this->rows[ $id ] = new Row(
			$data + array(
				'id'      => $id,
				'user_id' => null,
			)
		);
	}
}
final class Originals {
	public $original;
	public function get( $id ) {
		return $this->original; }
	public function by_project_id_and_entry( $id, $entry, $status ) {
		if ( ( $entry->context ?? null ) !== ( $this->original->context ?? null ) ) {
			return null; }
		return $this->original;
	}
}
final class GP {
	public static $translation;
	public static $original;
	public static $translation_set;
	public static $project;
	public static $translation_warnings;
}
final class Provider {
	public static $calls = 0;
	public static function instance() {
		return new self(); }
	public function translate_plural( ...$args ) {
		++self::$calls;
		return array( '%s AI0', '%s AI1', '%s AI2' ); }
}
class_alias( Provider::class, 'Meloniq\\GpOpenaiTranslate\\Translate' );
final class PO {
	public $entries;
	public function import_from_file( $file ) {
		$this->entries = array( $GLOBALS['entry'] );
		return true; }
}
$meta = array();
function gp_get_meta( $type, $id, $key ) {
	return $GLOBALS['meta'][ $id ][ $key ] ?? null; }
function gp_update_meta( $id, $key, $value, $type ) {
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	return true; }
function gp_clean_translation_set_cache( $id ) {}
function is_wp_error( $value ) {
	return false; }
function get_temp_dir() {
	return $GLOBALS['temp'] . '/'; }
function wp_delete_file( $file ) {
	unlink( $file ); }
function translations_api( ...$args ) {
	return array(
		'translations' => array(
			array(
				'language' => 'bs_BA',
				'package'  => 'fixture',
			),
		),
	); }
function wp_remote_get( ...$args ) {
	return file_get_contents( $GLOBALS['zip'] ); }
function wp_remote_retrieve_response_code( $response ) {
	return 200; }
function wp_remote_retrieve_body( $response ) {
	return $response; }
function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	} print "PASS $label\n"; }

$root = getenv( 'AIDEVOPS_TEMP_DIR' ) ?: getenv( 'HOME' ) . '/.aidevops/.agent-workspace/tmp';
$temp = $root . '/gp-human-plurals-' . getmypid();
mkdir( $temp, 0700, true );
$zip     = $temp . '/fixture.zip';
$archive = new ZipArchive();
$archive->open( $zip, ZipArchive::CREATE );
$archive->addFromString( 'fixture.po', 'fixture parsed by PO test double' );
$archive->close();
try {
	GP::$translation        = new Store();
	GP::$original           = new Originals();
	GP::$original->original = (object) array(
		'id'       => 7,
		'singular' => '%s day',
		'plural'   => '%s days',
		'context'  => 'period',
	);
	$set                    = (object) array(
		'id'     => 3,
		'locale' => 'bs',
	);
	$project                = (object) array( 'id' => 1 );
	$entry                  = (object) array(
		'singular'     => '%s day',
		'plural'       => '%s days',
		'context'      => 'period',
		'translations' => array( '%s human0', '%s human1' ),
	);
	Automation::import_wporg_translations( $project, $set, 'fixture', 'bs_BA' );
	check( ! GP::$translation->find_one( array( 'status' => 'current' ) ) && count( GP::$translation->rows ) === 1, 'two of three forms imported as waiting' );
	check( 0 === Automation::import_wporg_translations( $project, $set, 'fixture', 'bs_BA' ) && count( GP::$translation->rows ) === 1, 'repeated partial import is idempotent and reports no changes' );
	$current = GP::$translation->create(
		array(
			'original_id'        => 7,
			'translation_set_id' => 3,
			'status'             => 'current',
			'user_id'            => null,
			'translation_0'      => '%s human0',
			'translation_1'      => '%s human1',
			'translation_2'      => '%s AI2',
		)
	);
	Policy::record(
		$current,
		array(
			'translation_0' => '%s human0',
			'translation_1' => '%s human1',
		),
		array( 2 )
	);
	Automation::replace_ai_with_human( $project, $set, 'fixture', 'bs_BA' );
	check( '%s AI2' === $current->translation_2 && 'current' === $current->status, 'partial human sync cannot erase AI-filled third form' );
	$entry->translations = array( '%s changed0', '%s changed1', '%s human2' );
	Automation::replace_ai_with_human( $project, $set, 'fixture', 'bs_BA' );
	check( '%s human2' === $current->translation_2 && '%s changed0' === $current->translation_0, 'complete human sync updates all three slots' );
	check( array() === gp_get_meta( 'translation', $current->id, Policy::AI_FORMS ), 'complete human replacement removes AI provenance' );
	check( 0 === Automation::replace_ai_with_human( $project, $set, 'fixture', 'bs_BA' ), 'unchanged complete sync reports no replacements' );
	$entry->translations = array( '%s changed0', '%s changed1', '%s newer2' );
	Automation::replace_ai_with_human( $project, $set, 'fixture', 'bs_BA' );
	check( '%s newer2' === $current->translation_2, 'third-slot change applied even when first form unchanged' );
	$current->user_id    = 9;
	$entry->translations = array( '%s forbidden0', '%s forbidden1', '%s forbidden2' );
	Automation::replace_ai_with_human( $project, $set, 'fixture', 'bs_BA' );
	check( '%s changed0' === $current->translation_0, 'manual current translation protected' );
	$entry->context = 'another meaning';
	Automation::import_wporg_translations( $project, $set, 'fixture', 'bs_BA' );
	check( count( GP::$translation->rows ) === 2, 'same source with different context is not conflated' );
	// Exercise the real scheduled batch path, not just the import policy.
	GP::$translation          = new Store();
	$meta                     = array();
	$entry->context           = 'period';
	$entry->translations      = array( '%s human0', '', '%s human2' );
	GP::$project              = new class( $project ) {
		private $value;
		public function __construct( $value ) {
			$this->value       = $value;
			$this->value->slug = 'fixture'; }
		public function get( $id ) {
			return $this->value; }
	};
	GP::$translation_set      = new class( $set ) {
		private $value;
		public function __construct( $value ) {
			$this->value = $value; }
		public function get( $id ) {
			return $this->value; }
	};
	GP::$translation_warnings = new class() { public function check( ...$args ) {
			return array();
	} };
	$automation               = ( new ReflectionClass( Automation::class ) )->newInstanceWithoutConstructor();
	$automation->process_translation_batch( 1, 3, array( 7 ) );
	$generated = GP::$translation->find_one( array( 'status' => 'current' ) );
	check( $generated && '%s human0' === $generated->translation_0 && '%s AI1' === $generated->translation_1 && '%s human2' === $generated->translation_2, 'scheduled AI batch fills only missing human forms' );
	check( array( 1 ) === gp_get_meta( 'translation', $generated->id, Policy::AI_FORMS ), 'scheduled batch records mixed provenance' );
	$automation->process_translation_batch( 1, 3, array( 7 ) );
	check( 1 === Provider::$calls, 'complete current plural skips repeated AI calls' );
	$six = (object) array( 'translations' => array( '0', 'one', 'two', 'few', 'many', 'other' ) );
	Policy::import_entry(
		(object) array(
			'id'     => 8,
			'plural' => '%s days',
		),
		(object) array(
			'id'     => 4,
			'locale' => 'ar',
		),
		$six
	);
	$arabic = GP::$translation->find_one( array( 'translation_set_id' => 4 ) );
	check( '0' === $arabic->translation_0 && 'other' === $arabic->translation_5, 'Arabic six-slot import and zero supported' );
	print "Human import/sync regression checks passed.\n";
} finally {
	foreach ( glob( $temp . '/*' ) as $file ) {
		unlink( $file ); }
	rmdir( $temp );
}
