/**
 * Release verification: the scanner must not fabricate cookie declarations
 * from image URLs (F019), at EVERY inference entry point — while genuine
 * script/embed detections keep minting their cookies.
 *
 * What was broken: the scanner's page-subresource extraction regexes
 * word-boundary-match any attribute ending in `-src` (`\bsrc=` matches inside
 * `data-thumb-src=`), so a YouTube THUMBNAIL such as
 * img.youtube.com/vi/ID/hqdefault.jpg was harvested as a "script" and fed to
 * the unanchored provider matchers, fabricating YSC / VISITOR_INFO1_LIVE /
 * LOGIN_INFO. Those fabrications reach the PUBLIC cookie declaration and the
 * server-side deletion policy. The fix filters non-code asset URLs
 * (Controller::filter_inferable_script_urls) before inference at all three
 * entry points; the matchers themselves are deliberately unanchored because
 * ~27% of patterns catch self-hosted trackers whose host never appears in the
 * URL — so this spec also proves the opposite direction: genuine scripts,
 * embeds, and extension-less tracking beacons still yield their cookies. A
 * blanket-drop "fix" goes red on tests 3–6.
 *
 * These tests exercise the INSTALLED RELEASE through the live
 * `POST /faz/v1/scans/server-scan` REST route (whose inferred cookies the
 * scan engine imports verbatim as observed). The fixture pages are served by
 * the running site itself via a TEMPORARY mu-plugin this spec writes into
 * wp-content/mu-plugins/ and removes in a `finally` — the route's SSRF guard
 * only fetches same-origin URLs, and its URL normaliser appends a trailing
 * slash to paths, so a same-origin query-var page is the one shape that
 * survives both (`/?faz_scaninf_release=<scenario>`).
 *
 * Global-state footprint: the mu-plugin file is the ONLY mutation. The
 * server-scan route persists nothing (response-only — verified in
 * admin/modules/scanner/api/class-api.php::server_scan), so no cookie rows or
 * settings need snapshotting. Each test installs and removes its own copy of
 * the fixture and asserts the removal, so no later spec can see it.
 */
import { existsSync, mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiPost, openCookiesPage } from '../utils/faz-api';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const QUERY_VAR = 'faz_scaninf_release';
const MU_BASENAME = 'faz-e2e-release-verify-scanner-inference-lab.php';

// A real YouTube thumbnail URL shape. It contains the provider token
// (`youtube.com`) that the script→cookie matcher substring-matches, so
// WITHOUT the asset filter this URL mints YSC + VISITOR_INFO1_LIVE — which is
// exactly the fabrication this release claims to have fixed.
const THUMBNAIL_URL = 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg';
const GTM_URL = 'https://www.googletagmanager.com/gtag/js?id=G-FAZRELVERIFY';
const FB_LOADER_URL = 'https://connect.facebook.net/en_US/fbevents.js';
const YT_EMBED_URL = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
const FB_PIXEL_URL = 'https://www.facebook.com/tr?id=123456&ev=PageView&noscript=1';
const BING_BEACON_URL = 'https://bat.bing.com/action/0?ti=343';

const YOUTUBE_COOKIE_NAMES = ['YSC', 'VISITOR_INFO1_LIVE', 'LOGIN_INFO'];

/**
 * Fixture markup per scenario. These are scanner INPUTS fetched server-side
 * and parsed with regexes — no browser ever executes them, so the bare URLs
 * are deliberate: they are the exact shapes the extraction must (or must not)
 * turn into cookie declarations.
 */
const SCENARIOS: Record<string, string> = {
  // Thumbnail on a listed lazy-load attribute (`data-lazy-src` is one of the
  // iframe attributes the extractor reads on purpose).
  'thumb-lazy': `<iframe data-lazy-src="${THUMBNAIL_URL}" title="lazy-loaded video facade"></iframe>`,
  // Thumbnail on an UNLISTED attribute that the `\bsrc=` word boundary
  // matches anyway — the exact route the original fabrication came in by.
  // Note: no real src attribute at all; only `data-thumb-src`.
  'thumb-attr': `<iframe class="lazyload" data-thumb-src="${THUMBNAIL_URL}" title="thumbnail facade"></iframe>`,
  gtm: `<script src="${GTM_URL}"></script>`,
  fb: `<script src="${FB_LOADER_URL}"></script>`,
  'yt-embed': `<iframe src="${YT_EMBED_URL}" title="embedded video"></iframe>`,
  pixels: `<script src="${FB_PIXEL_URL}"></script><script src="${BING_BEACON_URL}"></script>`,
};

function muPluginSource(): string {
  const encoded = Buffer.from(JSON.stringify(SCENARIOS), 'utf8').toString('base64');
  return `<?php
/**
 * Plugin Name: FAZ E2E Release-Verify Scanner Inference Lab (temporary)
 * Description: Written and removed by release-verify-scanner-inference.spec.ts. Serves deterministic HTML for the server-scan inference tests. If you can read this outside a test run, delete it.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'init', function () {
\tif ( empty( $_GET['${QUERY_VAR}'] ) ) { return; }
\t$scenario = preg_replace( '/[^a-z0-9\\-]/', '', (string) $_GET['${QUERY_VAR}'] );
\t$pages = json_decode( base64_decode( '${encoded}' ), true );
\tif ( ! is_array( $pages ) || ! isset( $pages[ $scenario ] ) || ! is_string( $pages[ $scenario ] ) ) { return; }
\tstatus_header( 200 );
\theader( 'Content-Type: text/html; charset=utf-8' );
\techo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>FAZ scanner-inference lab</title></head><body>' . $pages[ $scenario ] . '</body></html>';
\texit;
}, 0 );
`;
}

/**
 * The webroot is needed only to place the mu-plugin. Prefer WP_PATH (the
 * documented env for WP-CLI-dependent specs); fall back to deriving it from
 * FAZ_PLUGIN_DEPLOY_PATH so the spec also runs with the minimal env set.
 */
function resolveMuPluginsDir(deployPath: string | null): string {
  let root = process.env.WP_PATH ?? '';
  if (!root && deployPath) {
    const marker = deployPath.indexOf('/wp-content/');
    if (marker > 0) {
      root = deployPath.slice(0, marker);
    }
  }
  if (!root || !existsSync(root)) {
    throw new Error(
      'Cannot locate the WordPress install root. Set WP_PATH (or FAZ_PLUGIN_DEPLOY_PATH) — ' +
        'this spec must place a temporary mu-plugin under wp-content/mu-plugins/ to serve its fixture pages.'
    );
  }
  return join(root, 'wp-content', 'mu-plugins');
}

/**
 * Install the fixture mu-plugin for the duration of one test and guarantee —
 * and assert — its removal, so no later spec inherits it. mu-plugins load on
 * every request but this one is inert unless the query var is present, and
 * the server-scan route itself persists nothing, so the file is the whole
 * state footprint.
 */
async function withInferenceLab<T>(deployPath: string | null, run: () => Promise<T>): Promise<T> {
  const muDir = resolveMuPluginsDir(deployPath);
  mkdirSync(muDir, { recursive: true });
  const muPath = join(muDir, MU_BASENAME);
  writeFileSync(muPath, muPluginSource(), 'utf8');
  try {
    return await run();
  } finally {
    if (existsSync(muPath)) {
      unlinkSync(muPath);
    }
    expect(existsSync(muPath), 'fixture mu-plugin must be removed so later specs cannot see it').toBe(false);
  }
}

type ServerScanCookie = {
  name: string;
  domain?: string;
  category?: string;
  description?: string;
  duration?: string;
};

type ServerScanResponse = {
  cookies: ServerScanCookie[];
  scripts: string[];
};

/**
 * POST the live route and hard-fail on anything but a well-formed 200. The
 * route answers 200 with EMPTY arrays when its page fetch fails, so callers
 * must additionally assert their fixture URL shows up in `scripts` before
 * trusting an empty `cookies` — an unreachable fixture must never turn a
 * no-fabrication test green.
 */
async function serverScan(page: Page, nonce: string, scenario: string): Promise<ServerScanResponse> {
  const url = `${WP_BASE}/?${QUERY_VAR}=${scenario}`;
  const response = await fazApiPost<ServerScanResponse>(page, nonce, 'scans/server-scan', { url });
  expect(response.status, `scans/server-scan must answer 200 for ${url}`).toBe(200);
  expect(Array.isArray(response.data?.cookies), 'server-scan response carries a cookies[] array').toBe(true);
  expect(Array.isArray(response.data?.scripts), 'server-scan response carries a scripts[] array').toBe(true);
  return response.data;
}

/** All cookie names in the response, header-derived and inferred alike. */
function cookieNames(data: ServerScanResponse): string[] {
  return data.cookies.map((cookie) => String(cookie.name));
}

/**
 * Only the INFERRED declarations. Provider-inferred entries carry a category
 * (see server_scan in admin/modules/scanner/api/class-api.php); entries
 * parsed from Set-Cookie headers carry name+domain only. Distinguishing them
 * keeps the "nothing was fabricated" assertions immune to header noise from
 * an unrelated plugin on the shared test install.
 */
function inferredCookies(data: ServerScanResponse): ServerScanCookie[] {
  return data.cookies.filter((cookie) => typeof cookie.category === 'string');
}

test.describe('Release verify: scanner inference never fabricates cookies from images', () => {
  test('a YouTube thumbnail on a lazy-load attribute is harvested but mints no cookie declarations', async ({
    page,
    loginAsAdmin,
    wpCreds,
  }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'thumb-lazy');

      // Vacuity guard: the thumbnail URL must actually have been harvested
      // from the page — otherwise "no cookies" proves only that the fixture
      // never reached the scanner.
      expect(data.scripts, 'thumbnail URL must be harvested from data-lazy-src').toContain(THUMBNAIL_URL);

      // The fabrication itself: an image URL from a cookie-bearing provider
      // must not mint that provider's cookies…
      const names = cookieNames(data);
      for (const fabricated of YOUTUBE_COOKIE_NAMES) {
        expect(names, `image URL must not fabricate ${fabricated}`).not.toContain(fabricated);
      }
      // …nor any other inferred declaration: the page's ONLY subresource is
      // the thumbnail, so every inferred entry here would be minted from an
      // image that sets nothing.
      expect(inferredCookies(data), 'no cookie may be inferred from an image-only page').toHaveLength(0);
    });
  });

  test('a thumbnail smuggled through a data-thumb-src attribute mints no cookie declarations', async ({
    page,
    loginAsAdmin,
    wpCreds,
  }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'thumb-attr');

      // The extraction regex word-boundary-matches `src=` inside
      // `data-thumb-src=` — this is HOW the original fabrication got in, so
      // the harvest itself must still happen for this test to mean anything.
      expect(
        data.scripts,
        'the \\bsrc= word boundary must harvest the URL out of data-thumb-src — if this stops, the test no longer exercises the fabrication route'
      ).toContain(THUMBNAIL_URL);

      const names = cookieNames(data);
      for (const fabricated of YOUTUBE_COOKIE_NAMES) {
        expect(names, `thumbnail harvested via data-thumb-src must not fabricate ${fabricated}`).not.toContain(fabricated);
      }
      expect(inferredCookies(data), 'no cookie may be inferred from a thumbnail-only page').toHaveLength(0);
    });
  });

  test('a genuine Google Tag Manager script still yields its analytics cookies on the site domain', async ({
    page,
    loginAsAdmin,
    wpCreds,
  }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'gtm');

      expect(data.scripts, 'the GTM script tag must be harvested').toContain(GTM_URL);

      // Opposite direction of tests 1–2: a filter that blanket-drops
      // subresources (instead of dropping only non-code assets) goes red
      // here, because the genuine detection disappears with the fabrication.
      const ga = data.cookies.find((cookie) => cookie.name === '_ga');
      expect(ga, 'a real googletagmanager.com script must still infer _ga').toBeTruthy();
      expect(ga?.category, '_ga must keep its analytics categorisation').toBe('analytics');
      // Inferred cookies are declared on the SITE domain (they are set on the
      // scanned site, not on the script host).
      expect(ga?.domain, '_ga must be declared on the site host, not the script host').toBe(new URL(WP_BASE).hostname);
    });
  });

  test('a genuine Facebook Pixel loader script still yields its marketing cookies', async ({
    page,
    loginAsAdmin,
    wpCreds,
  }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'fb');

      expect(data.scripts, 'the fbevents.js loader must be harvested').toContain(FB_LOADER_URL);

      const names = cookieNames(data);
      expect(names, 'connect.facebook.net loader must still infer _fbp').toContain('_fbp');
      expect(names, 'connect.facebook.net loader must still infer fr').toContain('fr');
      const fbp = data.cookies.find((cookie) => cookie.name === '_fbp');
      expect(fbp?.category, '_fbp must keep its marketing categorisation').toBe('marketing');
    });
  });

  test('a genuine YouTube embed still yields YSC and VISITOR_INFO1_LIVE', async ({ page, loginAsAdmin, wpCreds }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'yt-embed');

      expect(data.scripts, 'the embed iframe URL must be harvested').toContain(YT_EMBED_URL);

      // Same provider as the thumbnail tests, opposite verdict: the /embed/
      // player DOES set these cookies, so the filter must be discriminating
      // on the URL's shape (image vs code), not on the provider. If tests
      // 1–2 pass while this one fails, the "fix" deleted genuine detections.
      const names = cookieNames(data);
      expect(names, 'youtube.com/embed must still infer YSC').toContain('YSC');
      expect(names, 'youtube.com/embed must still infer VISITOR_INFO1_LIVE').toContain('VISITOR_INFO1_LIVE');
    });
  });

  test('extension-less tracking beacons survive the asset filter and stay importable', async ({
    page,
    loginAsAdmin,
    wpCreds,
  }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await withInferenceLab(wpCreds.deployPath, async () => {
      const data = await serverScan(page, nonce, 'pixels');

      // Tracking pixels have no file extension at all (facebook.com/tr,
      // bat.bing.com/action/0). A filter implemented as an allowlist of
      // "real script" extensions — instead of a blocklist of asset
      // extensions — would silently drop them. Both must stay in the
      // observed-scripts import.
      expect(data.scripts, 'the extension-less facebook.com/tr pixel must stay imported as an observed script').toContain(
        FB_PIXEL_URL
      );
      expect(data.scripts, 'the extension-less bat.bing.com beacon must stay imported as an observed script').toContain(
        BING_BEACON_URL
      );

      // And the extension-less shape must still reach the MATCHERS: the Bing
      // UET beacon URL is provider-matched (bat.bing.com), so its cookies
      // prove the filter passed an extension-less URL through to inference
      // rather than merely echoing it in the scripts list.
      const names = cookieNames(data);
      expect(names, 'extension-less bat.bing.com beacon must still infer MUID').toContain('MUID');
      expect(names, 'extension-less bat.bing.com beacon must still infer _uetsid').toContain('_uetsid');
    });
  });
});
