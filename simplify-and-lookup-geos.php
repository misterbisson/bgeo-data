<?php

//define( 'WP_INSTALLING', TRUE );
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
	function get_and_split( $src_path, $name_keys )
	{
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

		$source->features = array_slice( $source->features , 0, 15 );

		// iterate through the source, separate features
		echo "\n";
		foreach ( $source->features as $feature )
		{
			if ( ! is_array( $name_keys ) )
			{
				$name_keys = array( $name_keys );
			}

			// iterate through the name keys, adding pieces until the search returns a result that works
			$search_name = $this->centroid( $feature )->latlon;
			foreach ( $name_keys as $name_key )
			{
				$search_name .= ' ' . preg_replace( '/[0-9]*/', '', $feature->properties->$name_key );
				$locations = bgeo()->admin()->posts()->locationlookup( $search_name );

				echo "Searching for $search_name\n";

				//print_r( $feature->properties );
				//print_r( $locations );

				// the location lookup can return multiple locations, inspect each
				foreach ( $locations as $location )
				{
					// is the centroid of this looked up location inside the source geography envelope?
					$contained = $this->contains( $feature, (object) array( 'lon' => $location->point_lon, 'lat' => $location->point_lat ) );
					echo "Contained? ";
					var_dump( $contained );
					echo "\n\n";
					if( $contained )
					{
						break 2;
					}
				}
			}

//die;
continue;




			$log_geo_orig = $this->stats( $feature );

			echo "\nSimplified " . basename( $src_path, '.geojson' ) . ' ' . $feature->properties->name . "\n\n";

			// @TODO: log the execution time for this step
			$feature->geometry = $this->simplify( $feature );

			$log_geo_simpl = $this->stats( $feature );
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
				w_uri
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
			)
			ON DUPLICATE KEY UPDATE
				bgeo_key = VALUES( bgeo_key ),
				bgeo_key_unencoded = VALUES( bgeo_key_unencoded ),
				bgeo_geometry = VALUES( bgeo_geometry ),
				bgeo_type = VALUES( bgeo_type ),
				ne_name = VALUES( ne_name ),
				ne_admin = VALUES( ne_admin ),
				ne_properties = VALUES( ne_properties ),
				w_uri = VALUES( w_uri )
			',
			$data->bgeo_key,
			$data->bgeo_key_unencoded,
			$bgeo_geometry->asText(),
			$data->bgeo_type,
			$data->ne_name,
			$data->ne_admin,
			serialize( $data->ne_properties ),
			$data->w_uri
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

	function centroid( $src )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );
		$centroid = $geometry->centroid();

		return (object) array(
			'latlon' => $centroid->y() . ' ' . $centroid->x(),
			'y' => $centroid->y(),
			'lat' => $centroid->y(),
			'x' => $centroid->x(),
			'lon' => $centroid->x(),
		);
	}

	function contains( $src, $point )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );
		$bigenvelope = $geometry->buffer( 1.05 )->envelope();

//echo $bigenvelope->out( 'json' );

		// create a point geo from the provided point lat and lon
		$point = $this->new_geometry( 'POINT (' . $point->lon . ' ' . $point->lat . ')', 'wkt' );

//echo $point->out( 'json' );

		// is the point inside the geo?
		return $bigenvelope->contains( $point );
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
/*
*/
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'name_key' => array( 'admin', 'sovereignt' ),
		'woe_types' => array( 12 ),
		'constrain' => FALSE,
	),
/*
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'name_key' => 'name',
		'woe_types' => array( 8 ),
		'constrain' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'name_key' => 'unit_name',
		'woe_types' => array( 13, 16, 20 ),
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'name_key' => 'name',
		'woe_types' => array( 15, 37, 38 ),
		'constrain' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'name_key' => 'name',
		'woe_types' => array( 15, 37, 38 ),
	),
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan.geojson',
		'name_key' => 'name_conve',
		'woe_types' => array( 7 ),
		'constrain' => TRUE,
	),
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan_truncated.geojson',
		'name_key' => 'name',
		'woe_types' => array( 7 ),
		'constrain' => TRUE,
	),
*/
);

foreach ( $sources as $source )
{
	print_r( $bgeo_data->get_and_split( __DIR__ . '/naturalearthdata/' . $source->src_file, $source->name_key ) );
}

