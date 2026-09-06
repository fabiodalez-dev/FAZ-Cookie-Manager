import { wpEval } from './wp-env';

/** Closed-token records and expired index rows must not count as live scans. */
export function countActiveBrowserScanSessions(): number {
  return Number.parseInt(wpEval(`
    $controller = \\FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller::get_instance();
    $count = 0;
    foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
      wp_set_current_user( (int) $user_id );
      $state = $controller->describe_browser_scan_session();
      if ( ! empty( $state['active'] ) ) { ++$count; }
    }
    echo $count;
  `).trim(), 10);
}
