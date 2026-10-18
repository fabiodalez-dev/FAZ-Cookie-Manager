<?php
/** Suggested copy changes with feature settings, and names the real storage. */
define( 'ABSPATH', __DIR__ );
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars($s); }
function esc_html( $s ) { return htmlspecialchars($s); }
define('DAY_IN_SECONDS',86400);
$GLOBALS['policy_options']=array();
function get_option($key,$default=false) {return $GLOBALS['policy_options'][$key] ?? $default;}
class PolicySecrets { public static function decrypt($value){return $value;} }
class PolicyGeo { public static function has_database(){return true;} }
// The footer route is configured but its verification is never satisfied, as
// on a site whose probe went stale: the policy text must still describe the
// configured route instead of flipping to the floating button by itself.
class PolicyWithdrawal {
 const PATH_FOOTER_LINK='footer_link';
 public static function configured_path($settings=null){return ($settings['banner_control']['withdrawal_path'] ?? '')==='footer_link' ? 'footer_link' : 'widget';}
 public static function satisfies_revisit_requirement($settings=null){return false;}
}
class_alias(PolicySecrets::class,'FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Secrets');
class_alias(PolicyGeo::class,'FazCookie\\Includes\\Geolocation');
class_alias(PolicyWithdrawal::class,'FazCookie\\Includes\\Withdrawal_Path');
require dirname(__DIR__,2) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';
require dirname(__DIR__,2) . '/includes/class-privacy-policy-text.php';
use FazCookie\Includes\Privacy_Policy_Text as Policy;
$checks=0;
function policy_check($v,$label) { global $checks; ++$checks; if(!$v) throw new RuntimeException($label); }
$off=array('consent_logs'=>array('status'=>false));
$facts=Policy::facts($off,array());
foreach(array('logs','hashes','tcf','geo','maxmind','ipinfo','pageviews') as $key) policy_check(!isset($facts[$key]),'disabled feature omitted: '.$key);
policy_check(strpos($facts['withdrawal'],'floating')!==false,'default route is floating button');
$settings=array('consent_logs'=>array('status'=>true,'retention'=>9),'iab'=>array('enabled'=>true),'geolocation'=>array('geo_targeting'=>true),'pageview_tracking'=>true,'pageviews'=>array('retention'=>3),'dsar'=>array('retention'=>18));
$facts=Policy::facts($settings,array('withdrawal_footer'=>true,'has_mmdb'=>true,'ipinfo_active'=>true));
foreach(array('fazcookie-consent','consent ID','fazcookie-dnsmpi') as $cookie) policy_check(strpos(implode(' ',$facts),$cookie)!==false,'core identifier disclosed: '.$cookie);
policy_check(strpos($facts['tcf'],'euconsent-v2')!==false && strpos($facts['tcf'],'fazVendorConsent')!==false,'TCF cookies disclosed');
policy_check(strpos($facts['tcf_li'],'fazVendorLI')!==false,'Right-to-Object cookie disclosed');
policy_check(strpos($facts['hashes'],'salted SHA-256')!==false && strpos($facts['hashes'],'pseudonymous')!==false,'hashing described accurately');
policy_check(strpos($facts['log_retention'],'9 months')!==false,'configured log retention');
policy_check(strpos($facts['pageview_retention'],'3 months')!==false,'configured counter retention');
policy_check(strpos($facts['dsar'],'18 months')!==false && strpos($facts['dsar'],'consent ID')!==false,'configured DSAR storage and identifier');
policy_check(strpos($facts['withdrawal'],'site footer')!==false,'configured footer route');
$footer_settings=$settings+array('banner_control'=>array('withdrawal_path'=>'footer_link'));
policy_check(strpos(Policy::facts($footer_settings,Policy::environment($footer_settings))['withdrawal'],'site footer')!==false,'a footer route whose verification lapsed is still described as the footer link');
policy_check(strpos(Policy::facts($settings,Policy::environment($settings))['withdrawal'],'floating')!==false,'the widget route is described as the floating button');
policy_check(strpos($facts['ipinfo'],'cleartext')!==false,'external raw IP disclosure');
policy_check(strpos($facts['pageviews'],'without a visitor identifier')!==false,'aggregate counter disclosure');
$settings['pageviews']['retention']=0;
policy_check(strpos(Policy::facts($settings,array())['pageview_retention'],'no automatic')!==false,'unlimited retention disclosed');
$root=dirname(__DIR__,2);
foreach(array('MaxMind','ipinfo.io') as $provider) policy_check(strpos(file_get_contents($root.'/readme.txt'),$provider)!==false,'service documented in readme');
policy_check(strpos(Policy::build($settings,array()),'<h2>')===0,'builder produces policy HTML');

foreach(array(false,true) as $optin) foreach(array('', 'configured-key') as $key) {
 $GLOBALS['policy_options']=array('faz_geo_ipinfo_optin'=>$optin,'faz_geo_ipinfo_api_key'=>$key);
 $env=Policy::environment($settings);
 $html=Policy::build($settings,$env);
 policy_check($env['ipinfo_active']===($optin && $key!==''), 'environment requires both opt-in and a usable API key');
 policy_check((strpos($html,'ipinfo.io')!==false)===($optin && $key!==''), 'full builder follows the external-service gate');
 foreach(Policy::facts($settings,$env) as $sentence) policy_check(strpos($html,esc_html($sentence))!==false,'build renders the complete fact set');
}
echo "ALL PASS ($checks policy text checks)\n";
