<?php
/**
 * GeoLite2 archive extraction and MMDB reads are bounded (2026-11 audit).
 *
 *  - Geolocation::extract_mmdb() streams the archive with gzopen() and a tar
 *    walker instead of PharData: it writes only the .mmdb entry (no
 *    decompressed .tar next to the download), handles ustar prefixes and GNU
 *    long names, and refuses an archive that decompresses past the byte
 *    budget or an entry over the size cap.
 *  - Mmdb_Reader reads through a file handle: opening a database no longer
 *    loads the whole file, and lookups give the same answers.
 *
 * The MMDB fixture is generated here (one tree node, two records).
 *
 * Run: php tests/unit/test-mmdb-stream-extract-php.php
 *
 * @package FazCookie\Tests\Unit
 */

define( 'ABSPATH', __DIR__ . '/' );

$faz_tmp = sys_get_temp_dir() . '/faz-mmdb-stream-' . getmypid();
@mkdir( $faz_tmp . '/uploads', 0777, true );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_upload_dir() { global $faz_tmp; return array( 'basedir' => $faz_tmp . '/uploads' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 8 ) ); }
function delete_option( $k ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
function esc_html( $s ) { return (string) $s; }
function __( $t, $d = 'default' ) { return $t; }

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-mmdb-reader.php';
require_once $root . '/includes/class-geolocation.php';
use FazCookie\Includes\Geolocation;
use FazCookie\Includes\Mmdb_Reader;

$passed = 0;
$failed = 0;
function ms_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) { ++$passed; echo "  PASS: $label\n"; }
	else { ++$failed; echo "  FAIL: $label\n"; }
}

// ---------- MMDB fixture writer ----------
function mm_str( $s ) {
	$n = strlen( $s );
	return ( $n < 29 ? chr( 0x40 | $n ) : chr( 0x40 | 29 ) . chr( $n - 29 ) ) . $s;
}
function mm_uint( $v, $type ) { // 5 = uint16, 6 = uint32.
	$b = ltrim( pack( 'N', $v ), "\0" );
	return chr( ( $type << 5 ) | strlen( $b ) ) . $b;
}
function mm_map( array $pairs ) {
	$out = chr( 0xE0 | count( $pairs ) );
	foreach ( $pairs as $k => $v ) {
		$out .= mm_str( $k ) . $v;
	}
	return $out;
}
function mm_array( array $items ) {
	$out = chr( count( $items ) ) . chr( 11 - 7 );
	foreach ( $items as $v ) { $out .= $v; }
	return $out;
}
/**
 * IPv4 DB, record size 24, one node: first bit 0 → AU, first bit 1 → US/CA.
 * $padding bytes of zeros sit, unreferenced, between data and metadata.
 */
function mm_build( $type, $padding = 0 ) {
	$rec_a = mm_map( array( 'country' => mm_map( array( 'iso_code' => mm_str( 'AU' ) ) ) ) );
	$rec_b = mm_map(
		array(
			'country'      => mm_map( array( 'iso_code' => mm_str( 'US' ) ) ),
			'subdivisions' => mm_array( array( mm_map( array( 'iso_code' => mm_str( 'CA' ) ) ) ) ),
		)
	);
	$node_count = 1;
	$ptr_a      = $node_count + 16 + 0;
	$ptr_b      = $node_count + 16 + strlen( $rec_a );
	$tree       = substr( pack( 'N', $ptr_a ), 1 ) . substr( pack( 'N', $ptr_b ), 1 );
	$meta       = mm_map(
		array(
			'node_count'    => mm_uint( $node_count, 6 ),
			'record_size'   => mm_uint( 24, 5 ),
			'ip_version'    => mm_uint( 4, 5 ),
			'database_type' => mm_str( $type ),
		)
	);
	return $tree . str_repeat( "\0", 16 ) . $rec_a . $rec_b . str_repeat( "\0", $padding ) . "\xab\xcd\xefMaxMind.com" . $meta;
}

// ---------- tar writer ----------
function tar_header( $name, $size, $type = '0', $prefix = '' ) {
	$h  = str_pad( $name, 100, "\0" );
	$h .= str_pad( '0000644', 7, '0', STR_PAD_LEFT ) . "\0";
	$h .= "0000000\0" . "0000000\0";
	$h .= str_pad( decoct( $size ), 11, '0', STR_PAD_LEFT ) . "\0";
	$h .= str_pad( decoct( 1700000000 ), 11, '0', STR_PAD_LEFT ) . "\0";
	$h .= '        ' . $type . str_repeat( "\0", 100 );
	$h .= "ustar\0" . '00' . str_repeat( "\0", 32 ) . str_repeat( "\0", 32 ) . str_repeat( "\0", 16 );
	$h .= str_pad( $prefix, 155, "\0" );
	$h  = str_pad( $h, 512, "\0" );
	$sum = 0;
	for ( $i = 0; $i < 512; $i++ ) { $sum += ord( $h[ $i ] ); }
	return substr_replace( $h, str_pad( decoct( $sum ), 6, '0', STR_PAD_LEFT ) . "\0 ", 148, 8 );
}
function tar_entry( $name, $data, $type = '0', $prefix = '' ) {
	$pad = ( 512 - strlen( $data ) % 512 ) % 512;
	return tar_header( $name, strlen( $data ), $type, $prefix ) . $data . str_repeat( "\0", $pad );
}
function tar_gz( $path, $tar ) {
	file_put_contents( $path, gzencode( $tar . str_repeat( "\0", 1024 ) ) );
	return $path;
}

$extract = new ReflectionMethod( Geolocation::class, 'extract_mmdb' );
$extract->setAccessible( true );
$walk = null;
if ( method_exists( Geolocation::class, 'read_tar_entry' ) ) {
	$walk = new ReflectionMethod( Geolocation::class, 'read_tar_entry' );
	$walk->setAccessible( true );
}
function ms_walk( $walk, array $args ) {
	if ( null === $walk ) {
		throw new LogicException( 'no read_tar_entry()' );
	}
	return $walk->invokeArgs( null, $args );
}
$data_dir = $faz_tmp . '/uploads/faz-cookie-manager/';

echo "\n== extract_mmdb(): streamed tar walk ==\n";

$city = mm_build( 'GeoLite2-City' );
$arc  = tar_gz(
	$faz_tmp . '/city.tar.gz',
	tar_entry( 'GeoLite2-City_20261110/', '', '5' )
	. tar_entry( 'GeoLite2-City_20261110/COPYRIGHT.txt', str_repeat( 'c', 700 ) )
	. tar_entry( 'GeoLite2-City_20261110/GeoLite2-City.mmdb', $city )
);
$res = $extract->invoke( null, $arc, 'GeoLite2-City' );
ms_check( true === $res, 'a MaxMind-shaped archive installs' );
ms_check( file_exists( $data_dir . 'GeoLite2-City.mmdb' ) && $city === file_get_contents( $data_dir . 'GeoLite2-City.mmdb' ), 'the installed .mmdb is byte-identical to the entry' );
ms_check( ! file_exists( $faz_tmp . '/city.tar' ), 'no decompressed .tar is written next to the download' );
ms_check( array() === glob( $data_dir . '.*.tmp' ), 'no staging file is left behind' );

$country = mm_build( 'GeoLite2-Country' );
$long    = str_repeat( 'd', 120 ) . '/GeoLite2-Country.mmdb';
$arc     = tar_gz(
	$faz_tmp . '/long.tar.gz',
	tar_entry( '././@LongLink', $long . "\0", 'L' ) . tar_entry( substr( $long, 0, 100 ), $country )
);
ms_check( true === $extract->invoke( null, $arc, 'GeoLite2-Country' ), 'a GNU long-name (L) entry is found by its full name' );

$arc = tar_gz( $faz_tmp . '/prefix.tar.gz', tar_entry( 'GeoLite2-Country.mmdb', $country, '0', 'GeoLite2-Country_20261110' ) );
ms_check( true === $extract->invoke( null, $arc, 'GeoLite2-Country' ), 'a ustar entry with a prefix is found' );

$arc = tar_gz( $faz_tmp . '/none.tar.gz', tar_entry( 'README.txt', 'no database here' ) );
$res = $extract->invoke( null, $arc, 'GeoLite2-Country' );
ms_check( is_wp_error( $res ) && 'faz_geo_no_mmdb' === $res->get_error_code(), 'an archive without a .mmdb reports faz_geo_no_mmdb' );

$arc = tar_gz( $faz_tmp . '/wrong.tar.gz', tar_entry( 'GeoLite2-City.mmdb', $city ) );
$res = $extract->invoke( null, $arc, 'GeoLite2-Country' );
ms_check( is_wp_error( $res ) && 'faz_geo_extract_failed' === $res->get_error_code(), 'a wrong-edition database is still refused' );

// Header plus 188 of the entry's 2 KB+, and the stream simply ends there.
$arc = $faz_tmp . '/trunc.tar.gz';
file_put_contents( $arc, gzencode( substr( tar_entry( 'GeoLite2-City.mmdb', mm_build( 'GeoLite2-City', 2048 ) ), 0, 700 ) ) );
$res = $extract->invoke( null, $arc, 'GeoLite2-City' );
ms_check( is_wp_error( $res ), 'a truncated entry is refused' );

echo "\n== read_tar_entry(): byte budget and entry cap ==\n";

// A filler entry before the database: the walk has to decompress past it,
// and those bytes count. A gzip bomb is the same shape at a larger size.
$arc   = tar_gz( $faz_tmp . '/bomb.tar.gz', tar_entry( 'filler.bin', str_repeat( "\0", 2 * 1048576 ) ) . tar_entry( 'x.mmdb', $city ) );
$out   = $faz_tmp . '/bomb.out';
$threw = false;
try {
	ms_walk( $walk, array( $arc, '.mmdb', $out, 1048576, 1048576 ) );
} catch ( RuntimeException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'decompressed size limit' );
} catch ( LogicException $e ) {
	$threw = false;
}
ms_check( $threw, 'an archive that decompresses past the budget is refused' );
ms_check( ! file_exists( $out ), 'nothing is written for the refused archive' );

$threw = false;
try {
	ms_walk( $walk, array( tar_gz( $faz_tmp . '/big.tar.gz', tar_entry( 'x.mmdb', str_repeat( 'm', 5000 ) ) ), '.mmdb', $out, 1048576, 4096 ) );
} catch ( RuntimeException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'size limit' );
} catch ( LogicException $e ) {
	$threw = false;
}
ms_check( $threw, 'an entry over the entry cap is refused' );

$threw = false;
try {
	ms_walk( $walk, array( 'phar://' . $faz_tmp . '/city.tar.gz', '.mmdb', $out, 1048576, 1048576 ) );
} catch ( RuntimeException $e ) {
	$threw = true;
} catch ( LogicException $e ) {
	$threw = false;
}
ms_check( $threw, 'a stream-wrapper path is refused' );

$src = (string) file_get_contents( $root . '/includes/class-geolocation.php' );
ms_check( false === strpos( $src, 'new \\PharData' ) && false === strpos( $src, '->decompress(' ), 'extract_mmdb() no longer goes through PharData' );

echo "\n== Mmdb_Reader: handle-based reads ==\n";

$big_path = $faz_tmp . '/big.mmdb';
file_put_contents( $big_path, mm_build( 'GeoLite2-City', 24 * 1048576 ) );
$before = memory_get_usage();
$reader = new Mmdb_Reader( $big_path );
$grew   = memory_get_usage() - $before;
ms_check( $grew < 2 * 1048576, 'opening a 24 MB database keeps under 2 MB in memory (grew ' . $grew . ' bytes)' );
ms_check( 'GeoLite2-City' === $reader->database_type(), 'database_type() comes from the metadata tail' );
ms_check( 'AU' === $reader->country( '1.2.3.4' ), 'country() of a left-branch address' );
ms_check( 'US' === $reader->country( '200.1.2.3' ), 'country() of a right-branch address' );
ms_check( 'CA' === $reader->subdivision( '200.1.2.3' ), 'subdivision() reads the array record' );
ms_check( '' === $reader->subdivision( '1.2.3.4' ), 'subdivision() is empty where the record has none' );
ms_check( '' === $reader->country( 'not-an-ip' ), 'an invalid address resolves to nothing' );
unset( $reader );

file_put_contents( $faz_tmp . '/nometa.mmdb', str_repeat( "\0", 4096 ) );
$threw = false;
try {
	new Mmdb_Reader( $faz_tmp . '/nometa.mmdb' );
} catch ( RuntimeException $e ) {
	$threw = true;
}
ms_check( $threw, 'a file without the metadata marker is refused' );

// Cleanup.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $faz_tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
rmdir( $faz_tmp );

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
