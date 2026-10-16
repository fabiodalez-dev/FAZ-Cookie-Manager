<?php
/** Execute the real exporter, eraser and history controller with an in-memory DB. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
function __( $s, $domain = '' ) { return $s; }
function _n( $one, $many, $n, $domain = '' ) { return 1 === $n ? $one : $many; }
function sanitize_email( $s ) { return $s; }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function post_type_exists( $s ) { return true; }
function did_action( $s ) { return false; }
function add_action( ...$args ) {}
function delete_transient( $key ) {}
function current_time( ...$args ) { return '2026-10-16 12:00:00'; }
function get_post_field( ...$args ) { return '2026-10-16'; }
function wp_update_post( $args ) { return $args['ID']; }
function wp_delete_post( $id, $force ) { unset( $GLOBALS['meta'][$id] ); return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function apply_filters( $name, $value, ...$args ) { return $GLOBALS['filters'][$name] ?? $value; }
class WP_Query {
 public $posts; public $max_num_pages = 1;
 public function __construct( $args ) {
  $this->posts = array_keys( array_filter( $GLOBALS['meta'], function( $meta ) use ( $args ) { return ( $meta['_dsar_email'] ?? '' ) === $args['meta_query'][0]['value']; } ) );
 }
}
class Privacy_DB {
 public $prefix = 'wp_'; public $rows = array(); public $fail_delete = false;
 public function prepare( $sql, $value ) { return array( $sql, $value ); }
 public function get_results( $query, $output ) {
  if ( false === strpos( $query[0], 'WHERE consent_id = %s ORDER BY created_at DESC, log_id DESC' ) ) throw new RuntimeException( 'unsafe history query' );
  $rows = array_values( array_filter( $this->rows, function( $row ) use ( $query ) { return $row['consent_id'] === $query[1]; } ) );
  usort( $rows, function( $a, $b ) { return $b['log_id'] <=> $a['log_id']; } );
  return $rows;
 }
 public function delete( $table, $where, $formats ) {
  if ( $this->fail_delete ) return false;
  $before = count( $this->rows );
  $this->rows = array_values( array_filter( $this->rows, function( $row ) use ( $where ) { return $row['consent_id'] !== $where['consent_id']; } ) );
  return $before - count( $this->rows );
 }
}
require dirname(__DIR__,2) . '/admin/modules/consentlogs/includes/class-controller.php';
require dirname(__DIR__,2) . '/includes/class-utils.php';
require dirname(__DIR__,2) . '/includes/class-ip-hasher.php';
require dirname(__DIR__,2) . '/includes/class-dsar-shortcode.php';
$wpdb = new Privacy_DB();
$checks = 0;
function privacy_check( $v, $label ) { global $checks; ++$checks; if ( ! $v ) throw new RuntimeException( $label ); }
function seed_privacy() {
 global $wpdb;
 $GLOBALS['filters'] = array();
 $GLOBALS['meta'] = array( 1 => array( '_dsar_email' => 'visitor@example.test', '_dsar_consent_id' => 'visitor123', '_dsar_status' => 'completed' ) );
 $wpdb->fail_delete = false;
 $wpdb->rows = array(
  array( 'log_id'=>1, 'consent_id'=>'visitor123', 'status'=>'accepted', 'ip_hash'=>'hash1', 'user_agent'=>'hash2' ),
  array( 'log_id'=>2, 'consent_id'=>'visitor123', 'status'=>'rejected' ),
  array( 'log_id'=>3, 'consent_id'=>'anotherVisitor', 'status'=>'accepted' ),
 );
}
foreach ( array( '', 'abc123+/=', str_repeat('a',190) ) as $id ) privacy_check( \FazCookie\Includes\DSAR_Shortcode::valid_consent_id($id), 'valid consent id accepted' );
foreach ( array( '<bad>', 'space id', str_repeat('a',191), "id\n", array('bad') ) as $id ) privacy_check( ! \FazCookie\Includes\DSAR_Shortcode::valid_consent_id($id), 'invalid consent id rejected' );
seed_privacy();
$export = faz_privacy_exporter( 'visitor@example.test' );
$logs = array_values(array_filter($export['data'], function($row){ return $row['group_id']==='faz_consent_logs'; }));
privacy_check( count($logs)===2, 'all decisions exported, not only newest' );
privacy_check( $logs[0]['item_id']==='faz-consent-2', 'history newest first' );
privacy_check( faz_privacy_exporter('unrelated@example.test')['data']===array(), 'unrelated email cannot resolve history' );
foreach ( array( true, false ) as $retain_request ) {
 seed_privacy(); $GLOBALS['filters']['faz_dsar_erasure_retain_record']=$retain_request;
 $result = faz_privacy_eraser('visitor@example.test');
 privacy_check(count($wpdb->rows)===1 && $wpdb->rows[0]['consent_id']==='anotherVisitor', 'all and only matching decisions erased');
 privacy_check(!isset($GLOBALS['meta'][1]['_dsar_consent_id']), 'identifier removed in either DSAR erasure mode');
 privacy_check($result['items_removed']===3, 'both consent rows and request counted');
}
seed_privacy(); $GLOBALS['filters']['faz_consent_log_erasure_retain']=true;
$result=faz_privacy_eraser('visitor@example.test');
privacy_check(count($wpdb->rows)===3 && $result['items_retained'], 'optional retention filter reports retained history');
privacy_check(!isset($GLOBALS['meta'][1]['_dsar_consent_id']), 'retention does not preserve the DSAR identifier');
seed_privacy(); unset($GLOBALS['meta'][1]['_dsar_consent_id']);
faz_privacy_eraser('visitor@example.test');
privacy_check(count($wpdb->rows)===3, 'without a supplied identifier unrelated history stays untouched');
seed_privacy(); $wpdb->fail_delete=true;
$result=faz_privacy_eraser('visitor@example.test');
privacy_check($result['items_retained'] && isset($GLOBALS['meta'][1]['_dsar_consent_id']), 'DB failure preserves the link for retry');
privacy_check($result['done'], 'DB failure cannot cause an endless core erasure loop');
echo "ALL PASS ($checks consent ID privacy checks)\n";
