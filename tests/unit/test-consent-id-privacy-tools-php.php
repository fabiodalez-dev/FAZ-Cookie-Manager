<?php
/** Execute the real exporter, eraser and history controller with an in-memory DB. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public $code; public $message; public function __construct($code,$message){$this->code=$code;$this->message=$message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_hash($value,$scheme='auth'){return hash_hmac('sha256',$value,'privacy-test-salt');}
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
function current_user_can($cap,...$args){return $cap==='edit_post' || !empty($GLOBALS['privacy_admin']);}
function check_admin_referer($action){$GLOBALS['checked_nonce']=$action;}
function get_post($id){return (object)array('ID'=>$id,'post_type'=>'faz_dsar');}
function absint($value){return abs((int)$value);}
function wp_unslash($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_]/','',$v);}
function esc_html__($v,$domain=''){return $v;}
function wp_die($message,...$args){throw new RuntimeException($message);}
class WP_Query {
 public $posts; public $max_num_pages = 1;
 public function __construct( $args ) {
  $matches = array_keys( array_filter( $GLOBALS['meta'], function( $meta ) use ( $args ) { return ( $meta['_dsar_email'] ?? '' ) === $args['meta_query'][0]['value']; } ) );
  $this->posts = array_slice($matches, (($args['paged'] ?? 1)-1)*$args['posts_per_page'], $args['posts_per_page']);
  $this->max_num_pages = (int)ceil(count($matches)/$args['posts_per_page']);
 }
}
class Privacy_DB {
 public $prefix = 'wp_'; public $rows = array(); public $fail_delete = false; public $fail_read = false; public $last_error = '';
 public function prepare( $sql, $value ) { return array( $sql, $value ); }
 public function get_results( $query, $output ) {
  $this->last_error = $this->fail_read ? 'synthetic database failure' : '';
  if ($this->fail_read) return array();
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
require dirname(__DIR__,2) . '/includes/class-dsar-admin.php';
$wpdb = new Privacy_DB();
$checks = 0;
function privacy_check( $v, $label ) { global $checks; ++$checks; if ( ! $v ) throw new RuntimeException( $label ); }
function seed_privacy() {
 global $wpdb;
 $GLOBALS['filters'] = array();
 $GLOBALS['meta'] = array( 1 => array( '_dsar_email' => 'visitor@example.test', '_dsar_consent_id' => 'visitor123', '_dsar_status' => 'completed' ) );
 $GLOBALS['meta'][1]['_dsar_consent_id_verified'] = faz_dsar_consent_verification_token(1);
 $wpdb->fail_read = false;
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
privacy_check(is_wp_error($result) && isset($GLOBALS['meta'][1]['_dsar_consent_id']), 'DB failure stops core with an error and preserves the link for retry');

seed_privacy(); $wpdb->fail_read=true;
privacy_check(is_wp_error(faz_privacy_exporter('visitor@example.test')), 'query failure cannot masquerade as an empty successful export');
seed_privacy(); $wpdb->rows=array();
privacy_check(!is_wp_error(faz_privacy_exporter('visitor@example.test')), 'a genuinely empty history remains exportable');
seed_privacy(); unset($GLOBALS['meta'][1]['_dsar_consent_id_verified']);
$export=faz_privacy_exporter('visitor@example.test');
privacy_check(count($export['data'])===1 && $export['data'][0]['group_id']==='faz_dsar_requests', 'a typed foreign ID never authorizes export');
$result=faz_privacy_eraser('visitor@example.test');
privacy_check(count($wpdb->rows)===3, 'a typed foreign ID never authorizes erasure');
privacy_check($result['items_retained'] && !isset($GLOBALS['meta'][1]['_dsar_consent_id']), 'the unverified ID is discarded with the request and the history is reported retained');
$said=implode(' ', $result['messages']);
privacy_check(false===strpos($said,'has not been independently verified') && false!==strpos($said,'The unverified ID has been removed with the request'), 'the administrator is told the ID is gone, not that it can still be verified');
privacy_check(false!==strpos($said,'verify it before running the erasure'), 'and how the remaining history can still be erased');
seed_privacy(); $GLOBALS['meta'][1]['_dsar_consent_id']='anotherVisitor';
privacy_check(faz_dsar_verified_consent_id(1,'visitor@example.test')==='', 'changing an attested ID invalidates verification');
seed_privacy(); $GLOBALS['meta'][1]['_dsar_email']='attacker@example.test';
privacy_check(faz_dsar_verified_consent_id(1,'attacker@example.test')==='', 'changing the linked email invalidates verification');
seed_privacy();
for($i=2;$i<=25;$i++) {
 $GLOBALS['meta'][$i]=$GLOBALS['meta'][1];
 $GLOBALS['meta'][$i]['_dsar_consent_id_verified']=faz_dsar_consent_verification_token($i);
}
$wpdb->fail_delete=true;
privacy_check(is_wp_error(faz_privacy_eraser('visitor@example.test')), 'a failed batch of more than twenty requests does not complete');
privacy_check(count($GLOBALS['meta'])===25, 'failed batch retains all request links');
$wpdb->fail_delete=false;
$res=faz_privacy_eraser('visitor@example.test');
privacy_check(!is_wp_error($res) && !$res['done'], 'retry drains first twenty and leaves the next batch pending');
$res=faz_privacy_eraser('visitor@example.test',2);
privacy_check(!is_wp_error($res) && $res['done'], 'retry drains the remaining five without an endless loop');

seed_privacy(); unset($GLOBALS['meta'][1]['_dsar_consent_id_verified']);
$_GET=array('post'=>1,'faz_action'=>'verify_consent_id');
$admin=(new ReflectionClass(\FazCookie\Includes\DSAR_Admin::class))->newInstanceWithoutConstructor();
try { $admin->handle_action(); throw new RuntimeException('unexpected permission bypass'); }
catch (RuntimeException $error) { privacy_check(strpos($error->getMessage(),'not allowed')!==false,'DSAR editing alone cannot attest consent ownership'); }
privacy_check($GLOBALS['checked_nonce']===\FazCookie\Includes\DSAR_Admin::nonce_action(1,'verify_consent_id'), 'ownership verification checks a record/action-specific nonce');
privacy_check(faz_dsar_verified_consent_id(1,'visitor@example.test')==='', 'rejected verification cannot grant data access');
// Issue #327: the declared consent ID is compared at submission with the
// consent cookie of the browser that sends the request, and only the outcome
// is stored and shown next to the verification button.
if ( ! function_exists( 'faz_parse_consent_cookie' ) ) {
 function faz_parse_consent_cookie() {
  $raw = (string) ( $_COOKIE['fazcookie-consent'] ?? '' ); $out = array();
  foreach ( explode( ',', $raw ) as $pair ) { $kv = explode( ':', $pair, 2 ); if ( 2 === count( $kv ) ) $out[ trim( $kv[0] ) ] = trim( $kv[1] ); }
  return $out;
 }
}
$shortcode = \FazCookie\Includes\DSAR_Shortcode::class;
privacy_check( method_exists( $shortcode, 'consent_cookie_match' ), 'the submission compares the declared ID with the submitting browser' );
if ( method_exists( $shortcode, 'consent_cookie_match' ) ) {
 $_COOKIE = array( 'fazcookie-consent' => 'action:yes,consentid:visitor123,necessary:yes' );
 privacy_check( 'match' === $shortcode::consent_cookie_match( 'visitor123' ), 'the same ID as the browser cookie is a match' );
 privacy_check( 'mismatch' === $shortcode::consent_cookie_match( 'anotherVisitor' ), 'another ID is a mismatch' );
 privacy_check( '' === $shortcode::consent_cookie_match( '' ), 'no declared ID records nothing' );
 $_COOKIE = array();
 privacy_check( 'no_cookie' === $shortcode::consent_cookie_match( 'visitor123' ), 'a browser without a consent cookie is recorded as such' );
}
$admin_class = \FazCookie\Includes\DSAR_Admin::class;
privacy_check( method_exists( $admin_class, 'consent_cookie_note' ), 'the administrator is shown the outcome' );
if ( method_exists( $admin_class, 'consent_cookie_note' ) ) {
 $GLOBALS['meta'][7] = array( '_dsar_consent_id_cookie' => 'mismatch' );
 $note = $admin_class::consent_cookie_note( 7 );
 privacy_check( false !== strpos( $note, 'notice-warning' ) && false !== strpos( $note, 'does not match' ), 'a mismatch is shown as a warning' );
 $GLOBALS['meta'][7] = array( '_dsar_consent_id_cookie' => 'match' );
 privacy_check( false !== strpos( $admin_class::consent_cookie_note( 7 ), 'matches the consent cookie' ) && false === strpos( $admin_class::consent_cookie_note( 7 ), 'notice-warning' ), 'a match is shown as support, without a warning' );
 $GLOBALS['meta'][7] = array( '_dsar_consent_id_cookie' => 'no_cookie' );
 privacy_check( false !== strpos( $admin_class::consent_cookie_note( 7 ), 'no consent cookie' ), 'a missing cookie is said so' );
 $GLOBALS['meta'][7] = array();
 privacy_check( '' === $admin_class::consent_cookie_note( 7 ), 'a record from before the comparison shows nothing' );
 unset( $GLOBALS['meta'][7] );
}
seed_privacy(); $GLOBALS['meta'][1]['_dsar_consent_id_cookie']='match';
faz_privacy_eraser('visitor@example.test');
privacy_check(!isset($GLOBALS['meta'][1]['_dsar_consent_id_cookie']), 'the erasure removes the comparison outcome with the ID');
echo "ALL PASS ($checks consent ID privacy checks)\n";
