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

class bGeo_Data_Correlate
{

	public $hierarchy = array(
		'neighborhood' => FALSE,
		'city' => FALSE,
		'county' => FALSE,
		'state' => FALSE,
		'country' => FALSE,
	);

	public function get_row()
	{
		global $wpdb;

		$row = $wpdb->get_row('
			SELECT *, ASTEXT(bgeo_geometry) AS bgeo_geometry
			FROM bgeo_data
			WHERE 1=1
			AND y_response = ""
			LIMIT 1
		');

		return $row;
	}

	public function enrich( $row )
	{


		$bgeo_geometry = $this->new_geometry( $row->bgeo_geometry, 'wkt' );
		$centroid = $bgeo_geometry->centroid();

		$api_response = bgeo()->yboss()->placefinder( preg_replace( '/[0-9]*/', '', $row->ne_name ) . ' ' . $centroid->y() . ' ' . $centroid->x() );
		
		$nameish = array_filter( array_intersect_key( (array) $api_response[0], $this->hierarchy ) );

		$insert = (object) array(
			'bgeo_key' => $row->bgeo_key,
			'y_name' => reset( $nameish ),
			'y_type' => $api_response[0]->woetype,
			'y_woeid' => $api_response[0]->woeid,
			'y_parent_woeid' => 0,
			'y_response' => serialize( $api_response ),
			'wikipedia_uri' => '',		
		);

		$this->insert_meta( $insert );


//print_r( $row );
//print_r( $api_response );
print_r( $insert );
//die;
		return;
	}

	public function insert_meta( $data )
	{
		global $wpdb;

		$sql = $wpdb->prepare(
			'INSERT INTO bgeo_data
			(
				bgeo_key,
				y_name,
				y_type,
				y_woeid,
				y_confidence,
				y_distance,
				y_response,
				wikipedia_uri
			)
			VALUES(
				\'%1$s\',
				\'%2$s\',
				\'%3$s\',
				\'%4$s\',
				\'%5$s\',
				\'%6$s\',
				\'%7$s\'
				\'%8$s\'
			)
			ON DUPLICATE KEY UPDATE
				bgeo_key = VALUES( bgeo_key ),
				y_name = VALUES( y_name ),
				y_type = VALUES( y_type ),
				y_woeid = VALUES( y_woeid ),
				y_parent_woeid = VALUES( y_confidence ),
				y_parent_woeid = VALUES( y_distance ),
				y_response = VALUES( y_response ),
				wikipedia_uri = VALUES( wikipedia_uri )
			',
			$data->bgeo_key,
			$data->y_name,
			$data->y_type,
			$data->y_woeid,
			$data->y_confidence,
			$data->y_distance,
			$data->y_response,
			$data->wikipedia_uri
		);

		// execute the query
		$wpdb->query( $sql );

		return TRUE;
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

$bgeo_data = new bGeo_Data_Correlate();

while ( $row = $bgeo_data->get_row() )
{
	$bgeo_data->enrich( $row );
	usleep( 500 );
}
