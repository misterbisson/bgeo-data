<?php

ini_set( 'memory_limit', '4G' );

function get_and_split( $log_path, $src_path, $group_key, $out_path, $merge = FALSE )
{

	// check for and attempt to create the output directory
	if ( ! ( file_exists( $out_path ) && is_dir( $out_path ) ) )
	{
		if ( ! mkdir( $out_path, 0755, TRUE ) )
		{
			$error->text = 'can\'t create output directory';
			return $error;
		}
	}

	// check for and attempt to create the log directory
	if ( ! ( file_exists( $log_path ) && is_dir( $log_path ) ) )
	{
		if ( ! mkdir( $log_path, 0755, TRUE ) )
		{
			$error->text = 'can\'t create log directory';
			return $error;
		}
	}

	// start the log csv file
	$log_path_file = $log_path . preg_replace( '/[^a-zA-Z0-9]/', '-', basename( $src_path, '.geojson' ) ) . '.csv';

/*
commented out because it's not really needed

	make sure the file name is unique
	$increment = 1;
	do
	{
		$log_path_file = $log_path . preg_replace( '/[^a-zA-Z0-9]/', '-', basename( $src_path, '.geojson' ) ) . '-pass-' . $increment . '.csv';
		$increment++;
	}
	while ( file_exists( $log_path_file ) );
*/

	$log_handle = fopen( $log_path_file, 'w' );
	if ( ! $log_handle )
	{
		$error->text = 'can\t create log file at ' . $log_path_file;
		return $error;
	}

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

	// init the output and error var
	$output = (object) array();
	$output_merged = clone $feature_skel;
	$error = (object) array(
		'nogroup' => 0,
		'text' => '',
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
				// special handling for numerics
				if ( is_numeric( $feature->properties->$one_group_key ) )
				{
					$_group = (string) pow( 10, strlen( ceil( $feature->properties->$one_group_key ) ) );
				}
				// sanitize text group names
				else
				{
					$_group = preg_replace( '/[^a-z0-9]/', '-', strtolower( $feature->properties->$one_group_key ) );
				}

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
		if ( ! isset( $output_merged->features[ $group ] ) )
		{
			$output_merged->features[ $group ] = clone $merged_skel;
			$output_merged->features[ $group ]->properties = $merged_props;
		}

		$log_geo_orig = geometry_stats( $feature );

		$feature->geometry = simplify_geometry( $feature );

		$log_geo_simpl = geometry_stats( $feature );

		fputcsv( $log_handle, array(
			'name' => $feature->properties->name,
			'orig_type' => $log_geo_orig->type,
			'orig_components' => $log_geo_orig->components,
			'orig_area' => $log_geo_orig->area,
			'simpl_type' => $log_geo_simpl->type,
			'simpl_components' => $log_geo_simpl->components,
			'simpl_area' => $log_geo_simpl->area,
		) );

		// add this feature to the group in the output var
		$output->$group->features[] = $feature;
	}

	// no more playing around, output this stuff
	foreach ( $output as $k => $v )
	{
		// save this or merge it
		if ( ! $merge )
		{
			// save this json
			file_put_contents( $out_path . $k . '.geojson', bgeo_json_encode( $v ) );

			// explicitly clean up vars to save memory
			unset( $output->$k );
		}
		else
		{
			$output_merged->features[ $k ]->geometry = merge_into_one( $v );
		}
	}

	// if we're merging, save the result
	if ( $merge )
	{
		// sort the array to reset the indexs to numeric
		sort( $output_merged->features );

		// save this json
		file_put_contents( $out_path . $merge . '.geojson', bgeo_json_encode( $output_merged ) );
	}

	// explicitly clean up vars to save memory
	unset( $source, $output, $output_merged );

	// return any errors
	return $error;
}

function geometry_stats( $src )
{
	// get a geometry from the input json
	$geometry = new_geometry( $src, 'json' );

	return (object) array(
		'type' => $geometry->geometryType(),
		'components' => count( (array) $geometry->getComponents() ),
		'area' => $geometry->area(),
	);
}

function bgeo_json_encode( $src )
{
	return str_ireplace( 
		array(
			'"features":', // separates the preamble from the content
			'},{',      // separates features from eachother
			',"geometry"', // separates the geometry from the properties
		), 
		array(
			"\"features\":\n",
			"}\n,\n{",
			",\n\"geometry\"",
		), 
		json_encode( $src )
	);
}

function merge_into_one( $src )
{
	// get a geometry from the input json
	$geometry = new_geometry( $src, 'json' );

	echo 'orig: ' . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->area() . " area\n";

	// break the geometry into sub-components
	$parts = $geometry->getComponents();

	// sanity check
	if ( ! is_array( $parts ) )
	{
		return json_decode( $geometry->out( 'json' ) );
	}

	// merge the parts into a single whole
	$whole = $parts[0];
	unset( $parts[0] );
	foreach ( $parts as $part )
	{
		$whole = $whole->union( $part->buffer( 1.1 ) );
		echo 'simp: ' . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->area() . " area\n";
	}

	// return the merged and smoother result
	return json_decode( $whole->simplify( .1, FALSE )->buffer( -1 )->out( 'json' ) );
}

function simplify_geometry( $src )
{
	// get a geometry from the input json
	$geometry = new_geometry( $src, 'json' );
	$orig_area = $geometry->area();

	echo 'orig: ' . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->area() . " area\n";

	$buffer_factor = 1.50;
	$buffer_buffer_factor = 0.01;
	$simplify_factor = 0.050;
	$iteration = 1;

	do
	{
		echo "Attempt $iteration with buffer( " . ( $buffer_factor + $buffer_buffer_factor ) . " ) and simplify( $simplify_factor )\n";

		$simple_geometry = clone $geometry;
		$simple_geometry = $simple_geometry->buffer( $buffer_factor + $buffer_buffer_factor )->simplify( $simplify_factor, FALSE )->buffer( $buffer_factor * -1 );
		$simple_area = $simple_geometry->area();

		// $buffer_factor += 0.01;
		$buffer_buffer_factor += 0.01;
		$simplify_factor -= 0.002;
		$iteration += 1;
	}
	while ( $orig_area > $simple_area );

	echo 'simp: ' . $simple_geometry->geometryType() . ': ' . count( (array) $simple_geometry->getComponents() ) . ' components with ' . $simple_geometry->area() . " area\n";

	$return = json_decode( $simple_geometry->out( 'json' ) );

	return json_decode( $simple_geometry->out( 'json' ) );
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
		'out_path' => '/simplified-geos/countries/',
		'merge' => FALSE,
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'group_key' => 'region_wb',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'country-groups',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => 'admin',
		'out_path' => '/simplified-geos/states-and-provinces/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region' ),
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'state-and-province-groups',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'group_key' => array( 'admin', 'region_big' ),
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'state-and-province-groups-large',
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'group_key' => 'region',
		'out_path' => '/simplified-geos/regions/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'group_key' => 'subregion',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'regions',
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'group_key' => 'unit_type',
		'out_path' => '/simplified-geos/parks-and-protected-lands/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/simplified-geos/water-features/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'group_key' => 'featurecla',
		'out_path' => '/simplified-geos/water-features/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan.geojson',
		'group_key' => 'max_pop_al',
		'out_path' => '/simplified-geos/urban-areas/',
		'merge' => FALSE,
	),
/*
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan_trancated.geojson',
		'group_key' => 'max_pop_al',
		'out_path' => '/simplified-geos/urban-areas-test/',
		'merge' => FALSE,
	),

*/
);

foreach ( $sources as $source )
{
	print_r( get_and_split( __DIR__ . '/logs/', __DIR__ . '/naturalearthdata/' . $source->src_file, $source->group_key, __DIR__ . $source->out_path, $source->merge ) );
}
