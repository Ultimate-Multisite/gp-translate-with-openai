<?php
/**
 * Setup sample GlotPress data for development.
 *
 * Run via: wp eval-file setup-sample-data.php
 */

if ( ! class_exists( 'GP' ) ) {
	WP_CLI::error( 'GlotPress is not active.' );
	return;
}

// Check if sample project already exists.
$existing = GP::$project->by_path( 'sample-project' );
if ( $existing ) {
	WP_CLI::log( 'Sample data already exists, skipping.' );
	return;
}

// Create project.
$project = GP::$project->create(
	array(
		'name'                => 'Sample Project',
		'slug'                => 'sample-project',
		'path'                => 'sample-project',
		'description'         => 'A sample project for development and testing.',
		'active'              => 1,
		'parent_project_id'   => 0,
	)
);

if ( ! $project ) {
	WP_CLI::error( 'Failed to create sample project.' );
	return;
}

WP_CLI::log( "Created project: {$project->name} (ID: {$project->id})" );

// Create translation sets for a few locales.
$locales = array(
	'es' => 'Spanish',
	'de' => 'German',
	'fr' => 'French',
);

foreach ( $locales as $slug => $name ) {
	$locale = GP_Locales::by_slug( $slug );
	if ( ! $locale ) {
		WP_CLI::warning( "Locale {$slug} not found, skipping." );
		continue;
	}

	$set = GP::$translation_set->create(
		array(
			'name'       => $name,
			'slug'       => 'default',
			'project_id' => $project->id,
			'locale'     => $slug,
		)
	);

	if ( $set ) {
		WP_CLI::log( "Created translation set: {$name} ({$slug}) ID: {$set->id}" );
	}
}

// Add some sample originals.
$strings = array(
	array( 'singular' => 'Hello World', 'context' => '' ),
	array( 'singular' => 'Save Changes', 'context' => '' ),
	array( 'singular' => 'Settings', 'context' => 'menu item' ),
	array( 'singular' => 'Are you sure you want to delete this item?', 'context' => '' ),
	array( 'singular' => 'Search results for: %s', 'context' => '' ),
	array( 'singular' => '%d item', 'plural' => '%d items', 'context' => '' ),
	array( 'singular' => 'Dashboard', 'context' => '' ),
	array( 'singular' => 'Upload', 'context' => '' ),
	array( 'singular' => 'Cancel', 'context' => '' ),
	array( 'singular' => 'Edit Profile', 'context' => '' ),
);

foreach ( $strings as $string ) {
	$data = array(
		'project_id' => $project->id,
		'singular'   => $string['singular'],
		'status'     => '+active',
	);

	if ( ! empty( $string['plural'] ) ) {
		$data['plural'] = $string['plural'];
	}

	if ( ! empty( $string['context'] ) ) {
		$data['context'] = $string['context'];
	}

	GP::$original->create( $data );
}

WP_CLI::log( 'Added ' . count( $strings ) . ' sample originals.' );
WP_CLI::success( 'Sample data setup complete. Visit /projects/sample-project/ to see it.' );
