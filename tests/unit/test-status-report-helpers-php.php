<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
function esc_html__($s,$d=null){return $s;} function esc_html($s){return $s;}
function date_i18n($f,$t){return date($f,$t);}
function human_time_diff($a,$b){$d=abs($b-$a); return floor($d/86400).' days';}
function esc_attr($s){return $s;} function esc_url($s){return $s;}
function wp_kses_allowed_html($c){return array();} function apply_filters($t,$v){return $v;}
require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';

$ok=0;$ko=0;
function t($c,$l){global $ok,$ko; if($c){$ok++;echo "  PASS $l\n";}else{$ko++;echo "  FAIL $l\n";}}

$on  = faz_status_flag(true);
$off = faz_status_flag(false);
t(strpos($on,'Yes')!==false,  'ON carries the word "Yes", not only an icon');
t(strpos($off,'No')!==false,  'OFF carries the word "No"');
// Il cuore della issue: togli gli emoji e il significato deve restare.
// Models the real path: the browser decodes the entity to a character, the
// Copy button reads textContent, and the clipboard/editor may drop non-ASCII.
$strip = function($s){
	$decoded = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
	return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\x20-\x7E]/u', '', $decoded)));
};
t($strip($on)==='Yes',  'ON survives emoji stripping as "Yes"');
t($strip($off)==='No',  'OFF survives emoji stripping as "No"');

$future = faz_status_schedule(time()+3600);
$late   = faz_status_schedule(time()-5*86400);
$none   = faz_status_schedule(false);
t(stripos($future,'OVERDUE')===false, 'a future schedule is not flagged');
t(stripos($late,'OVERDUE')!==false,   'a past schedule IS flagged overdue');
t(stripos($late,'5 days')!==false,    'and says how late it is');
t(stripos($none,'not scheduled')!==false, 'an absent schedule says so instead of printing a dash');
t($strip($none)!=='', 'the absent case also survives stripping');

echo "\nstatus report helpers: $ok passed, $ko failed\n";
exit($ko>0?1:0);
