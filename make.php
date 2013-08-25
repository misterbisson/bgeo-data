<?php

ini_set( 'memory_limit', '4G' );

function get_and_split( $src_path, $group_key, $out_path, $merge = FALSE )
{
	// init the output and error var
	$output = (object) array();
	$output_merged = (object) array();
	$error = (object) array(
		'nogroup' => 0,
		'text' => '',
	);

	// the skeleton objects for the geo feature
	$feature_skel = (object) array(
		'type' => 'FeatureCollection',
		'features' => array(),
	);
	$merged_skel = (object) array(
		'type' => 'Feature',
		'properties' => (object) array(),
		'geometry' => (object) array(),
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
			$merged_props = (object) array();
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

				// set the properties for the merged result, if we do merge
				$merged_props->$one_group_key = $feature->properties->$one_group_key;
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

		// initialize merged output group and properties
		if ( ! isset( $output_merged->$group ) )
		{
			$output_merged->$group = clone $merged_skel;
			$output_merged->$group->properties = $merged_props;
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
		// maybe merge the geometry
		if ( $merge )
		{
			$output_merged->$k->geometry = json_decode( merge_into_one( $v ) );
			$v = $output_merged->$k;
		}

		// save this json
		file_put_contents( $out_path . $k . '.geojson', json_encode( $v ) );

		// explicitly clean up vars to save memory
		unset( $output->$k, $output_merged->$k );
	}

	// explicitly clean up vars to save memory
	unset( $source, $output, $output_merged );

	// return any errors
	return $error;
}

function merge_into_one( $src )
{
	// get a geometry from the input json
	$geometry = new_geometry( $src, 'json' );

	// break the geometry into sub-components
	$parts = $geometry->getComponents();

	// sanity check
	if ( ! is_array( $parts ) )
	{
		return $geometry->out( 'json' );
	}

	// merge the parts into a single whole
	$whole = $parts[0];
	unset( $parts[0] );
	foreach ( $parts as $part )
	{
		$whole = $whole->union( $part->buffer( 1.1 ) );
		echo $whole->area() . "\n";
	}

	// return the merged and smoother result
	return $whole->buffer( -1 )->simplify( .1, FALSE )->out( 'json' );
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
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'group_key' => 'region_wb',
		'out_path' => '/output/regions-countries/',
		'merge' => TRUE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => 'admin',
		'out_path' => '/output/states-and-provinces/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region' ),
		'out_path' => '/output/regions-states-and-provinces/',
		'merge' => TRUE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region_big' ),
		'out_path' => '/output/regions-states-and-provinces/',
		'merge' => TRUE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'group_key' => 'region',
		'out_path' => '/output/regions/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'group_key' => 'unit_type',
		'out_path' => '/output/parks-and-protected-lands/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/output/water-features/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/output/water-features/',
		'merge' => FALSE,
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

foreach ( $sources as $source )
{
	print_r( get_and_split( __DIR__ . '/naturalearthdata/' . $source->src_file, $source->group_key, __DIR__ . $source->out_path, $source->merge ) );
}
