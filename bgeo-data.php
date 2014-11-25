<?php

/*
wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=0 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=1100 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=2200 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=3300 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=4400 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_urban_areas_landscan.geojson --namekeys=name_conve --woetypes=7 --offset=5500 --limit=1100

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_parks_and_protected_lands_area.geojson --namekeys=unit_name,unit_type --woetypes=13,16,20

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_geography_marine_polys.geojson --namekeys=name --woetypes=15,37,38

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_lakes.geojson --namekeys=name,featurecla,name_alt --woetypes=15,37,38

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_admin_1_states_provinces_lakes_shp.geojson --namekeys=name,admin,name_alt,name_local --woetypes=8 --offset=0 --limit=1400

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_admin_1_states_provinces_lakes_shp.geojson --namekeys=name,admin,name_alt,name_local --woetypes=8 --offset=1400 --limit=1400

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_admin_1_states_provinces_lakes_shp.geojson --namekeys=name,admin,name_alt,name_local --woetypes=8 --offset=2800 --limit=1400

wp --url=bgeo.me --require=./bgeo-data.php bgeodata simplify_and_correlate naturalearthdata/ne_10m_admin_0_countries_lakes.geojson --namekeys=admin,sovereignt --woetypes=12
*/

class bGeo_Data extends WP_CLI_Command
{

	const VERBOSE = 0;

	public function count_features( $args, $assoc_args )
	{
		if ( empty( $args ) )
		{
			WP_CLI::error( 'No input file was specified.' );
			return;
		}

		// attempt to read the source file$
		$source = json_decode( file_get_contents( $args[0] ) );

		WP_CLI::success( count( $source->features ) . ' features in ' . $args[0] );
	}

	public function name_features( $args, $assoc_args )
	{
		if ( empty( $args ) )
		{
			WP_CLI::error( 'No input file was specified.' );
			return;
		}

		if ( ! is_array( $assoc_args ) )
		{
			$assoc_args = array();
		}

		$assoc_args['source'] = $args[0];
		$args = (object) array_intersect_key( $assoc_args, array(
			'source' => TRUE,
			'namekeys' => TRUE,
		) );

		if ( ! isset( $args->source, $args->namekeys  ) )
		{
			WP_CLI::error( 'Input file and --namekeys values are required.' );
			return;
		}

		$args->namekeys = array_map( 'trim', explode( ',', $args->namekeys ) );

		if ( empty( $args->namekeys ) )
		{
			WP_CLI::error( '--namekeys arg has no parsed values' );
			return;
		}

		// attempt to read the source file$
		$source = json_decode( file_get_contents( $args->source ) );

		// iterate through the source, separate features
		foreach ( $source->features as $k => $feature )
		{

			$feature_name = implode( ', ' , array_intersect_key( (array) $feature->properties, array_flip( $args->namekeys ) ) );
			$geometry = bgeo()->new_geometry( $feature, 'json', TRUE );
			$feature_name .= ' ' . self::centroid( $geometry )->latlon;
			WP_CLI::line( $k . ': ' . $feature_name );
		}

		WP_CLI::success( count( $source->features ) . ' features in ' . $args->source );
	}

	public function simplify_and_correlate( $args, $assoc_args )
	{
		if ( empty( $args ) )
		{
			WP_CLI::error( 'No input file was specified.' );
			return;
		}

		if ( ! is_array( $assoc_args ) )
		{
			$assoc_args = array();
		}

		$assoc_args['source'] = $args[0];
		$args = (object) array_intersect_key( $assoc_args, array(
			'source' => TRUE,
			'namekeys' => TRUE,
			'woetypes' => TRUE,
			'offset' => TRUE,
			'limit' => TRUE,
		) );

		if ( ! isset( $args->source, $args->namekeys, $args->woetypes ) )
		{
			WP_CLI::error( 'Input file, --namekeys, and --woetypes values are required.' );
			return;
		}

		$args->namekeys = array_map( 'trim', explode( ',', $args->namekeys ) );
		$args->woetypes = wp_parse_id_list( $args->woetypes );

		if ( empty( $args->namekeys ) )
		{
			WP_CLI::error( '--namekeys arg has no parsed values' );
			return;
		}

		if ( empty( $args->woetypes ) )
		{
			WP_CLI::error( '--woetypes arg has no parsed values' );
			return;
		}

		$error = (object) array(
			'matched' => 0,
			'unmatched' => 0,
			'unmatched_list' => array(),
			'inserted' => 0,
			'notinserted' => 0,
			'notinserted_list' => array(),
		);

		// attempt to read the source file
		$source = json_decode( file_get_contents( $args->source ) );

		// sanity check that read
		if ( ! is_object( $source ) )
		{
			$error->text = 'can\'t json_decode() or read source file from ' . $args->source;
			return $error;
		}

		if ( isset( $args->offset ) || isset( $args->limit ) )
		{
			// both are set
			if ( isset( $args->offset, $args->limit ) )
			{
				$source->features = array_slice( $source->features, $args->offset, $args->limit );
			}
			// just the offset is set
			elseif ( isset( $args->offset ) )
			{
				$source->features = array_slice( $source->features, $args->offset );
				$args->limit = 0;
			}
			// just the limit is set
			else
			{
				$source->features = array_slice( $source->features, 0, $args->limit );
				$args->offset = 0;
			}
		}

		// iterate through the source, separate features
		foreach ( $source->features as $k => $feature )
		{

//print_r( $feature->properties );

			// iterate through the name keys, adding pieces until the search returns a result that works, hopefully
			$geometry = bgeo()->new_geometry( $feature, 'json', TRUE );
			if ( ! is_object( $geometry ) )
			{
				self::log( array(
					'source' => basename( $args->source ),
					'status' => 'Can\'t load geometry from feature.',
					'item' => "$search_name  (feature #" . ( $k + $args->offset ) . ")",
				) );
				$error->unmatched++;
				$error->unmatched_list[] = ( $k + $args->offset ) . ' in ' . $args->source;
				WP_CLI::warning( "Failed to load geometry from feature " . ( $k + $args->offset ) . " in $args->source" );
				continue;
			}
			$geometry = self::simplify( $geometry );

			$search_name = self::centroid( $geometry )->latlon;
			foreach ( $args->namekeys as $name_key )
			{
				// skip this iteration if the name key is unset or empty
				if (
					! isset( $feature->properties->$name_key ) ||
					empty( $feature->properties->$name_key )
				)
				{
					continue;
				}

				// get the search name from the component keys named on the command line
				$search_name .= ' ' . trim( str_replace( '|', ' ', preg_replace( '/[0-9]*/', '', $feature->properties->$name_key ) ) );
				WP_CLI::line( "Searching for $search_name" );

				$locations = bgeo()->admin()->posts()->locationlookup( $search_name );
				if ( ! is_array( $locations ) )
				{
					self::log( array(
						'source' => basename( $args->source ),
						'status' => 'API returned no results for search.',
						'item' => "$search_name  (feature #" . ( $k + $args->offset ) . ")",
					) );
					$error->unmatched++;
					$error->unmatched_list[] = ( $k + $args->offset ) . ' in ' . $args->source;
					WP_CLI::warning( "No locations found for $search_name, feature " . ( $k + $args->offset ) . " in $args->source" );

					continue;
				}

				//print_r( $feature->properties );

				// the location lookup can return multiple locations, inspect each
				foreach ( $locations as $location )
				{
					$match = self::match( $location, $geometry, $args->woetypes );

					if ( $match )
					{
						if ( self::VERBOSE )
						{
							WP_CLI::line( "Matched" );
						}
						else
						{
							echo '.';
						}

						self::log( array(
							'source' => basename( $args->source ),
							'status' => 'Success! Matched feature to API result.',
							'item' => "$search_name  (feature #" . ( $k + $args->offset ) . ", WOEID: $location->api_id)",
						) );

						$error->matched++;

						//insert this geo
						if ( ! self::insert_or_merge_geo(
							$location,
							$geometry
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

					WP_CLI::warning( "NOT Matched" );
				}
			}

			if ( ! $match )
			{
				self::log( array(
					'source' => basename( $args->source ),
					'status' => 'No API results matched feature.',
					'item' => "$search_name  (feature #" . ( $k + $args->offset ) . ")",
				) );
				$error->unmatched++;
				$error->unmatched_list[] = $search_name;
			}
			WP_CLI::line( "\n\n" );
		}

		// return any errors
		WP_CLI::success( 'Done! ' . print_r( $error, TRUE ) );
	}

	private function match( $location, $geometry, $woe_types, $recursion = FALSE )
	{
		if (
			! is_object( $location ) ||
			is_wp_error( $location )
		)
		{
			WP_CLI::warning( "Location NOT valid" );
			return FALSE;
		}

		// is the centroid of this looked up location inside the source geography envelope?
		if ( ! self::contains( $geometry, (object) array( 'lon' => $location->point_lon, 'lat' => $location->point_lat ) ) )
		{
			WP_CLI::warning( "Location NOT coincident" );
			return FALSE;
		}

		// is the found location a valid WOE type?
		if ( in_array( (int) $location->api_raw->placeTypeName->code, $woe_types ) )
		{
			if ( self::VERBOSE )
			{
				WP_CLI::line( "WOEID type is valid" );
			}
			return $location;
		}
		elseif ( ! $recursion )
		{
			WP_CLI::warning( "WOEID type is NOT valid ( {$location->api_raw->placeTypeName->code} ), recursing into belongtos" );
			foreach ( $location->belongtos as $belongto )
			{
				if ( 'woeid' != $belongto->api )
				{
					continue;
				}

				return self::match( bgeo()->new_geo_by_woeid( $belongto->api_id ), $geometry, $woe_types, TRUE );
			}
		}

		// this is a failed response
		// successful responses are handled above
		return FALSE;
	}

	private function sanitize_belongtos( $woeids )
	{
		if ( ! is_array( $woeids ) )
		{
			return array();
		}

		rsort( $woeids );
		return array_filter( array_unique( (array) $woeids ) );
	}

	private function insert_or_merge_geo( $location, $geometry, $recursion = FALSE )
	{
		if ( 'woeid' != $location->api )
		{
			WP_CLI::warning( "insert_or_merge_geo requires a WOEID, returning without action" );
			return FALSE;
		}

		self::get_lock( $location->api_id );

		// the data to insert or merge
		$data = (object) array(
			'woeid' => $location->api_id,
			'woe_raw' => $location->api_raw,
			'woe_belongtos' => self::sanitize_belongtos( wp_list_pluck( $location->belongtos, 'api_id' ) ),
			'bgeo_geometry' => $geometry,
		);

		// check for an existing record for this WOEID
		$existing = self::get_row( $location->api_id );

		if ( ! $existing )
		{
			// insert if this is the first try at this WOEID
			WP_CLI::line( "Inserting new row for WOEID $location->api_id" );
			self::insert_row( $data );
		}
		else
		{
			WP_CLI::line( "Updating existing row for WOEID $location->api_id" );

			// we've been here before, merge the parts and update
			// attempt to union the geometry
			try
			{
				// @TODO: this has started fatalling with exceptions.
				// Using the try-catch-reduce workaround for now, but why did the errors just appear?
				$unioned_geometry = $existing->bgeo_geometry->union( $data->bgeo_geometry );
				$existing->bgeo_geometry = $unioned_geometry;
			}
			catch ( Exception $e )
			{
				WP_CLI::warning( "Caught exception while trying to union() geometries near " . __FILE__ . ':' . __LINE__ . '.' );
				WP_CLI::warning( 'Attempted to union ' . $data->bgeo_geometry->geometryType() . ' into ' . $existing->bgeo_geometry->geometryType() . '.' );
				$existing->bgeo_geometry = self::reduce( array( $existing->bgeo_geometry, $data->bgeo_geometry ) );
				WP_CLI::warning( 'Instead reduced to ' . $existing->bgeo_geometry->geometryType() . ' with ' . count( (array) $existing->bgeo_geometry->getComponents() ) . ' components.' );

				// attempt to merge the hack-unioned geometry
				try
				{
					$unioned_geometry = self::merge_into_one( $existing->bgeo_geometry );
					$existing->bgeo_geometry = $unioned_geometry;

					WP_CLI::warning( 'Finally merged to ' . $existing->bgeo_geometry->geometryType() . ' with ' . count( (array) $existing->bgeo_geometry->getComponents() ) . ' components.' );

				}
				catch ( Exception $e )
				{
					WP_CLI::warning( "Caught exception while trying to merge_into_one() near " . __FILE__ . ':' . __LINE__ . '.' );
				}
			}

			$existing->woe_belongtos = self::sanitize_belongtos( array_merge( (array) $existing->woe_belongtos, (array) $data->woe_belongtos ) );

			self::insert_row( $existing );
		}

		self::release_lock( $location->api_id );

		if ( ! $recursion )
		{
			foreach ( $data->woe_belongtos as $woeid )
			{
				WP_CLI::line( "recursing belongtos with $woeid" );
				self::insert_or_merge_geo( bgeo()->new_geo_by_woeid( $woeid ), $geometry, TRUE );
			}
		}

		// @TODO how to communicate success or failure back, maybe?
		return TRUE;
	}

	private function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'bgeo_data';
	}

	private function maybe_create_table()
	{
		static $did_create_table = FALSE;

		if ( ! $did_create_table )
		{
			self::create_table();
			$did_create_table = TRUE;
		}
	}//end maybe_create_table

	private function create_table()
	{
		global $wpdb;

		if ( ! empty( $wpdb->charset ) )
		{
			$charset_collate = 'DEFAULT CHARACTER SET '. $wpdb->charset;
		}
		if ( ! empty( $wpdb->collate ) )
		{
			$charset_collate .= ' COLLATE '. $wpdb->collate;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		WP_CLI::warning( 'Don\'t be surprised by error messages about duplicate primary keys. Other errors might be an issue, though...' );
		return dbDelta( "
			CREATE TABLE " . self::get_table_name() . " (
				`woeid` int(16) unsigned NOT NULL,
				`woe_raw` text NOT NULL,
				`woe_belongtos` text NOT NULL,
				`bgeo_geometry` geometrycollection NOT NULL,
				PRIMARY KEY (`woeid`)
			) ENGINE=MyISAM $charset_collate;
		" );
	}//end create_table

	private function get_row( $woeid )
	{
		self::maybe_create_table();

		global $wpdb;
		$row = $wpdb->get_row('
			SELECT
				woeid,
				woe_raw,
				woe_belongtos,
				AsText(bgeo_geometry) AS bgeo_geometry
			FROM ' . self::get_table_name() . '
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
			$row->bgeo_geometry = bgeo()->new_geometry( $row->bgeo_geometry, 'wkt', TRUE );
		}

		// unserialize the pieces
		foreach ( array( 'woe_raw', 'woe_belongtos' ) as $key )
		{
			$row->$key = maybe_unserialize( $row->$key );
		}
		$row->woe_belongtos = self::sanitize_belongtos( $row->woe_belongtos );

		return $row;
	}

	private function insert_row( $data )
	{
		self::maybe_create_table();

		if ( empty( $data->bgeo_geometry ) )
		{
			WP_CLI::warning( "Empty geometry! " . print_r( $data ) );
			return FALSE;
		}

		global $wpdb;
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::get_table_name() . '
			(
				woeid,
				woe_raw,
				woe_belongtos,
				bgeo_geometry
			)
			VALUES(
				\'%1$s\',
				\'%2$s\',
				\'%3$s\',
				GeomFromText( "%4$s" )
			)
			ON DUPLICATE KEY UPDATE
				woeid = VALUES( woeid ),
				woe_raw = VALUES( woe_raw ),
				woe_belongtos = VALUES( woe_belongtos ),
				bgeo_geometry = VALUES( bgeo_geometry )
			',
			$data->woeid,
			maybe_serialize( $data->woe_raw ),
			maybe_serialize( $data->woe_belongtos ),
			$data->bgeo_geometry->asText()
		);

		// execute the query
		$wpdb->query( $sql );

		// ...and export as a file while we have the data
		self::export( $data );

		// @TODO how to communicate success or failure back, maybe?
		return TRUE;
	}

	private function get_lock( $woeid )
	{
		$i = 0;
		while (
			( $lock = wp_cache_get( $woeid, 'bgeo-data-lock', TRUE ) ) &&
			(bool) $lock
		)
		{
			if ( 1000 < $i )
			{
				WP_CLI::warning( "\nGiving up waiting for lock on $woeid. Previous lock set " . ( time() - $lock ) . " seconds ago." );
				break;
			}

			if ( ! $i )
			{
				WP_CLI::warning( "Waiting for lock on $woeid. Previous lock set " . ( time() - $lock ) . " seconds ago." );
			}
			else
			{
				echo '.';
			}

			sleep( 2 );
			$i++;
		}

		// if we've been putting dots on the screen, clear the line
		if ( $i )
		{
			WP_CLI::line( '' );
		}

		wp_cache_set( $woeid, time(), 'bgeo-data-lock', 3600 );
		return TRUE;
	}

	private function release_lock( $woeid )
	{
		wp_cache_delete( $woeid, 'bgeo-data-lock' );

		return TRUE;
	}

	private function export( $geo )
	{
		$out_file = $out_path = __DIR__ . '/correlated-geos';
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
			'geometry' => json_decode( self::maybe_simplify( $geo->bgeo_geometry )->out( 'json' ) ),
		);

		WP_CLI::line( "Exporting $out_file" );
		file_put_contents( $out_file, self::json_encode( $output ) );
	}

	private function log( $data )
	{
		static $log_handle = FALSE;

		// get the file handle on the first run
		if ( ! $log_handle )
		{
			$log_handle = self::get_log_handle();

			// insert the array keys as column headers in the CSV, just on the first pass
			fputcsv( $log_handle, array_keys( $data ) );
		}

		// it's up to the caller to make sure the number and order of array keys is consistent
		fputcsv( $log_handle, $data );
	}

	private function get_log_handle()
	{
		// check for and attempt to create the log directory
		$log_path = __DIR__ . '/logs';
		if ( ! ( file_exists( $log_path ) && is_dir( $log_path ) ) )
		{
			if ( ! mkdir( $log_path, 0755, TRUE ) )
			{
				WP_CLI::error( "Can't create log file directory" );
				return FALSE;
			}
		}

		// start the log csv file
		$log_file = $log_path . '/' . date( DATE_ATOM ) . '.csv';
		$log_handle = fopen( $log_file, 'w' );
		if ( ! $log_handle )
		{
			WP_CLI::error( "Can't create log file directory" );
			return FALSE;
		}

		return $log_handle;
	}

	private function json_encode( $src )
	{
		return str_ireplace(
			array(
				'"features":', // separates the preamble from the content
				']],[[',       // separates features from eachother
				'},{',         // separates features from eachother
				',"geometry"', // separates the geometry from the properties
			),
			array(
				"\"features\":\n",
				"]]\n,\n\t[[",
				"}\n,\n\t{",
				",\n\"geometry\"",
			),
			json_encode( $src )
		);
	}

	private function maybe_simplify( $geometry )
	{
		// merge multipolygons into a single polygon, if possible
		if ( 'MultiPolygon' == $geometry->geometryType() )
		{
			// try to reduce it further by unioning the pieces
			try
			{
				// @TODO: this has started fatalling with exceptions.
				// Using the try-catch-reduce workaround for now, but why did the errors just appear?
				$unioned_geometry = self::merge_into_one( $geometry );
				$geometry = $unioned_geometry;
			}
			catch ( Exception $e )
			{
				// what else can I do?
				WP_CLI::warning( "Caught exception while trying to merge_into_one() near " . __FILE__ . ':' . __LINE__ . '.' );
				WP_CLI::warning( 'Geometry is ' . $geometry->geometryType() . ' with ' . count( (array) $geometry->getComponents() ) . ' components.' );
			}

			// simplify the individual components of the resulting geometry
			$geometry = self::simplify( $geometry );
		}

		return $geometry;
	}

	private function simplify( $geometry )
	{
		// get the original area for comparison later
		$orig_area = $geometry->envelope()->area();

		if ( 1 < self::VERBOSE )
		{
			WP_CLI::line( "simp orig: " . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->envelope()->area() . " area" );
		}
		else
		{
			echo '.';
		}

		$buffer_factor = 1.09;
		$buffer_buffer_factor = 0.020;
		$simplify_factor = 0.050;
		$iteration = 1;

		do
		{
			if ( 1 < self::VERBOSE )
			{
				WP_CLI::line( "simp attempt $iteration with buffer( " . ( $buffer_factor + $buffer_buffer_factor ) . " ) and simplify( $simplify_factor )" );
			}
			else
			{
				echo '.';
			}

			$simple_geometry = clone $geometry;
			$simple_geometry = $simple_geometry->buffer( $buffer_factor + $buffer_buffer_factor )->simplify( $simplify_factor, FALSE )->buffer( $buffer_factor * -1 );
			$simple_area = $simple_geometry->envelope()->area();

			// $buffer_factor += 0.01;
			$buffer_buffer_factor += 0.01;
			$simplify_factor -= 0.002;
			$iteration += 1;
		}
		while ( $orig_area > $simple_area );

		if ( 1 < self::VERBOSE )
		{
			WP_CLI::line( "simp simp: " . $simple_geometry->geometryType() . ': ' . count( (array) $simple_geometry->getComponents() ) . ' components with ' . $simple_geometry->envelope()->area() . " area" );
		}
		else
		{
			WP_CLI::line( '' );
		}

		return $simple_geometry;
	}

	private function merge_into_one( $geometry, $recursion = FALSE )
	{
		if ( 1 < self::VERBOSE )
		{
			WP_CLI::line( "merge orig: " . $geometry->geometryType() . ': ' . count( (array) $geometry->getComponents() ) . ' components with ' . $geometry->area() . " area" );
		}
		else
		{
			echo '.';
		}

		// break the geometry into sub-components
		$parts = array_reverse( $geometry->getComponents() );

		// sanity check
		if ( ! is_array( $parts ) )
		{
			return $geometry;
		}

		// merge the parts into a single whole
		$whole = $parts[0];
		$extras = FALSE;
		unset( $parts[0] );
		foreach ( $parts as $k => $part )
		{
			// attempt to union the geometry
			try
			{
				// @TODO: this has started fatalling with exceptions.
				// Using the try-catch-reduce workaround for now, but why did the errors just appear?
				$unioned_geometry = $whole->union( $part );
				$whole = $unioned_geometry;
				if ( 1 < self::VERBOSE )
				{
					WP_CLI::line( 'merge step ' . $k . ': ' . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->area() . ' area' );
				}
				else
				{
					echo '.';
				}
			}
			catch ( Exception $e )
			{
				if ( ! self::VERBOSE )
				{
					WP_CLI::line( '' );
				}
				WP_CLI::warning( 'Caught exception while trying to union() geometries near ' . __FILE__ . ':' . __LINE__ . ",\nstep " . $k . ': ' . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->area() . ' area' );
				WP_CLI::warning( 'Attempted to union ' . $part->geometryType() . ' into ' . $whole->geometryType() . '.' );

				if ( ! $extras )
				{
					$extras = $part;
				}
				else
				{
					$extras = self::reduce( array( $extras, $part ) );
				}

				// now take a big leap of faith and try merging thest extras
				if ( ! $recursion )
				{
					$extras = self::merge_into_one( $extras, TRUE );
				}

				if ( ! self::VERBOSE )
				{
					WP_CLI::line( '' );
				}
				WP_CLI::warning( 'Extra items on step ' . $k . ': ' . $extras->geometryType() . ': ' . count( (array) $extras->getComponents() ) . ' components with ' . $extras->area() . ' area' );
			}
		}

		if (
			is_object( $extras ) &&
			is_callable( array( $extras, 'getComponents' ) ) &&
			count( (array) $extras->getComponents() )
		)
		{
			$whole = self::reduce( array( $whole, $extras ) );
		}

		if ( 1 < self::VERBOSE )
		{
			WP_CLI::line( "merge merged: " . $whole->geometryType() . ': ' . count( (array) $whole->getComponents() ) . ' components with ' . $whole->envelope()->area() . " area" );
		}
		else
		{
			echo ".\n";
		}

		// return the merged result
		return $whole;
	}

	private function centroid( $geometry )
	{
		$centroid = $geometry->centroid();

		return (object) array(
			'latlon' => $centroid->y() . ' ' . $centroid->x(),
			'y' => $centroid->y(),
			'lat' => $centroid->y(),
			'x' => $centroid->x(),
			'lon' => $centroid->x(),
		);
	}

	private function contains( $geometry, $point )
	{
		$bigenvelope = $geometry->buffer( 1.05 )->envelope();

		// create a point geo from the provided point lat and lon
		$point = bgeo()->new_geometry( 'POINT (' . $point->lon . ' ' . $point->lat . ')', 'wkt', TRUE );

		// is the point inside the geo?
		return $bigenvelope->contains( $point );
	}

	public function reduce( $components )
	{
		$geometry = new MultiPolygon( $components );
		return geoPHP::geometryReduce( $geometry );
	}//end new_geometry

}//END class

WP_CLI::add_command( 'bgeodata', 'bGeo_Data' );