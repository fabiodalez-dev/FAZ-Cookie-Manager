<?php
/** Minimal named-lock database for standalone scanner lifecycle tests. */
define( 'DB_NAME', 'faz_test' );
class Faz_Scan_Lock_DB {
	public $options = 'wp_options';
	public $owner = 1;
	public $locks = array();
	public $unavailable = false;
	public function prepare( $sql, $name ) { return array( $sql, $name ); }
	public function get_var( $query ) {
		list( $sql, $name ) = $query;
		if ( false !== strpos( $sql, 'GET_LOCK' ) ) {
			if ( $this->unavailable ) { return null; }
			if ( isset( $this->locks[$name] ) && $this->locks[$name] !== $this->owner ) { return '0'; }
			$this->locks[$name] = $this->owner;
			return '1';
		}
		if ( ( $this->locks[$name] ?? null ) !== $this->owner ) { return '0'; }
		unset( $this->locks[$name] );
		return '1';
	}
}
$GLOBALS['wpdb'] = new Faz_Scan_Lock_DB();
