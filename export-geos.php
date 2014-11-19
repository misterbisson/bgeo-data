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

class bGeo_Data_Export
{
	function export( $geo, $out_path )
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

	public function get_row( $i = 0 )
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
			ORDER BY woeid ASC
			LIMIT ' . (int) $i .', 1
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

	function maybe_simplify( $geometry )
	{
		// merge multipolygons into a single polygon, if possible
		if ( 'MultiPolygon' == $geometry->geometryType() )
		{
			$geometry = $this->merge_into_one( $geometry );
			$geometry = $this->simplify( $geometry );
		}

		return $geometry;
	}

	function simplify( $geometry )
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
	function merge_into_one( $geometry )
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

	function new_geometry( $input, $adapter )
	{
		if ( ! class_exists( 'geoPHP' ) )
		{
			require_once __DIR__ . '/components/external/geoPHP/geoPHP.inc';
		}

		return geoPHP::load( $input, $adapter );
	} // END new_geometry
}

$bgeo_data = new bGeo_Data_Export();

$i = 0;
while ( $row = $bgeo_data->get_row( $i ) )
{
	$bgeo_data->export( $row, __DIR__ . '/correlated-geos' );
	$i++;
}
