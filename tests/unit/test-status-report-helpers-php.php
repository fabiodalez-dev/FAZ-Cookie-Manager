<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
function esc_html__($s,$d=null){return $s;} function esc_html($s){return $s;}
function date_i18n($f,$t){return date($f,$t);}
function human_time_diff($a,$b){$d=abs($b-$a); return floor($d/86400).' days';}
function get_option($k,$d=false){ return $k==='gmt_offset' ? ($GLOBALS['FAZ_TZ_OFFSET'] ?? 0) : $d; }
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

// The schedule is rendered with the site's offset, not UTC. date_i18n()'s
// timestamp argument carries the offset already (legacy contract) while
// wp_next_scheduled() returns a true Unix timestamp, so the naive call showed
// 19:04 on a Europe/Rome site for a schedule that is 21:04 to its admin.
$GLOBALS['FAZ_TZ_OFFSET'] = 0;
$utc  = faz_status_schedule(1788375846);
$GLOBALS['FAZ_TZ_OFFSET'] = 2;
$rome = faz_status_schedule(1788375846);
$GLOBALS['FAZ_TZ_OFFSET'] = 0;
t($utc !== $rome, 'the same instant renders differently under a different site offset');
t(strpos($rome, gmdate('Y-m-d H:i:s', 1788375846 + 7200)) !== false, 'and the offset applied is the site\'s, not zero');

// WP-Cron fires on the next page load, so a just-passed schedule is the normal
// state of every healthy site between the due instant and the next visitor.
// The first version of this helper alarmed on ANY positive lag, and the first
// version of this test never noticed — it only tried 5 days and the future,
// i.e. only the two cases that could not expose the false alarm.
$barely = faz_status_schedule(time()-90);
t(stripos($barely,'OVERDUE')===false, 'a schedule 90s past is NOT alarmed (cron is traffic-driven)');
t(stripos($barely,'WP-Cron')===false, 'and makes no claim about WP-Cron at all');
// The helper reads a timestamp; it never tests whether cron runs. It may
// suggest the cause, it may not assert it.
t(stripos($late,'may not be running')!==false, 'the overdue text offers a hypothesis, not a verdict');
t(stripos($late,'Cron is not running')===false, 'and never states an unverified cause as fact');
t($strip($none)!=='', 'the absent case also survives stripping');

echo "\nstatus report helpers: $ok passed, $ko failed\n";
exit($ko>0?1:0);
