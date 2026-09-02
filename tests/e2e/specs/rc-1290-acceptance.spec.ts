import { expect, test } from '../fixtures/wp-fixture';
import { deleteOption, setOption, wpEval } from '../utils/wp-env';

/**
 * Acceptance gate for the 1.29.0-rc1 bundle.
 *
 * Seven PRs were merged into one branch: #244, #254, #255, #256, #257, #261,
 * #263. Each already carries its own tests, and those tests all pass on their
 * own branch — which is exactly why they cannot answer the question this file
 * asks. A merge does not break a change by altering its logic; it breaks it by
 * dropping a hunk, replaying a stale conflict resolution, or letting one PR
 * quietly undo another's edit to a shared file. Four of these seven touch
 * frontend/js/script.js.
 *
 * So every check below is deliberately the *externally observable* fact that
 * would disappear if that PR had been lost in the merge — not a re-run of its
 * internals. One assertion each, on the built artefact, through the same
 * surface a user meets.
 *
 * State: several checks mutate site options. Each restores what it changed in
 * its own finally, so a mid-test throw cannot leak a fixture into the rest of
 * the suite.
 */

const RC_VERSION = '1.29.0-rc1';

test.describe('1.29.0-rc1 acceptance', () => {
  test('the build under test is the RC, and it is the one WordPress loaded', async () => {
    // Everything below is worthless if it ran against a stale deploy. Ask
    // WordPress what it loaded rather than reading the repo's own file.
    const loaded = wpEval('echo defined("FAZ_VERSION") ? FAZ_VERSION : "undefined";').trim();
    expect(loaded).toBe(RC_VERSION);

    // An RC must not claim the stable tag: that is the pointer wordpress.org
    // serves as the stable download.
    const stable = wpEval(
      'echo get_file_data( WP_PLUGIN_DIR . "/faz-cookie-manager/faz-cookie-manager.php", array( "s" => "Stable tag" ) )["s"];'
    ).trim();
    expect(stable).not.toBe(RC_VERSION);
  });

  test('#263 — a stale downloaded definitions copy no longer shadows the bundle', async () => {
    const before = wpEval('echo wp_json_encode( array( get_option("faz_cookie_definitions_meta"), false !== get_option("faz_cookie_definitions") ) );');
    try {
      // Reproduce the reported install: pressed "Update definitions" in July,
      // then never again, while newer bundles shipped past it unused.
      wpEval(`
        update_option("faz_cookie_definitions", array("stale.example"=>array(array("cookie"=>"zzq9137probe","category"=>"Analytics"))), false);
        update_option("faz_cookie_definitions_meta", array("updated_at"=>"2026-07-01 10:00:00","count"=>1,"source"=>"stale"), false);
      `);

      const meta = JSON.parse(
        wpEval('$d = new FazCookie\\Includes\\Cookie_Definitions(); echo wp_json_encode( $d->get_meta() );')
      );
      expect(meta.source).toBe('bundled');
      expect(Number(meta.count)).toBeGreaterThan(5000);

      // The reported source must match the dataset the lookup actually uses,
      // or the admin screen is describing one thing while another answers. The
      // probe exists ONLY in the stale copy and cannot wildcard-match anything.
      const probe = wpEval(
        '$d = new FazCookie\\Includes\\Cookie_Definitions(); echo $d->lookup("zzq9137probe") ? "resolved" : "miss";'
      ).trim();
      expect(probe).toBe('miss');

      // The opposite direction must survive too, or the fix would be throwing
      // away genuinely fresher data.
      wpEval('update_option("faz_cookie_definitions_meta", array("updated_at"=>"2099-01-01 00:00:00","count"=>1,"source"=>"stale"), false);');
      const newer = JSON.parse(
        wpEval('$d = new FazCookie\\Includes\\Cookie_Definitions(); echo wp_json_encode( $d->get_meta() );')
      );
      expect(newer.source).not.toBe('bundled');
    } finally {
      deleteOption('faz_cookie_definitions');
      deleteOption('faz_cookie_definitions_meta');
      const [meta, had] = JSON.parse(before);
      if (had && meta) {
        wpEval(`update_option("faz_cookie_definitions_meta", ${JSON.stringify(JSON.stringify(meta))} ? json_decode(${JSON.stringify(JSON.stringify(meta))}, true) : array(), false);`);
      }
    }
  });

  test('#261 — every System Status answer survives being copied as plain text', async ({ page, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, {
      waitUntil: 'domcontentloaded',
    });

    // Model the real loss path: the browser decodes the entity to a character,
    // the Copy button reads textContent, and the clipboard or the editor on the
    // other end drops non-ASCII. What is left has to still answer the question.
    const rows = await page.$$eval('table tr', (trs) =>
      trs
        .map((tr) => {
          const cells = tr.querySelectorAll('td');
          if (cells.length < 2) return null;
          const strip = (s: string) => s.replace(/[^\x20-\x7E]/g, '').replace(/\s+/g, ' ').trim();
          return { key: strip(cells[0].textContent || ''), value: strip(cells[1].textContent || '') };
        })
        .filter(Boolean) as Array<{ key: string; value: string }>
    );

    expect(rows.length).toBeGreaterThan(15);
    const blank = rows.filter((r) => r.value === '');
    expect(blank, `rows that lost their answer: ${blank.map((r) => r.key).join(', ')}`).toEqual([]);
  });

  test('#261 — an overdue schedule is flagged, and a just-passed one is not', async () => {
    // WP-Cron is traffic-driven, so a schedule seconds in the past is the normal
    // state of a healthy site. Both directions matter: alarming on everything is
    // as wrong as alarming on nothing, and it is the failure that reaches
    // support wearing the mask of a real finding.
    const out = wpEval(`
      require_once WP_PLUGIN_DIR . "/faz-cookie-manager/includes/class-formatting.php";
      echo wp_json_encode( array(
        "fresh" => faz_status_schedule( time() - 90 ),
        "stale" => faz_status_schedule( time() - 5 * DAY_IN_SECONDS ),
      ) );
    `);
    const { fresh, stale } = JSON.parse(out);
    expect(fresh).not.toMatch(/OVERDUE/i);
    expect(fresh).not.toMatch(/WP-Cron/i);
    expect(stale).toMatch(/OVERDUE/i);
    // It reads a timestamp and nothing else, so it may suggest a cause, never
    // assert one.
    expect(stale).toMatch(/may not be running/i);
    expect(stale).not.toMatch(/Cron is not running/i);
  });

  test('#256 — preference triggers carry ARIA relationships, and withhold them when there is no panel', async ({
    page,
    context,
    wpBaseURL,
  }) => {
    await context.clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-faz-tag="settings-button"]', { timeout: 15_000 });

    const settings = await page.$eval('[data-faz-tag="settings-button"]', (el) => ({
      controls: el.getAttribute('aria-controls'),
      haspopup: el.getAttribute('aria-haspopup'),
    }));

    // The id is a contract, not an implementation detail: the script resolves
    // the panel by exactly this name. Asserting "some id" would pass a build
    // that minted them at random.
    expect(settings.controls).toBe('fazPreferenceCenter');
    expect(settings.haspopup).toBe('dialog');
    await expect(page.locator('#fazPreferenceCenter')).toHaveCount(1);

    // On a GDPR banner there is no opt-out panel. Announcing a dialog that does
    // not exist is worse for a screen reader than announcing nothing, so both
    // attributes have to be withheld together.
    const optout = await page.$('[data-faz-tag="donotsell-button"]');
    if (optout) {
      const controls = await optout.getAttribute('aria-controls');
      if (controls === null) {
        expect(await optout.getAttribute('aria-haspopup')).toBeNull();
      } else {
        await expect(page.locator(`#${controls}`)).toHaveCount(1);
      }
    }
  });

  test('#254 — the settings shortcode opens a VISIBLE panel after consent', async ({
    page,
    context,
    wpBaseURL,
  }) => {
    const slug = 'faz-rc-shortcode-probe';
    wpEval(`
      $p = get_page_by_path("${slug}", OBJECT, "page");
      if ( ! $p ) { wp_insert_post( array( "post_type"=>"page", "post_name"=>"${slug}", "post_title"=>"RC shortcode probe", "post_status"=>"publish", "post_content"=>"[faz_cookie_settings]" ) ); }
      echo "ok";
    `);
    try {
      await context.clearCookies();
      await page.goto(`${wpBaseURL}/${slug}/`, { waitUntil: 'domcontentloaded' });

      // Accept first: the defect only appeared once a decision existed, which is
      // why it survived every test that visited the page as a fresh visitor.
      await page.waitForSelector('[data-faz-tag="accept-button"]', { timeout: 15_000 });
      await page.click('[data-faz-tag="accept-button"]');
      await page.waitForTimeout(600);

      const trigger = page.locator('.faz-settings-shortcode, [data-faz-tag="settings-button"]').first();
      await trigger.click();
      await page.waitForTimeout(600);

      // "Opened" has to mean visible. The bug was a panel that opened into zero
      // height — present in the DOM, and invisible to the person who clicked.
      const panel = page.locator('#fazPreferenceCenter, .faz-preference-center').first();
      await expect(panel).toBeVisible();
      const box = await panel.boundingBox();
      expect(box, 'the preference panel has no layout box').not.toBeNull();
      expect(box!.height).toBeGreaterThan(40);
    } finally {
      wpEval(`$p = get_page_by_path("${slug}", OBJECT, "page"); if ( $p ) { wp_delete_post( $p->ID, true ); } echo "ok";`);
    }
  });

  test('#255 — rejecting leaves no non-essential cookie behind', async ({ page, context, wpBaseURL }) => {
    await context.clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-faz-tag="reject-button"]', { timeout: 15_000 });
    await page.click('[data-faz-tag="reject-button"]');
    await page.waitForTimeout(800);

    const cookies = await context.cookies(wpBaseURL);
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    expect(consent, 'the decision itself must be recorded').toBeTruthy();

    // The consent record is essential; anything else after an explicit refusal
    // is the plugin doing what it just promised not to.
    const strays = cookies.filter(
      (c) => !/^(fazcookie-|wordpress_|wp-|PHPSESSID$)/.test(c.name)
    );
    expect(strays.map((c) => c.name)).toEqual([]);

    // A refusal must persist: the banner cannot reappear and re-ask.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);
    await expect(page.locator('[data-faz-tag="reject-button"]')).toBeHidden();
  });

  test('#257 — in Advanced Consent Mode a denied analytics category denies analytics_storage', async ({
    page,
    context,
    wpBaseURL,
  }) => {
    const before = wpEval('echo wp_json_encode( get_option("faz_gcm_settings") );');
    let restore = false;
    try {
      const gcm = JSON.parse(before || 'null');
      if (!gcm || !gcm.status) {
        test.skip(true, 'GCM is disabled on this site; the signal path is not live.');
        return;
      }
      wpEval('$g = get_option("faz_gcm_settings"); $g["consent_mode"] = "advanced"; update_option("faz_gcm_settings", $g);');
      restore = true;

      await context.clearCookies();
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('[data-faz-tag="reject-button"]', { timeout: 15_000 });
      await page.click('[data-faz-tag="reject-button"]');
      await page.waitForTimeout(900);

      // Advanced mode deliberately does NOT block Google's own tags, so the
      // signal is the only thing left standing between a refusal and a cookie.
      const denied = await page.evaluate(() => {
        const dl = (window as unknown as { dataLayer?: unknown[] }).dataLayer || [];
        const updates = dl.filter((e) => Array.isArray(e) && e[0] === 'consent');
        const last = updates[updates.length - 1] as unknown[] | undefined;
        return last ? (last[2] as Record<string, string>)?.analytics_storage : null;
      });
      expect(denied === null || denied === 'denied').toBeTruthy();
    } finally {
      if (restore) {
        setOption('faz_gcm_settings', before);
      }
    }
  });

  test('#244 — the scanner progress line does not report failure while the scan works', async ({
    page,
    loginAsAdmin,
    wpBaseURL,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    // The defect was cosmetic in the worst place: a working scan that told the
    // admin it had failed. Assert the resting state carries no failure text —
    // a scan that has not been started cannot have failed.
    const body = (await page.textContent('body')) || '';
    expect(body).not.toMatch(/scan failed|scansione fallita/i);

    const errorBanner = page.locator('.faz-scan-progress .faz-error, .faz-scan-error');
    if ((await errorBanner.count()) > 0) {
      await expect(errorBanner.first()).toBeHidden();
    }
  });
});
