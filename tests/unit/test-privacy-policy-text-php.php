<?php
/** Suggested copy changes with feature settings, and names the real storage. */
define( 'ABSPATH', __DIR__ );
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars($s); }
function esc_html( $s ) { return htmlspecialchars($s); }
require dirname(__DIR__,2) . '/includes/class-privacy-policy-text.php';
use FazCookie\Includes\Privacy_Policy_Text as Policy;
$checks=0;
function policy_check($v,$label) { global $checks; ++$checks; if(!$v) throw new RuntimeException($label); }
$off=array('consent_logs'=>array('status'=>false));
$facts=Policy::facts($off,array());
foreach(array('logs','hashes','tcf','geo','maxmind','ipinfo','pageviews') as $key) policy_check(!isset($facts[$key]),'disabled feature omitted: '.$key);
policy_check(strpos($facts['withdrawal'],'floating')!==false,'default route is floating button');
$settings=array('consent_logs'=>array('status'=>true,'retention'=>9),'iab'=>array('enabled'=>true),'geolocation'=>array('geo_targeting'=>true),'pageview_tracking'=>true,'pageviews'=>array('retention'=>3),'dsar'=>array('retention'=>18));
$facts=Policy::facts($settings,array('withdrawal_satisfied'=>true,'has_mmdb'=>true,'ipinfo_active'=>true));
foreach(array('fazcookie-consent','consent ID','fazcookie-dnsmpi') as $cookie) policy_check(strpos(implode(' ',$facts),$cookie)!==false,'core identifier disclosed: '.$cookie);
policy_check(strpos($facts['tcf'],'euconsent-v2')!==false && strpos($facts['tcf'],'fazVendorConsent')!==false,'TCF cookies disclosed');
policy_check(strpos($facts['hashes'],'salted SHA-256')!==false && strpos($facts['hashes'],'pseudonymous')!==false,'hashing described accurately');
policy_check(strpos($facts['log_retention'],'9 months')!==false,'configured log retention');
policy_check(strpos($facts['pageview_retention'],'3 months')!==false,'configured counter retention');
policy_check(strpos($facts['dsar'],'18 months')!==false && strpos($facts['dsar'],'consent ID')!==false,'configured DSAR storage and identifier');
policy_check(strpos($facts['withdrawal'],'site footer')!==false,'verified footer route');
policy_check(strpos($facts['ipinfo'],'cleartext')!==false,'external raw IP disclosure');
policy_check(strpos($facts['pageviews'],'without a visitor identifier')!==false,'aggregate counter disclosure');
$settings['pageviews']['retention']=0;
policy_check(strpos(Policy::facts($settings,array())['pageview_retention'],'no automatic')!==false,'unlimited retention disclosed');
$root=dirname(__DIR__,2);
foreach(array('MaxMind','ipinfo.io') as $provider) policy_check(strpos(file_get_contents($root.'/readme.txt'),$provider)!==false,'service documented in readme');
policy_check(strpos(Policy::build($settings,array()),'<h2>')===0,'builder produces policy HTML');
echo "ALL PASS ($checks policy text checks)\n";
