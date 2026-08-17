/**
 * Release verification — the AMP consent bridge over live HTTP.
 *
 * These tests exercise the INSTALLED plugin through the two public REST
 * routes (`/faz/v1/amp-consent/check` and `/faz/v1/amp-consent/update`)
 * exactly the way the amp-consent runtime does: POST with a JSON body
 * labelled `text/plain;charset=utf-8` (preflight-free), banner + HMAC scope
 * in the query string, and AMP's CORS security model (AMP-Same-Origin for
 * same-origin requests, Origin + __amp_source_origin for cross-origin ones).
 *
 * Release claims pinned here (from frontend/class-amp-consent-rest.php):
 *  - resolve_context() falls back to 180 days for a banner that never stored
 *    consentExpiry — matching Frontend::get_store_data() — instead of the
 *    old 182-day outlier that gave the same banner two lifetimes.
 *  - shared_data() OMITS `fazExpiresAt` when the deadline is unknown (legacy
 *    cookie with no `exp`), rather than publishing epoch zero as a deadline.
 *  - CORS is fail-closed: a denied request/preflight carries NO
 *    Access-Control-Allow-Origin / AMP-Access-Control-Allow-Source-Origin,
 *    while an allowed one carries the exact documented grant.
 *
 * Scope tokens and the cookie scope fingerprint are HMACs over wp salts, so
 * they are read from the running install via WP-CLI — never recomputed here.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { APIRequestContext, APIResponse } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

const DAY_IN_SECONDS = 86_400;
// Generous slack for wall-clock skew between this process and PHP plus HTTP
// latency. It MUST stay far below one day: the property under test is a
// 2-day (180 vs 182) difference.
const CLOCK_SLACK_SECONDS = 120;

type AmpContext = {
  slug: string;
  law: string;
  scope: string;
  instance: string;
  fingerprint: string;
  revision: number;
  purposes: string[];
  siteOrigin: string;
  bannerControl: boolean;
};

/**
 * Read every server-side secret/fact the bridge derives from wp salts and
 * the active banner. Throws (failing the test) when the installed release
 * ZIP is missing the bridge class or the site has no active banner — both
 * are release defects in this verification context, not skips.
 */
function readAmpContext(): AmpContext {
  const raw = wpEval(`
    if ( ! class_exists( '\\\\FazCookie\\\\Frontend\\\\AMP_Consent_Rest' ) ) {
      throw new Exception( 'AMP consent bridge class missing from the installed plugin.' );
    }
    $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
    if ( ! $banner ) {
      throw new Exception( 'No active banner on the test site.' );
    }
    $slug = sanitize_title( (string) $banner->get_slug() );
    $law  = (string) $banner->get_law();
    if ( '' === $law ) {
      $law = 'gdpr';
    }
    $parts  = wp_parse_url( home_url( '/' ) );
    $origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
    if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
      $origin .= ':' . (int) $parts['port'];
    }
    $settings = get_option( 'faz_settings', array() );
    $purposes = array();
    foreach ( \\FazCookie\\Frontend\\AMP_Consent_Rest::get_purposes() as $purpose ) {
      $purposes[] = $purpose['id'];
    }
    echo wp_json_encode( array(
      'slug'          => $slug,
      'law'           => $law,
      'scope'         => \\FazCookie\\Frontend\\AMP_Consent_Rest::scope_token( $slug ),
      'instance'      => \\FazCookie\\Frontend\\AMP_Consent_Rest::instance_id( $slug ),
      'fingerprint'   => substr( wp_hash( $slug . '|' . $law, 'auth' ), 0, 32 ),
      'revision'      => faz_get_consent_revision(),
      'purposes'      => $purposes,
      'siteOrigin'    => $origin,
      'bannerControl' => is_array( $settings ) && ! empty( $settings['banner_control']['status'] ),
    ) );
  `);
  return JSON.parse(raw) as AmpContext;
}

function ampRouteUrl(
  baseURL: string,
  route: 'check' | 'update',
  ctx: AmpContext,
  extraQuery: Record<string, string> = {},
  style: 'query' | 'index-query' | 'pretty' = 'query',
): string {
  // `?rest_route=` is the portable form and is what the GET/POST cases use.
  // It is NOT usable for the preflight: an OPTIONS against `/?rest_route=…`
  // is answered 405 by the web server before PHP ever runs, so a preflight
  // test written on it never reaches the bridge and indicts the plugin for
  // the vhost's behaviour. The canonical /wp-json/ path answers OPTIONS 200
  // and is the URL a real AMP runtime resolves, so preflight uses that.
  let url = 'pretty' === style
    ? `${baseURL}/wp-json/faz/v1/amp-consent/${route}`
      + `?banner=${encodeURIComponent(ctx.slug)}&scope=${encodeURIComponent(ctx.scope)}`
    : `${baseURL}/${'index-query' === style ? 'index.php' : ''}?rest_route=/faz/v1/amp-consent/${route}`
      + `&banner=${encodeURIComponent(ctx.slug)}&scope=${encodeURIComponent(ctx.scope)}`;
  for (const [key, value] of Object.entries(extraQuery)) {
    url += `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
  }
  return url;
}

/**
 * POST the way the real amp-consent runtime does: JSON payload labelled
 * text/plain so the request stays preflight-free. This deliberately
 * exercises the bridge's hydrate_amp_body() path — the one real AMP uses —
 * not the query-string convenience path.
 */
async function postAmp(
  request: APIRequestContext,
  url: string,
  body: Record<string, unknown>,
  headers: Record<string, string> = {},
): Promise<APIResponse> {
  return request.post(url, {
    headers: {
      'Content-Type': 'text/plain;charset=UTF-8',
      ...headers,
    },
    data: JSON.stringify(body),
    timeout: 60_000,
  });
}

/** Same-origin AMP request marker (viewer on the publisher origin). */
const SAME_ORIGIN_HEADERS = { 'AMP-Same-Origin': 'true' };

function assertPreconditions(ctx: AmpContext): void {
  expect(
    ctx.bannerControl,
    'Precondition: faz_settings.banner_control.status must be enabled on the test site — every consent spec in this suite assumes it.',
  ).toBe(true);
  expect(
    ctx.purposes.length,
    'Precondition: the site must expose at least one optional category as an AMP purpose, or the purpose assertions below are vacuous.',
  ).toBeGreaterThan(0);
}

test.describe('Release verify: AMP consent bridge', () => {
  test('check answers the amp-consent contract shape for a fresh visitor', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);

    // The `request` fixture is a fresh per-test context: no consent cookie
    // travels with this call, so the server MUST answer the fail-closed
    // "no decision on record" shape.
    const res = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      SAME_ORIGIN_HEADERS,
    );
    expect(res.status(), await res.text()).toBe(200);
    const body = await res.json();

    expect(body.consentRequired).toBe(true);
    expect(body.consentStateValue).toBe('unknown');
    // Every configured purpose must be present and denied — both under the
    // plural key the current runtime reads and the singular legacy alias.
    for (const id of ctx.purposes) {
      expect(body.purposeConsents[id], `purposeConsents.${id}`).toBe(false);
      expect(body.purposeConsent[id], `purposeConsent.${id}`).toBe(false);
    }
    // Incoming state 'unknown' must not expire AMP's local cache.
    expect(body.expireCache).toBe(false);
    // sharedData carries the scope facts amp-analytics templates read…
    expect(body.sharedData.fazBanner).toBe(ctx.slug);
    expect(body.sharedData.fazLaw).toBe(ctx.law);
    expect(body.sharedData.fazConsentRevision).toBe(ctx.revision);
    // …and with no decision there is no deadline: the key must be ABSENT,
    // not zero (epoch zero as a deadline is the exact bug this release fixed).
    expect(Object.prototype.hasOwnProperty.call(body.sharedData, 'fazExpiresAt')).toBe(false);
    // No decision on record → nothing to sign into AMP local storage.
    expect(Object.prototype.hasOwnProperty.call(body, 'consentString')).toBe(false);
  });

  test('a banner without a stored consentExpiry yields a 180-day lifetime, not 182', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);
    expect(
      ctx.law,
      'Premise: the fallback under test is the GDPR-family one (180 days); a CCPA banner takes the 365-day fallback and cannot exercise it.',
    ).not.toBe('ccpa');

    // Snapshot the active banner's raw settings longtext — byte-exact via
    // base64, restored verbatim through $wpdb->update (never re-encoded).
    const snapshot = JSON.parse(wpEval(`
      global $wpdb;
      $row = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE slug = %s",
          ${JSON.stringify(ctx.slug)}
        ),
        ARRAY_A
      );
      if ( ! $row ) {
        throw new Exception( 'Active banner row not found for slug ${ctx.slug}.' );
      }
      echo wp_json_encode( array(
        'id'       => (int) $row['banner_id'],
        'settings' => base64_encode( (string) $row['settings'] ),
      ) );
    `)) as { id: number; settings: string };

    try {
      // Remove consentExpiry so resolve_context() must take its fallback,
      // then invalidate every cache the REST path could read through.
      const mutated = wpEval(`
        global $wpdb;
        $settings = json_decode( base64_decode( '${snapshot.settings}' ), true );
        if ( ! is_array( $settings ) ) {
          $settings = array();
        }
        unset( $settings['settings']['consentExpiry'] );
        $wpdb->update(
          "{$wpdb->prefix}faz_banners",
          array( 'settings' => wp_json_encode( $settings ) ),
          array( 'banner_id' => ${snapshot.id} ),
          array( '%s' ),
          array( '%d' )
        );
        if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'banners' );
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
          faz_clear_banner_template_cache();
        }
        $check = json_decode(
          (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_id = %d",
            ${snapshot.id}
          ) ),
          true
        );
        echo isset( $check['settings']['consentExpiry'] ) ? 'still-present' : 'removed';
      `);
      expect(mutated, 'The consentExpiry key must actually be gone or the fallback is never exercised.').toBe('removed');

      // The update route throttles per client IP (3s window). Every test in
      // this suite arrives from the same IP, so clear the throttle bucket to
      // keep this call (and Playwright retries of it) deterministic.
      wpEval(`
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%faz_amp_consent_update%'" );
        if ( wp_using_ext_object_cache() ) {
          wp_cache_flush();
        }
      `);

      const before = Math.floor(Date.now() / 1000);
      const res = await postAmp(
        request,
        ampRouteUrl(wpBaseURL, 'update', ctx),
        {
          consentInstanceId: ctx.instance,
          consentStateValue: 'accepted',
          purposeConsents: Object.fromEntries(ctx.purposes.map((id) => [id, true])),
        },
        SAME_ORIGIN_HEADERS,
      );
      expect(res.status(), await res.text()).toBe(200);
      const body = await res.json();
      const after = Math.floor(Date.now() / 1000);

      expect(body.updated).toBe(true);
      expect(body.consentStateValue).toBe('accepted');
      const expiresAt = body.sharedData?.fazExpiresAt;
      expect(typeof expiresAt, 'an update writes an absolute deadline, so fazExpiresAt must be published').toBe('number');
      // The lifetime must be the canonical 180 days…
      expect(expiresAt).toBeGreaterThanOrEqual(before + 180 * DAY_IN_SECONDS - CLOCK_SLACK_SECONDS);
      expect(expiresAt).toBeLessThanOrEqual(after + 180 * DAY_IN_SECONDS + CLOCK_SLACK_SECONDS);
      // …and specifically NOT the old 182-day outlier (which lands 2 days later).
      expect(expiresAt, 'a 182-day fallback would put the deadline ~2 days past this bound').toBeLessThan(before + 181 * DAY_IN_SECONDS);
    } finally {
      const restored = wpEval(`
        global $wpdb;
        $wpdb->update(
          "{$wpdb->prefix}faz_banners",
          array( 'settings' => base64_decode( '${snapshot.settings}' ) ),
          array( 'banner_id' => ${snapshot.id} ),
          array( '%s' ),
          array( '%d' )
        );
        if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'banners' );
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
          faz_clear_banner_template_cache();
        }
        $reread = (string) $wpdb->get_var( $wpdb->prepare(
          "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_id = %d",
          ${snapshot.id}
        ) );
        echo base64_encode( $reread ) === '${snapshot.settings}' ? 'restored' : 'RESTORE-MISMATCH';
      `);
      expect(restored, 'Banner settings must be restored byte-exact or every later spec inherits a mutated banner.').toBe('restored');
    }
  });

  test('a legacy cookie with no absolute expiry publishes NO fazExpiresAt to AMP', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);

    // A pre-bridge cookie: valid action/rev/scope (so state_from_cookie()
    // accepts it) but WITHOUT the `exp` key bridge-written cookies carry.
    const legacyPairs = [
      'consentid:faz-e2e-amp-legacy',
      'consent:yes',
      'action:yes',
      'necessary:yes',
      `rev:${ctx.revision}`,
      `__scope.banner:${ctx.slug}`,
      `__scope.law:${ctx.law}`,
      `__scope.fp:${ctx.fingerprint}`,
      ...ctx.purposes.map((id) => `${id}:yes`),
    ];
    const legacyCookie = encodeURIComponent(legacyPairs.join(','));

    const res = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      { ...SAME_ORIGIN_HEADERS, Cookie: `fazcookie-consent=${legacyCookie}` },
    );
    expect(res.status(), await res.text()).toBe(200);
    const body = await res.json();

    // FIRST prove the cookie branch was actually taken — the fresh-visitor
    // branch also lacks fazExpiresAt, so without these two assertions the
    // absence check below could pass while the cookie was silently rejected.
    expect(body.consentStateValue, 'the legacy cookie must be recognised as an accepted decision').toBe('accepted');
    for (const id of ctx.purposes) {
      expect(body.purposeConsents[id], `purposeConsents.${id} must reflect the cookie`).toBe(true);
    }
    expect(body.sharedData.fazBanner).toBe(ctx.slug);

    // The fixed contract: an unknown deadline is an ABSENT key — not 0, not
    // null. Epoch zero published as a real deadline was the release bug.
    expect(Object.prototype.hasOwnProperty.call(body.sharedData, 'fazExpiresAt')).toBe(false);
    // And no consentString: the bridge must not invent/sign a deadline the
    // cookie never carried.
    expect(Object.prototype.hasOwnProperty.call(body, 'consentString')).toBe(false);
    // Server and incoming state agree in the "still valid" sense → no expiry
    // of AMP's local cache.
    expect(body.expireCache).toBe(false);
  });

  test('an allowed publisher origin receives the exact documented AMP CORS grant', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);

    const res = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: ctx.siteOrigin }),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      { Origin: ctx.siteOrigin },
    );
    expect(res.status(), await res.text()).toBe(200);

    const headers = res.headers();
    expect(headers['access-control-allow-origin']).toBe(ctx.siteOrigin);
    expect(headers['access-control-allow-credentials']).toBe('true');
    expect(headers['amp-access-control-allow-source-origin']).toBe(ctx.siteOrigin);
    expect(headers['access-control-expose-headers'] ?? '').toContain('AMP-Access-Control-Allow-Source-Origin');
    expect(headers['vary'] ?? '').toContain('Origin');
    // The response is per-visitor consent state: it must never be cacheable.
    expect(headers['cache-control'] ?? '').toContain('no-store');
    // And the grant must be the exact origin, never the reflected wildcard
    // WordPress core would emit for arbitrary REST routes.
    expect(headers['access-control-allow-origin']).not.toBe('*');
  });

  test('a denied origin gets 403 and carries no CORS grant it could have inherited', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);

    // Establish an ALLOWED origin first: within this release the fail-closed
    // claim is that a later denied request can never ride on state an earlier
    // accepted request created.
    const allowed = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: ctx.siteOrigin }),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      { Origin: ctx.siteOrigin },
    );
    expect(allowed.status(), await allowed.text()).toBe(200);
    expect(allowed.headers()['access-control-allow-origin']).toBe(ctx.siteOrigin);

    // A stranger origin with a valid source origin → denied, and the denial
    // must carry NO grant headers at all (absent, not empty).
    const badOrigin = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: ctx.siteOrigin }),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      { Origin: 'https://attacker.example.com' },
    );
    expect(badOrigin.status()).toBe(403);
    const badOriginBody = await badOrigin.json();
    expect(badOriginBody.code).toBe('faz_amp_cors_denied');
    expect(badOrigin.headers()['access-control-allow-origin']).toBeUndefined();
    expect(badOrigin.headers()['amp-access-control-allow-source-origin']).toBeUndefined();

    // The publisher's own origin but a forged source origin → also denied,
    // also grant-free.
    const badSource = await postAmp(
      request,
      ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: 'https://attacker.example.com' }),
      { consentInstanceId: ctx.instance, consentStateValue: 'unknown' },
      { Origin: ctx.siteOrigin },
    );
    expect(badSource.status()).toBe(403);
    const badSourceBody = await badSource.json();
    expect(badSourceBody.code).toBe('faz_amp_source_origin_denied');
    expect(badSource.headers()['access-control-allow-origin']).toBeUndefined();
    expect(badSource.headers()['amp-access-control-allow-source-origin']).toBeUndefined();
  });

  test('preflight OPTIONS obeys the same origin gate on canonical and index.php query dispatch', async ({ request, wpBaseURL }) => {
    const ctx = readAmpContext();
    assertPreconditions(ctx);

    // WordPress core short-circuits every OPTIONS in
    // rest_handle_options_request() before any route callback, so this
    // response is producible ONLY by the bridge's rest_pre_dispatch
    // authorisation (the release fix) — an allowed preflight must carry the
    // exact-origin grant…
    for (const style of ['pretty', 'index-query'] as const) {
      const allowed = await request.fetch(
        ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: ctx.siteOrigin }, style),
        {
          method: 'OPTIONS',
          headers: {
            'Access-Control-Request-Method': 'POST',
            Origin: ctx.siteOrigin,
          },
          timeout: 60_000,
        },
      );
      expect(allowed.status(), `${style}: ${await allowed.text()}`).toBe(200);
      expect(allowed.headers()['access-control-allow-origin'], style).toBe(ctx.siteOrigin);
      expect(allowed.headers()['amp-access-control-allow-source-origin'], style).toBe(ctx.siteOrigin);
      expect(allowed.headers()['access-control-allow-methods'] ?? '', style).toContain('POST');

      // …and a denied preflight — issued immediately after the allowed one —
      // must emit NO grant at all. Before the fix the guard clauses never reset
      // the CORS state, and core's reflected header could survive; either
      // failure mode turns the header below back on.
      const denied = await request.fetch(
        ampRouteUrl(wpBaseURL, 'check', ctx, { __amp_source_origin: ctx.siteOrigin }, style),
        {
          method: 'OPTIONS',
          headers: {
            'Access-Control-Request-Method': 'POST',
            Origin: 'https://attacker.example.com',
          },
          timeout: 60_000,
        },
      );
      // Core still answers the OPTIONS itself (2xx), but stripped of any grant.
      expect(denied.status(), style).toBeLessThan(400);
      expect(denied.headers()['access-control-allow-origin'], style).toBeUndefined();
      expect(denied.headers()['amp-access-control-allow-source-origin'], style).toBeUndefined();
    }
  });
});
