/** Core-hook integration: invoking a callback directly cannot detect a wrong hook name. */
import { test, expect } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

test('WordPress core inline rendering invokes the registered FAZ attributes filter', () => {
  const result = JSON.parse(wpEval(`
    global $wp_filter;
    $frontend = null;
    foreach ( $wp_filter['wp_inline_script_attributes']->callbacks ?? array() as $callbacks ) {
      foreach ( $callbacks as $callback ) {
        $fn = $callback['function'];
        if ( is_array($fn) && $fn[0] instanceof \\FazCookie\\Frontend\\Frontend && 'filter_inline_script_attributes' === $fn[1] ) {
          $frontend = $fn[0];
          break 2;
        }
      }
    }
    if (!$frontend) { echo json_encode(array('registered'=>false)); return; }
    // Request-local fixture only; no settings, content or cache writes.
    foreach (array(
      'template'=>'<div></div>', 'provider_map_cache'=>array('wrapper'=>'functional','tracker'=>'analytics'),
      'blocked_categories_cache'=>array('analytics'), 'whitelist_cache'=>array(),
      'settings_option_cache'=>array(), 'service_consent_cache'=>array(), 'gcm_settings'=>null
    ) as $name=>$value) {
      $prop=new ReflectionProperty($frontend,$name); $prop->setAccessible(true); $prop->setValue($frontend,$value);
    }
    wp_register_script('wrapper-tracker',false);
    wp_add_inline_script('wrapper-tracker','window.fixtureInline=1;','before');
    $before=wp_scripts()->get_inline_script_tag('wrapper-tracker','before');
    $module=wp_get_inline_script_tag('window.fixtureInline=1;',array('id'=>'wrapper-tracker-js-after','type'=>'module','nonce'=>'preserved'));
    $extra=wp_get_inline_script_tag('tracker();',array('id'=>'tracker-js-extra'));
    echo json_encode(array('registered'=>true,'before'=>$before,'module'=>$module,'extra'=>$extra));
  `));
  expect(result.registered).toBe(true);
  expect(result.before).toContain('type="text/plain"');
  expect(result.before).toContain('data-faz-category="analytics"');
  expect(result.module).toContain('data-faz-original-type="module"');
  expect(result.module).toContain('nonce="preserved"');
  expect(result.extra).not.toContain('data-faz-category');
});
