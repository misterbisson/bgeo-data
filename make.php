<?php

ini_set( 'memory_limit', '4G' );

function get_and_split( $src_path, $group_key, $out_path )
{
	// init the output and error var
	$output = (object) array();
	$error = (object) array(
		'nogroup' => 0,
		'text' => '',
	);

	// the skeleton object for the geo feature
	$feature_skel = (object) array(
		'type' => 'FeatureCollection',
		'features' => array(),
	);

	// attempt to read the source file
	$source = json_decode( file_get_contents( $src_path ) );

	// sanity check that read
	if ( ! is_object( $source ) )
	{
		$error->text = 'can\'t json_decode() or read source file from ' . $src_path;
		return $error;
	}

	// iterate through the source, separate features
	foreach ( $source->features as $feature )
	{

		// set the name of the group
		if( is_string( $group_key ) || is_array( $group_key ) )
		{
			$group = '';
			foreach ( (array) $group_key as $one_group_key )
			{

				// sanity check the date to confirm it has the group info we're looking for
				if ( ! isset( $feature->properties->$one_group_key ) )
				{
					$error->nogroup++;
					continue count( (array) $group_key );
				}
	
				// get the group partial name
				$_group = preg_replace( '/[^a-z0-9]/', '-', strtolower( $feature->properties->$one_group_key ) );		

				if ( empty( $group ) )
				{
					$group = $_group;
				}
				else
				{
					$group .= '-'. $_group;
				}
			}
		}
		else
		{
			$error->text = 'no valid group(s) specified';
			return $error;
		}

		// sanity check the group one last time
		if ( empty( $group ) )
		{
			$error->nogroup++;
			continue;
		}

		// initialize this group in the output
		if ( ! isset( $output->$group ) )
		{
			$output->$group = clone $feature_skel;
		}

		// add this feature to the group in the output var
		$output->$group->features[] = $feature;
	}

	// check for and attempt to create the output directory
	if ( ! ( file_exists( $out_path ) && is_dir( $out_path ) ) )
	{
		if ( ! mkdir( $out_path, 0755, TRUE ) )
		{
			$error->text = 'can\'t create output directory';
			return $error;
		}
	}

	// no more playing around, output this stuff
	foreach ( $output as $k => $v )
	{
		file_put_contents( $out_path . $k . '.geojson', json_encode( $v ) );
	}

	// explicitly clean up vars to save memory
	unset( $source, $output );

	// return any errors
	return $error;
}

function new_geometry( $input, $adapter )
{
	if ( ! class_exists( 'geoPHP' ) )
	{
		require_once __DIR__ . '/components/external/geoPHP/geoPHP.inc';
	}

	return geoPHP::load( $input, $adapter );
} // END new_geometry


$sources = array(
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'group_key' => 'continent',
		'out_path' => '/output/countries/',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'group_key' => 'region_wb',
		'out_path' => '/output/regions-countries/',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => 'admin',
		'out_path' => '/output/states-and-provinces/',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region' ),
		'out_path' => '/output/regions-states-and-provinces/',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region_big' ),
		'out_path' => '/output/regions-states-and-provinces/',
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'group_key' => 'region',
		'out_path' => '/output/regions/',
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'group_key' => 'unit_type',
		'out_path' => '/output/parks-and-protected-lands/',
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/output/water-features/',
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/output/water-features/',
	),

/*
this is disabled because no groups are obvious yet
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan.geojson',
		'group_key' => 'name_conve',
		'out_path' => '/output/urban-areas/',
	),
*/
);
/*
foreach ( $sources as $source )
{
	print_r( get_and_split( __DIR__ . '/naturalearthdata/' . $source->src_file, $source->group_key, __DIR__ . $source->out_path ) );
}
*/

$geometry = new_geometry( file_get_contents( __DIR__ . '/output/region-states-and-provinces/sudan-darfour-darfur.geojson' ), 'json' );
$parts = $geometry->getComponents();
$whole = $parts[0];
unset( $parts[0] );
foreach ( $parts as $part )
{
	$whole = $whole->union( $part->buffer( 1.1 ) );
	echo $whole->area() . "\n";
}
echo( $whole->buffer( -1 )->simplify( .1, FALSE )->out( 'json' ) ) . "\n";
