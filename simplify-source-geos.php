<?php

define( 'WP_INSTALLING', TRUE );
$_SERVER['HTTP_HOST'] = 'bgeo.me';

require_once( dirname( dirname( dirname( __DIR__ ) ) ) . '/wp-load.php' );

if( function_exists( 'batcache_cancel' ) )
{
	// cancel batcache
	batcache_cancel();

	// turn off output buffering
	// do it three times for good measure
	ob_end_flush();
	ob_end_flush();
	ob_end_flush();
}

ini_set( 'memory_limit', '4G' );

class bGeo_Data_Simplify
{
	function get_and_split( $log_path, $src_path, $name_key, $group_key, $default_type, $out_path, $merge = FALSE )
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
			$error->text = 'can\'t create log file at ' . $log_path_file;
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
			'unsaved' => 0,
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

//		$source->features = array_slice($source->features , 0, 5);

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

			$log_geo_orig = $this->stats( $feature );

			echo "\nSimplified " . basename( $src_path, '.geojson' ) . ' ' . $feature->properties->name . "\n\n";

			// @TODO: log the execution time for this step
			$feature->geometry = $this->simplify( $feature );

			$log_geo_simpl = $this->stats( $feature );

/*
			fputcsv( $log_handle, array(
				'name' => isset( $feature->properties->name_conve ) ? $feature->properties->name_conve : $feature->properties->name,
				'orig_type' => $log_geo_orig->type,
				'orig_components' => $log_geo_orig->components,
				'orig_area' => $log_geo_orig->area,
				'simpl_type' => $log_geo_simpl->type,
				'simpl_components' => $log_geo_simpl->components,
				'simpl_area' => $log_geo_simpl->area,
			) );
*/
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
				file_put_contents( $out_path . $k . '.geojson', $this->json_encode( $v ) );

				foreach( $v->features as $kk => $vv )
				{

					$name_key_string = is_array( $name_key ) ? implode( ',' , $name_key ) : $name_key;
					$feature_name = is_array( $name_key ) ? implode( ', ' , array_intersect_key( (array) $vv->properties, array_flip( $name_key ) ) ) : $vv->properties->$name_key;

					if ( ! $feature_name )
					{
						$error->unsaved++;
						continue;
					}

					$bgeo_key = basename( $src_path ) . ' m=' . (int) $merge . ' ' . ( is_array( $group_key ) ? implode( ',' , $group_key ) : $group_key ) . '=' . $k . ' ' . $name_key_string . '=' . $feature_name;

					$saved = $this->insert_geo( (object) array(
						'bgeo_key'           => md5( $bgeo_key ),
						'bgeo_key_unencoded' => $bgeo_key,
						'bgeo_geometry'      => $vv->geometry,
						'bgeo_type'          => $default_type,
						'ne_name'            => $feature_name,
						'ne_admin'           => $vv->properties->admin,
						'ne_type'            => isset( $vv->properties->type ) ? $vv->properties->type : $default_type,
						'ne_properties'      => $vv->properties,
						'y_name'             => '',
						'y_type'             => '',
						'y_woeid'            => '',
						'y_parent_woeid'     => '',
						'y_response'         => '',
						'wikipedia_uri'      => is_numeric( $vv->properties->wikipedia ) ? '' : $vv->properties->wikipedia,
					) );

					if ( ! $saved )
					{
						$error->unsaved++;
					}
					echo "\nSaved " . $out_path .' '. $feature_name . "\n\n";
				}

				// explicitly clean up vars to save memory
				unset( $output->$k );
			}
			else
			{
				$output_merged->features[ $k ]->geometry = $this->merge_into_one( $v );
			}
		}

		// if we're merging, save the result
		if ( $merge )
		{
			// sort the array to reset the indexes to numeric
			sort( $output_merged->features );

			// save this json
			file_put_contents( $out_path . $merge . '.geojson', $this->json_encode( $output_merged ) );

			foreach( $output_merged->features as $kk => $vv )
			{
				$name_key_string = is_array( $name_key ) ? implode( ',' , $name_key ) : $name_key;
				$feature_name = is_array( $name_key ) ? implode( ', ' , array_intersect_key( (array) $vv->properties, array_flip( $name_key ) ) ) : $vv->properties->$name_key;

				if ( ! $feature_name )
				{
					$error->unsaved++;
					continue;
				}

				$bgeo_key = basename( $src_path ) . ' m=' . (int) $merge . ' ' . ( is_array( $group_key ) ? implode( ',' , $group_key ) : $group_key ) . '=' . $k . ' ' . $name_key_string . '=' . $feature_name;

				$saved = $this->insert_geo( (object) array(
					'bgeo_key' => md5( $bgeo_key ),
					'bgeo_key_unencoded' => $bgeo_key,
					'bgeo_geometry'      => $vv->geometry,
					'bgeo_type'          => $default_type,
					'ne_name'            => $feature_name,
					'ne_admin'           => $vv->properties->admin,
					'ne_type'            => isset( $vv->properties->type ) ? $vv->properties->type : $default_type,
					'ne_properties'      => $vv->properties,
					'y_name'             => '',
					'y_type'             => '',
					'y_woeid'            => '',
					'y_parent_woeid'     => '',
					'y_response'         => '',
					'wikipedia_uri'      => is_numeric( $vv->properties->wikipedia ) ? '' : $vv->properties->wikipedia,
				) );
			}

			if ( ! $saved )
			{
				$error->unsaved++;
			}

			echo "\nSaved " . $out_path .' '. $feature_name. "\n\n";
		}

		// explicitly clean up vars to save memory
		unset( $source, $output, $output_merged );

		// return any errors
		return $error;
	}

	function stats( $src )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );

		return (object) array(
			'type' => $geometry->geometryType(),
			'components' => count( (array) $geometry->getComponents() ),
			'area' => $geometry->area(),
		);
	}

	function insert_geo( $data )
	{
		global $wpdb;

		if ( empty( $data->bgeo_geometry ) )
		{
			echo "\n ERROR: Empty geometry!\n\n";
			print_r( $data );
			return FALSE;
			echo "\n\n";
		}

		$bgeo_geometry = $this->new_geometry( json_encode( $data->bgeo_geometry ), 'json' );

		$sql = $wpdb->prepare(
			'INSERT INTO bgeo_data
			(
				bgeo_key,
				bgeo_key_unencoded,
				bgeo_geometry,
				bgeo_type,
				ne_name,
				ne_admin,
				ne_properties,
				y_name,
				y_type,
				y_woeid,
				y_parent_woeid,
				y_response,
				wikipedia_uri
			)
			VALUES(
				\'%1$s\',
				\'%2$s\',
				GeomFromText( "%3$s" ),
				\'%4$s\',
				\'%5$s\',
				\'%6$s\',
				\'%7$s\',
				\'%8$s\',
				\'%9$s\',
				\'%10$s\',
				\'%11$s\',
				\'%12$s\',
				\'%13$s\'
			)
			ON DUPLICATE KEY UPDATE
				bgeo_key = VALUES( bgeo_key ),
				bgeo_key_unencoded = VALUES( bgeo_key_unencoded ),
				bgeo_geometry = VALUES( bgeo_geometry ),
				bgeo_type = VALUES( bgeo_type ),
				ne_name = VALUES( ne_name ),
				ne_admin = VALUES( ne_admin ),
				ne_properties = VALUES( ne_properties ),
				y_name = VALUES( y_name ),
				y_type = VALUES( y_type ),
				y_woeid = VALUES( y_woeid ),
				y_parent_woeid = VALUES( y_parent_woeid ),
				y_response = VALUES( y_response ),
				wikipedia_uri = VALUES( wikipedia_uri )
			',
			$data->bgeo_key,
			$data->bgeo_key_unencoded,
			$bgeo_geometry->asText(),
			$data->bgeo_type,
			$data->ne_name,
			$data->ne_admin,
			serialize( $data->ne_properties ),
			$data->y_name,
			$data->y_type,
			$data->y_woeid,
			$data->y_parent_woeid,
			$data->y_response,
			$data->wikipedia_uri
		);

		// execute the query
		$wpdb->query( $sql );

		return TRUE;
	}

	function json_encode( $src )
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
		$geometry = $this->new_geometry( $src, 'json' );

		echo 'merge orig: ' . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->area() . " area\n";

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
		foreach ( $parts as $k => $part )
		{
			$whole = $whole->union( $part );
			echo 'merge step ' . $k . ': ' . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->area() . " area\n";
		}

		// return the merged and smoother result
		return $this->simplify( $whole->out( 'json' ) );
	}

	function simplify( $src )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );
		$orig_area = $geometry->envelope()->area();

		echo 'simp orig: ' . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->envelope()->area() . " area\n";

		$buffer_factor = 1.09;
		$buffer_buffer_factor = 0.020;
		$simplify_factor = 0.050;
		$iteration = 1;

		do
		{
			echo "simp attempt $iteration with buffer( " . ( $buffer_factor + $buffer_buffer_factor ) . " ) and simplify( $simplify_factor )\n";

			$simple_geometry = clone $geometry;
			$simple_geometry = $simple_geometry->buffer( $buffer_factor + $buffer_buffer_factor )->simplify( $simplify_factor, FALSE )->buffer( $buffer_factor * -1 );
			$simple_area = $simple_geometry->envelope()->area();

			// $buffer_factor += 0.01;
			$buffer_buffer_factor += 0.01;
			$simplify_factor -= 0.002;
			$iteration += 1;
		}
		while ( $orig_area > $simple_area );

		echo 'simp simp: ' . $simple_geometry->geometryType() . ': ' . count( (array) $simple_geometry->getComponents() ) . ' components with ' . $simple_geometry->envelope()->area() . " area\n";

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
}

$bgeo_data = new bGeo_Data_Simplify();

$sources = array(
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'name_key' => 'admin',
		'group_key' => 'continent',
		'default_type' => 'country',
		'out_path' => '/simplified-geos/countries/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'name_key' => 'region_wb',
		'group_key' => 'region_wb',
		'default_type' => 'country-group-merged',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'country-groups',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'name_key' => 'name',
		'group_key' => 'admin',
		'default_type' => 'state-or-province',
		'out_path' => '/simplified-geos/states-and-provinces/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'name_key' => array( 'admin', 'region' ),
		'group_key' => array( 'admin', 'region' ),
		'default_type' => 'state-and-province-group-merged',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'state-and-province-groups',
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'name_key' => array( 'admin', 'region_big' ),
		'group_key' => array( 'admin', 'region_big' ),
		'default_type' => 'state-and-province-group-large-merged',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'state-and-province-groups-large',
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'name_key' => 'name',
		'group_key' => 'region',
		'default_type' => 'region',
		'out_path' => '/simplified-geos/regions/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'name_key' => 'region',
		'group_key' => 'region',
		'default_type' => 'region-merged',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'regions',
	),
	(object) array(
		'src_file' => 'ne_10m_geography_regions_polys.geojson',
		'name_key' => 'subregion',
		'group_key' => 'subregion',
		'default_type' => 'subregion-merged',
		'out_path' => '/simplified-geos/regions/',
		'merge' => 'subregions',
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'name_key' => 'unit_name',
		'group_key' => 'unit_type',
		'default_type' => 'parks-or-protected-land',
		'out_path' => '/simplified-geos/parks-and-protected-lands/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'name_key' => 'name',
		'group_key' => 'featurecla',
		'default_type' => 'water-feature',
		'out_path' => '/simplified-geos/water-features/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'name_key' => 'name',
		'group_key' => 'featurecla',
		'default_type' => 'lake',
		'out_path' => '/simplified-geos/water-features/',
		'merge' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan.geojson',
		'name_key' => 'name_conve',
		'group_key' => 'max_pop_al',
		'default_type' => 'urban-area',
		'out_path' => '/simplified-geos/urban-areas/',
		'merge' => FALSE,
	),
/*
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan_truncated.geojson',
		'name_key' => 'name',
		'group_key' => 'max_pop_al',
		'default_type' => 'populated_place',
		'out_path' => '/simplified-geos/urban-areas-test/',
		'merge' => FALSE,
	),

*/
);

foreach ( $sources as $source )
{
	print_r( $bgeo_data->get_and_split( __DIR__ . '/logs/', __DIR__ . '/naturalearthdata/' . $source->src_file, $source->name_key, $source->group_key, $source->default_type, __DIR__ . $source->out_path, $source->merge ) );
}
