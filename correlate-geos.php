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
			AND y_distance = 0
			AND 5 < CHARACTER_LENGTH( y_response )
			LIMIT 1
		');

		return $row;
	}

	public function enrich( $row )
	{
		$bgeo_geometry = $this->new_geometry( $row->bgeo_geometry, 'wkt' );
		$centroid = $bgeo_geometry->centroid();

		// don't re-check the Y! api if we already have data
		if (
			( ! $y_response = unserialize( $row->y_response ) ) ||
			! is_array( $y_response ) ||
			! count( $y_response )
		)
		{
			$y_response = bgeo()->yboss()->placefinder( preg_replace( '/[0-9]*/', '', $row->ne_name ) . ' ' . $centroid->y() . ' ' . $centroid->x() );
		}

		// is the Y! response inside the geometry from NE?
		if ( isset( $y_response[0]->latitude, $y_response[0]->longitude ) )
		{
			$y_centroid = $this->new_geometry( 'POINT (' . $y_response[0]->longitude . ' ' . $y_response[0]->latitude . ')', 'wkt' );
		}

		// Y!'s name for this place?
		$y_nameish = array_filter( array_intersect_key( (array) $y_response[0], $this->hierarchy ) );

		// don't re-check the W api if we already have data
		if (
			( ! $w_response = unserialize( $row->w_response ) ) ||
			! is_array( $w_response ) ||
			! count( $w_response )
		)
		{
			$w_response = scriblio_authority_bgeo()->wikipedia()->search( preg_replace( '/[0-9]*/', '', $row->ne_name ) . ' ' . $row->ne_admin );
		}


		$insert = (object) array(
			'bgeo_key' => $row->bgeo_key,
			'y_name' => reset( $y_nameish ),
			'y_type' => $y_response[0]->woetype,
			'y_woeid' => $y_response[0]->woeid,
			'y_confidence' => $y_response[0]->quality,
			'y_distance' => (int) $bgeo_geometry->contains( $y_centroid ) + 1,
			'y_response' => serialize( $y_response ),
			'y_detail' => '',
			'w_name' => '',
			'w_uri' => '',
			'w_distance' => '',
			'w_response' => serialize( $w_response ),
			'w_detail' => '',
		);

		$this->insert_meta( $insert );


//print_r( $row );
//print_r( $y_response );
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
				y_detail,
				w_name,
				w_uri,
				w_distance,
				w_response,
				w_detail
			)
			VALUES(
				\'%1$s\',
				\'%2$s\',
				\'%3$s\',
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
				y_name = VALUES( y_name ),
				y_type = VALUES( y_type ),
				y_woeid = VALUES( y_woeid ),
				y_confidence = VALUES( y_confidence ),
				y_distance = VALUES( y_distance ),
				y_response = VALUES( y_response ),
				y_detail = VALUES( y_detail ),
				w_name = VALUES( w_name ),
				w_uri = VALUES( w_uri ),
				w_distance = VALUES( w_distance ),
				w_response = VALUES( w_response ),
				w_detail = VALUES( w_detail )
			',
			$data->bgeo_key,
			$data->y_name,
			$data->y_type,
			$data->y_woeid,
			$data->y_confidence,
			$data->y_distance,
			$data->y_response,
			$data->y_detail,
			$data->w_name,
			$data->w_uri,
			$data->w_distance,
			$data->w_response,
			$data->w_detail
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
