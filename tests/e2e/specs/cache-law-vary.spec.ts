/**
 * Per-law page-cache varying (opt-in).
 *
 * FAZ vetoes full-page caching whenever the jurisdiction runtime is on, because
 * the rendered page varies by law. This feature lets a cache keep two entries
 * instead — opt-in and opt-out — keyed on a `faz-law` cookie, and lifts the veto
 * only while that separation is genuinely in place.
 *
 * What these five tests protect is not "does it cache". It is the safety
 * property underneath: a request that cannot prove its jurisdiction must never
 * be served, or stored as, the relaxed variant. Getting that wrong serves a
 * European visitor a cached page whose trackers are already unblocked, which is
 * worse than never caching at all — so each test below is written to go red on
 * exactly that failure, not merely to observe the happy path.
 *
 * SCOPE, stated plainly: these three lock the OPT-IN BOUNDARY — default-off
 * behaviour, the fail-closed readiness gate, and the shredder exemption. They
 * do NOT yet prove the variant-purity property (that a cookie-less request can
 * never receive the relaxed render). I wrote two tests for that, watched them
 * pass, then inverted the trust default from 'gdpr' to 'ccpa' and they stayed
 * green: on a site with a single opt-in banner the relaxed variant cannot be
 * produced at all, so the assertion was true either way. Vacuous coverage of a
 * safety property is worse than none, so they are not here. That gap needs a
 * fixture with both an opt-in and an opt-out banner and a marker that actually
 * differs between the two renders.
 *
 * The cache write happens on a FlyingPress *preload* request originating from
 * the server (Preload::process_single_url), which carries no visitor IP and no
 * headers — only the registered include-cookies. That is why the cookie is the
 * whole mechanism and why request geolocation cannot be trusted on that path.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval, isPluginActive, wp } from '../utils/wp-env';

const OPTIN_MU = 'faz-e2e-law-vary-optin.php';

let weActivatedFlyingPress = false;

/** Read a private Frontend method through reflection, in the real WP context. */
function privateCall(method: string, extra = ''): string {
  return wpEval(`
    ${extra}
    $f = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.27.0' );
    $m = new ReflectionMethod( $f, '${method}' );
    $m->setAccessible( true );
    echo var_export( $m->invoke( $f ), true );
  `).trim();
}

function writeMu(name: string, php: string): void {
  wpEval(`file_put_contents( WP_CONTENT_DIR . '/mu-plugins/${name}', <<<'PHPEOF'\n${php}\nPHPEOF );`);
}

function removeMu(name: string): void {
  wpEval(`@unlink( WP_CONTENT_DIR . '/mu-plugins/${name}' );`);
}

test.beforeAll(() => {
  if (!isPluginActive('flying-press')) {
    try {
      wp(['plugin', 'activate', 'flying-press']);
      weActivatedFlyingPress = isPluginActive('flying-press');
    } catch {
      /* not installed on this box — the cache-dependent tests skip below */
    }
  }
  if (isPluginActive('flying-press')) {
    // The drop-in is what actually reads the cookie; without it the feature
    // must refuse to engage, which is test 2's subject.
    wpEval(`if ( class_exists( '\\\\FlyingPress\\\\AdvancedCache' ) ) { \\FlyingPress\\AdvancedCache::add_advanced_cache(); }`);
  }
});

test.afterAll(() => {
  removeMu(OPTIN_MU);
  try {
    wpEval(`if ( class_exists( '\\\\FlyingPress\\\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }`);
  } catch {
    /* best-effort */
  }
  if (weActivatedFlyingPress) {
    // Leave it off: its page cache breaks unrelated specs.
    try {
      wp(['plugin', 'deactivate', 'flying-press']);
    } catch {
      /* best-effort */
    }
  }
});

test.describe('Per-law cache varying', () => {
  test('1 — default off: the veto stands and no faz-law cookie is issued', async ({ request, wpBaseURL }) => {
    removeMu(OPTIN_MU);

    expect(privateCall('is_law_vary_active'), 'inactive without the opt-in filter').toBe('false');

    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`);
    const setCookie = (res.headersArray() || [])
      .filter((h) => h.name.toLowerCase() === 'set-cookie')
      .map((h) => h.value)
      .join('; ');

    expect(setCookie, 'no jurisdiction cookie on a default install').not.toContain('faz-law');
    expect(
      (res.headers()['cache-control'] || '').toLowerCase(),
      'the page-cache bypass is still asserted',
    ).toContain('no-store');
  });

  test('2 — fail-closed: opting in is not enough while the cookie is not baked into the drop-in', () => {
    // The drop-in bakes its include-cookies list at write time and runs before
    // plugins load. If faz-law is not in that baked list the cache cannot tell
    // the variants apart, so engaging would let one entry answer both
    // jurisdictions. Asking for the feature must not be sufficient.
    writeMu(OPTIN_MU, `<?php add_filter( 'faz_cache_vary_by_law', '__return_true' );`);

    const ready = privateCall('flying_press_law_vary_ready');
    const active = privateCall('is_law_vary_active');

    // Whatever the box's state, the two must agree: never active while not ready.
    expect(
      ready === 'false' ? active : 'false',
      'is_law_vary_active() must never outrun flying_press_law_vary_ready()',
    ).toBe('false');
  });

  test('5 — faz-law is exempt from the cookie shredder', () => {
    // The shredder deletes cookies belonging to refused categories. Shredding
    // the jurisdiction cookie would silently drop every visitor back to the
    // no-cookie variant, which is the one thing that makes the feature inert
    // rather than wrong — and inert-but-wired is what this whole area keeps
    // producing.
    const allowed = wpEval(`
      $f = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.27.0' );
      $m = new ReflectionMethod( $f, 'is_cookie_allowed' );
      $m->setAccessible( true );
      echo $m->invoke( $f, 'faz-law' ) ? 'yes' : 'no';
    `).trim();

    expect(allowed, 'faz-law must be on the never-shred list').toBe('yes');
  });
});
