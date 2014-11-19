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

class bGeo_Data_SimplifyCorrelate
{
	public $out_path = NULL;

	public function __construct( $out_path )
	{
		$this->out_path = $out_path;
	}

	public function simplify_and_correlate( $src_path, $name_keys, $woe_types )
	{
		$error = (object) array(
			'matched' => 0,
			'unmatched' => 0,
			'unmatched_list' => array(),
			'inserted' => 0,
			'notinserted' => 0,
			'notinserted_list' => array(),
		);

		// attempt to read the source file
		$source = json_decode( file_get_contents( $src_path ) );

		// sanity check that read
		if ( ! is_object( $source ) )
		{
			$error->text = 'can\'t json_decode() or read source file from ' . $src_path;
			return $error;
		}

//$source->features = array_slice( $source->features , 0, 15 );

		// iterate through the source, separate features
		foreach ( $source->features as $feature )
		{

//print_r( $feature->properties );

			if ( ! is_array( $name_keys ) )
			{
				$name_keys = array( $name_keys );
			}

			// iterate through the name keys, adding pieces until the search returns a result that works, hopefully
			$search_name = $this->centroid( $feature )->latlon;
			foreach ( $name_keys as $name_key )
			{
				$search_name .= ' ' . str_replace( '|', ' ', preg_replace( '/[0-9]*/', '', $feature->properties->$name_key ) );
				$locations = bgeo()->admin()->posts()->locationlookup( $search_name );

				echo "\nSearching for $search_name";

				//print_r( $feature->properties );

				// the location lookup can return multiple locations, inspect each
				foreach ( $locations as $location )
				{
					$match = $this->match( $location, $feature, $woe_types );

					if ( $match )
					{
						echo "\nMatched";
						$error->matched++;

						//insert this geo via some other function
						if ( ! $this->insert_or_merge_geo(
							$location,
							$feature,
							$this->simplify( $this->new_geometry( $feature, 'json' ) )
						) )
						{
							$error->notinserted++;
							$error->notinserted_list[] = $search_name;
						}
						else
						{
							$error->inserted++;
						}

						break 2;
					}

					echo "\nNOT Matched";
				}
			}

			if ( ! $match )
			{
				$error->unmatched++;
				$error->unmatched_list[] = $search_name;
			}
			echo "\n\n";
		}

		// explicitly clean up vars to save memory
		unset( $source );

		// return any errors
		return $error;
	}

	public function match( $location, $feature, $woe_types, $recursion = FALSE )
	{

		if (
			! is_object( $location ) ||
			is_wp_error( $location )
		)
		{
			echo "\nLocation NOT valid";
			return FALSE;
		}

		// is the centroid of this looked up location inside the source geography envelope?
		if ( ! $this->contains( $feature, (object) array( 'lon' => $location->point_lon, 'lat' => $location->point_lat ) ) )
		{
			echo "\nLocation NOT coincident";
			return FALSE;
		}
		echo "\nLocation is coincident";

		// is the found location a valid WOE type?
		if ( in_array( (int) $location->api_raw->placeTypeName->code, $woe_types ) )
		{
			echo "\nWOEID type is valid";
			return $location;
		}
		elseif ( ! $recursion )
		{
			echo "\nWOEID type is NOT valid ( {$location->api_raw->placeTypeName->code} ), recursing into belongtos";
			foreach ( $location->belongtos as $belongto )
			{
				if ( 'woeid' != $belongto->api )
				{
					continue;
				}

				return $this->match( bgeo()->new_geo_by_woeid( $belongto->api_id ), $feature, $woe_types, TRUE );
			}
		}

		// this is a failed response
		// successful responses are handled above
		return FALSE;
	}


	public function stats( $src )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );

		return (object) array(
			'type' => $geometry->geometryType(),
			'components' => count( (array) $geometry->getComponents() ),
			'area' => $geometry->area(),
		);
	}

	public function insert_or_merge_geo( $location, $src, $geometry, $recursion = FALSE )
	{
		if ( 'woeid' != $location->api )
		{
			echo "\ninsert_or_merge_geo requires a WOEID, returning without action";
			return FALSE;
		}

		// the data to insert or merge
		$parts_key = md5( serialize( $src ) );
		$data = (object) array(
			'woeid' => $location->api_id,
			'woe_raw' => $location->api_raw,
			'woe_belongtos' => wp_list_pluck( $location->belongtos, 'api_id' ),
			'bgeo_geometry' => $geometry,
			'bgeo_parts' => array( $parts_key => clone $src ),
		);
		unset( $data->bgeo_parts[ $parts_key ]->geometry );

		// check for an existing record for this WOEID
		$existing = $this->get_row( $location->api_id );

		if ( ! $existing )
		{
			// insert if this is the first try at this WOEID
			echo "\ninserting new row";
			$this->insert_row( $data );
		}
		else
		{
			// we've been here before, merge the parts and update
			$existing->bgeo_geometry = $existing->bgeo_geometry->union( $data->bgeo_geometry );
			$existing->woe_belongtos = array_unique( array_filter( array_merge( (array) $existing->woe_belongtos, (array) $data->woe_belongtos ) ) );
			if ( ! is_array( $existing->bgeo_parts ) )
			{
				$existing->bgeo_parts = (array) $existing->bgeo_parts;
			}
			$existing->bgeo_parts[ $parts_key ] = $data->bgeo_parts[ $parts_key ];
	
			echo "\nupdating existing row";
			$this->insert_row( $existing );
		}

		if ( ! $recursion )
		{
			foreach ( $data->woe_belongtos as $woeid )
			{
				echo "\nrecursing belongtos with $woeid";
				$this->insert_or_merge_geo( bgeo()->new_geo_by_woeid( $woeid ), $src, $geometry, TRUE );
			}
		}

		// @TODO how to communicate success or failure back, maybe?
		return TRUE;
	}

	public function get_row( $woeid )
	{
		global $wpdb;

		$row = $wpdb->get_row('
			SELECT
				woeid,
				woe_raw,
				woe_belongtos,
				AsText(bgeo_geometry) AS bgeo_geometry,
				bgeo_parts
			FROM bgeo_data2
			WHERE 1 = 1
				AND woeid = ' . (int) $woeid . '
			LIMIT 1
		');

		if ( empty( $row ) )
		{
			return FALSE;
		}

		// convert the geometry into a proper object
		if ( ! empty( $row->bgeo_geometry ) )
		{
			$row->bgeo_geometry = $this->new_geometry( $row->bgeo_geometry, 'wkt' );
		}

		// unserialize the pieces
		foreach ( array( 'woe_raw', 'woe_belongtos', 'bgeo_parts' ) as $key )
		{
			$row->$key = maybe_unserialize( $row->$key );
		}

		return $row;
	}

	public function insert_row( $data )
	{
		global $wpdb;

		if ( empty( $data->bgeo_geometry ) )
		{
			echo "\n ERROR: Empty geometry!\n\n";
			print_r( $data );
			return FALSE;
			echo "\n\n";
		}

		$sql = $wpdb->prepare(
			'INSERT INTO bgeo_data2
			(
				woeid,
				woe_raw,
				woe_belongtos,
				bgeo_geometry,
				bgeo_parts
			)
			VALUES(
				\'%1$s\',
				\'%2$s\',
				\'%3$s\',
				GeomFromText( "%4$s" ),
				\'%5$s\'
			)
			ON DUPLICATE KEY UPDATE
				woeid = VALUES( woeid ),
				woe_raw = VALUES( woe_raw ),
				woe_belongtos = VALUES( woe_belongtos ),
				bgeo_parts = VALUES( bgeo_parts )
			',
			$data->woeid,
			maybe_serialize( $data->woe_raw ),
			maybe_serialize( $data->woe_belongtos ),
			$data->bgeo_geometry->asText(),
			maybe_serialize( $data->bgeo_parts )
		);

		// execute the query
		$wpdb->query( $sql );

		// ...and export as a file while we have the data
		$this->export( $data, $this->out_path );

		// @TODO how to communicate success or failure back, maybe?
		return TRUE;
	}

	public function export( $geo, $out_path )
	{
		$out_file = $out_path;
		if ( isset( $geo->woe_raw->country->content ) )
		{
			$out_file .= '/Countries/' . $geo->woe_raw->country->content;

			if ( isset( $geo->woe_raw->admin1->content ) )
			{
				$out_file .= '/' . $geo->woe_raw->admin1->content;
			}
		}
		else
		{
			$out_file .= '/' . $geo->woe_raw->placeTypeName->content;
		}
		$out_file .= '/' . $geo->woe_raw->woeid . '-' . $geo->woe_raw->name . '.geojson';
		$out_path = dirname( $out_file );

		// check for and attempt to create the output directory
		if ( ! ( file_exists( $out_path ) && is_dir( $out_path ) ) )
		{
			if ( ! mkdir( $out_path, 0755, TRUE ) )
			{
				$error->text = 'can\'t create output directory';
				return $error;
			}
		}

		// the skeleton object for the geo feature
		$output = (object) array(
			'type' => 'Feature',
			'properties' => (object) array(
				'name' => $geo->woe_raw->name,
				'woeid' => $geo->woe_raw->woeid,
			),
			'geometry' => json_decode( $this->maybe_simplify( $geo->bgeo_geometry )->out( 'json' ) ),
		);

		echo "\nExporting $out_file";
		file_put_contents( $out_file, $this->json_encode( $output ) );
	}

	public function json_encode( $src )
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

	public function maybe_simplify( $geometry )
	{
		// merge multipolygons into a single polygon, if possible
		if ( 'MultiPolygon' == $geometry->geometryType() )
		{
			$geometry = $this->merge_into_one( $geometry );
			$geometry = $this->simplify( $geometry );
		}

		return $geometry;
	}

	public function simplify( $geometry )
	{
		// get the original area for comparison later
		$orig_area = $geometry->envelope()->area();

		echo "\nsimp orig: " . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->envelope()->area() . " area";

		$buffer_factor = 1.09;
		$buffer_buffer_factor = 0.020;
		$simplify_factor = 0.050;
		$iteration = 1;

		do
		{
			echo "\nsimp attempt $iteration with buffer( " . ( $buffer_factor + $buffer_buffer_factor ) . " ) and simplify( $simplify_factor )";

			$simple_geometry = clone $geometry;
			$simple_geometry = $simple_geometry->buffer( $buffer_factor + $buffer_buffer_factor )->simplify( $simplify_factor, FALSE )->buffer( $buffer_factor * -1 );
			$simple_area = $simple_geometry->envelope()->area();

			// $buffer_factor += 0.01;
			$buffer_buffer_factor += 0.01;
			$simplify_factor -= 0.002;
			$iteration += 1;
		}
		while ( $orig_area > $simple_area );

		echo "\nsimp simp: " . $simple_geometry->geometryType() . ': ' . count( (array) $simple_geometry->getComponents() ) . ' components with ' . $simple_geometry->envelope()->area() . " area";

		return $simple_geometry;
	}

	public function merge_into_one( $geometry )
	{
		echo "\nmerge orig: " . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->area() . " area";

		// break the geometry into sub-components
		$parts = $geometry->getComponents();

		// sanity check
		if ( ! is_array( $parts ) )
		{
			return $geometry;
		}

		// merge the parts into a single whole
		$whole = $parts[0];
		unset( $parts[0] );
		foreach ( $parts as $k => $part )
		{
			$whole = $whole->union( $part );
			echo "\nmerge step " . $k . ': ' . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->area() . " area";
		}

		// return the merged result
		return $whole;
	}

	public function centroid( $src )
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

	public function contains( $src, $point )
	{
		// get a geometry from the input json
		$geometry = $this->new_geometry( $src, 'json' );
		$bigenvelope = $geometry->buffer( 1.05 )->envelope();

		// create a point geo from the provided point lat and lon
		$point = $this->new_geometry( 'POINT (' . $point->lon . ' ' . $point->lat . ')', 'wkt' );

		// is the point inside the geo?
		return $bigenvelope->contains( $point );
	}

	public function new_geometry( $input, $adapter )
	{
		if ( ! class_exists( 'geoPHP' ) )
		{
			require_once __DIR__ . '/components/external/geoPHP/geoPHP.inc';
		}

		return geoPHP::load( $input, $adapter );
	} // END new_geometry
}

$sources = array(
/*
*/
	(object) array(
		'src_file' => 'ne_10m_admin_0_countries_lakes.geojson',
		'name_keys' => array( 'admin', 'sovereignt' ),
		'woe_types' => array( 12 ),
		'constrain' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_admin_1_states_provinces_lakes_shp.geojson',
		'name_keys' => array( 'name', 'admin', 'name_alt', 'name_local' ),
		'woe_types' => array( 8 ),
		'constrain' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_parks_and_protected_lands_area.geojson',
		'name_keys' => array( 'unit_name', 'unit_type' ),
		'name_keys' => 'unit_name',
		'woe_types' => array( 13, 16, 20 ),
	),
	(object) array(
		'src_file' => 'ne_10m_geography_marine_polys.geojson',
		'name_keys' => array( 'name' ),
		'woe_types' => array( 15, 37, 38 ),
		'constrain' => FALSE,
	),
	(object) array(
		'src_file' => 'ne_10m_lakes.geojson',
		'name_keys' => array( 'name', 'featurecla', 'name_alt' ),
		'woe_types' => array( 15, 37, 38 ),
	),
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan.geojson',
		'name_keys' => array( 'name_conve' ),
		'woe_types' => array( 7 ),
		'constrain' => TRUE,
	),
/*
	(object) array(
		'src_file' => 'ne_10m_urban_areas_landscan_truncated.geojson',
		'name_keys' => array( 'name_conve' ),
		'woe_types' => array( 7 ),
		'constrain' => TRUE,
	),
*/
);

$bgeo_data = new bGeo_Data_SimplifyCorrelate( __DIR__ . '/correlated-geos' );
foreach ( $sources as $source )
{
	print_r( $bgeo_data->simplify_and_correlate( __DIR__ . '/naturalearthdata/' . $source->src_file, $source->name_keys, $source->woe_types ) );
}

