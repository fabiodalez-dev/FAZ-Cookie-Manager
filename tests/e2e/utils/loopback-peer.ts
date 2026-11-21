/**
 * Make the test site read the visitor address from X-Forwarded-For.
 *
 * faz-test is served on 127.0.0.1, so every request reaches PHP from a
 * loopback peer. faz_client_ip_identifies_visitor() treats a loopback address
 * as shared, and the per-connection limits that would deny a right (the DSAR
 * lock, the Do-Not-Sell 60-second window) correctly step aside for it. A spec
 * that pins one of those windows therefore has to present a public visitor
 * address, the way a reverse proxy in front of WordPress would: this installs
 * a throwaway mu-plugin naming X-Forwarded-For as the loopback proxy header,
 * and the spec sends `X-Forwarded-For: <public address>` on its requests.
 */
import { wpEval } from './wp-env';

const FILE = 'faz-e2e-loopback-peer.php';

/** A public, routable address (not TEST-NET, which counts as reserved). */
export const PUBLIC_PEER = '8.8.4.4';

/**
 * Headers that present PUBLIC_PEER as the visitor. faz-test's dev mu-plugin
 * (faz-geo-dev-fake-cf) may rewrite REMOTE_ADDR to a Cloudflare edge address,
 * in which case the plugin reads CF-Connecting-IP; on a plain loopback peer it
 * reads X-Forwarded-For through the fixture below. Send both.
 */
export const PUBLIC_PEER_HEADERS: Record<string, string> = {
  'X-Forwarded-For': PUBLIC_PEER,
  'CF-Connecting-IP': PUBLIC_PEER,
};

export function installLoopbackPeer(): void {
  wpEval(`
    $code = '<?php add_filter( "faz_loopback_proxy_header", static function () { return "HTTP_X_FORWARDED_FOR"; } );';
    if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { wp_mkdir_p( WPMU_PLUGIN_DIR ); }
    file_put_contents( WPMU_PLUGIN_DIR . '/${FILE}', $code );
    echo 'ok';
  `);
}

export function removeLoopbackPeer(): void {
  wpEval(`
    $path = WPMU_PLUGIN_DIR . '/${FILE}';
    if ( file_exists( $path ) ) { unlink( $path ); }
    echo 'ok';
  `);
}
