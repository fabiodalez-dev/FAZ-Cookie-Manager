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


/**
 * Snapshot an option and give back a restorer that puts it back exactly as it
 * was — including "it did not exist", which is a different state from "it was
 * empty" and the one a plain delete cannot express.
 *
 * The first version of these tests just deleted the keys afterwards. On this
 * machine that is harmless (faz-test has no downloaded definitions), which is
 * precisely why it looked fine: on an install that HAD downloaded them, running
 * this spec would have thrown that dataset away and left the site quietly
 * different from how it was found.
 */
async function snapshotOption(name: string): Promise<() => void> {
  const raw = wpEval(
    `$v = get_option( ${JSON.stringify(name)}, null ); echo wp_json_encode( array( "exists" => null !== $v, "value" => $v ) );`
  ).trim();
  const snap = JSON.parse(raw) as { exists: boolean; value: unknown };
  return () => {
    if (!snap.exists) {
      deleteOption(name);
      return;
    }
    wpEval(
      `update_option( ${JSON.stringify(name)}, json_decode( ${JSON.stringify(JSON.stringify(snap.value))}, true ), false );`
    );
  };
}

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
    const restoreDefs = await snapshotOption('faz_cookie_definitions');
    const restoreMeta = await snapshotOption('faz_cookie_definitions_meta');
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
      restoreDefs();
      restoreMeta();
    }
  });

  test('#263 — the System Status definitions row does not pass a bundled snapshot off as a download', async ({
    page,
    loginAsAdmin,
    wpBaseURL,
  }) => {
    // The row keyed on "is there a date?", and after #263 get_meta() always
    // returns one — the download's, or the bundled snapshot's capture date. So
    // the bundled branch became unreachable and a shipped snapshot printed a
    // bare date under "Cookie definitions updated", which reads as the day this
    // site downloaded it. Nothing asserted this row, which is why it could go
    // wrong silently; this is that assertion.
    const restoreDefs = await snapshotOption('faz_cookie_definitions');
    const restoreMeta = await snapshotOption('faz_cookie_definitions_meta');
    try {
      wpEval(`
        update_option("faz_cookie_definitions", array("stale.example"=>array(array("cookie"=>"zzq9137probe","category"=>"Analytics"))), false);
        update_option("faz_cookie_definitions_meta", array("updated_at"=>"2026-07-01 10:00:00","count"=>1,"source"=>"stale"), false);
      `);
      await loginAsAdmin(page);
      await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, {
        waitUntil: 'domcontentloaded',
      });

      const value = await page.$$eval('table tr', (trs) => {
        for (const tr of trs) {
          const c = tr.querySelectorAll('td');
          if (c.length >= 2 && /definitions updated|definizioni/i.test(c[0].textContent || '')) {
            return (c[1].textContent || '').trim();
          }
        }
        return null;
      });

      expect(value, 'no "Cookie definitions updated" row found').not.toBeNull();
      // The lookup answers from the bundle in this state, so the row has to say
      // so. A bare timestamp here is the bug.
      expect(value!).toMatch(/bundled/i);
      expect(value!, 'the row shows the stale download date as if it were current').not.toBe('2026-07-01 10:00:00');
    } finally {
      restoreDefs();
      restoreMeta();
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
    // Only remove the page if THIS test made it. Deleting by slug would destroy
    // a real page that happened to share the name — unlikely, and exactly the
    // kind of unlikely that is unrecoverable when it happens on someone's site.
    const created = wpEval(`
      $p = get_page_by_path("${slug}", OBJECT, "page");
      if ( $p ) { echo "existed"; }
      else {
        wp_insert_post( array( "post_type"=>"page", "post_name"=>"${slug}", "post_title"=>"RC shortcode probe", "post_status"=>"publish", "post_content"=>"[faz_cookie_settings]" ) );
        echo "created";
      }
    `).trim() === 'created';
    try {
      await context.clearCookies();
      await page.goto(`${wpBaseURL}/${slug}/`, { waitUntil: 'domcontentloaded' });

      // Accept first: the defect only appeared once a decision existed, which is
      // why it survived every test that visited the page as a fresh visitor.
      await page.waitForSelector('[data-faz-tag="accept-button"]', { timeout: 15_000 });
      await page.click('[data-faz-tag="accept-button"]');
      await page.waitForTimeout(600);

      // The shortcode's own hook is the data attribute it emits, not a class —
      // the class is user-overridable via the shortcode's `class` attribute, so
      // selecting on it would make this test fail on a legitimate customisation.
      const trigger = page.locator('[data-faz-open-preferences="1"]').first();
      await expect(trigger).toHaveCount(1);
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
      if (created) {
        wpEval(`$p = get_page_by_path("${slug}", OBJECT, "page"); if ( $p ) { wp_delete_post( $p->ID, true ); } echo "ok";`);
      }
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

  /**
   * Scope note, so this is not mistaken for full coverage of #257.
   *
   * The signal logic — analytics denied while performance is granted must yield
   * analytics_storage denied in advanced mode, and granted in basic — cannot be
   * asserted here. window._fazGcm is null on this site because no GCM tag id is
   * configured, and with no tag to signal to the plugin correctly emits nothing.
   * Configuring a fake measurement id would change site-wide state that other
   * specs read.
   *
   * That logic is covered by tests/unit/js/gcm-advanced-mode-analytics.test.mjs,
   * which drives the real gcm.js through real consent cookies in BOTH modes and
   * is mutation-proved. What is left to check at this level — and what a bad
   * merge would actually break — is the settings plumbing that decides whether
   * advanced mode is on at all.
   */
  test('#257 — the Advanced Consent Mode setting still drives is_advanced_mode()', async ({
    page,
    context,
    wpBaseURL,
  }) => {
    const before = wpEval('echo wp_json_encode( get_option("faz_gcm_settings") );').trim();
    try {
      // Turn the feature on rather than skipping. A skipped check is not a
      // passed one, and this RC contains the change it is meant to guard.
      wpEval(`
        $g = get_option( "faz_gcm_settings" );
        if ( ! is_array( $g ) ) { $g = array(); }
        $g["status"] = true;
        $g["advanced_mode"] = true;
        update_option( "faz_gcm_settings", $g );
        if ( class_exists( "FazCookie\\\\Admin\\\\Modules\\\\Gcm\\\\Includes\\\\Gcm_Settings" ) ) {
          FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache();
        }
        echo "ok";
      `);

      const on = wpEval(
        '$s = new FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings(); echo $s->is_advanced_mode() ? "yes" : "no";'
      ).trim();
      expect(on).toBe('yes');

      // Both halves of the gate, or "always true" would pass: advanced_mode is
      // meaningless unless GCM itself is on, and is_advanced_mode() is the AND
      // of the two.
      const offWhenGcmOff = wpEval(`
        $g = get_option( "faz_gcm_settings" ); $g["status"] = false;
        update_option( "faz_gcm_settings", $g );
        FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache();
        $s = new FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings();
        echo $s->is_advanced_mode() ? "yes" : "no";
      `).trim();
      expect(offWhenGcmOff).toBe('no');

      // And the page still renders with GCM configured — a merge that broke the
      // payload assembly would fatal here rather than silently degrade.
      await context.clearCookies();
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="accept-button"]')).toBeVisible({ timeout: 15_000 });
    } finally {
      if (before && before !== 'false' && before !== 'null') {
        setOption('faz_gcm_settings', before);
      } else {
        deleteOption('faz_gcm_settings');
      }
      wpEval(
        'if ( class_exists( "FazCookie\\\\Admin\\\\Modules\\\\Gcm\\\\Includes\\\\Gcm_Settings" ) ) { FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache(); } echo "ok";'
      );
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
    // innerText, not textContent: textContent returns the text of <script>
    // elements too, so it matches the failure strings the progress UI ships as
    // templates and would go red on every build that contains the feature. What
    // matters is whether the admin SEES a failure, which is a rendered-text
    // question.
    const visible = await page.locator('body').innerText();
    expect(visible).not.toMatch(/scan failed|scansione fallita/i);

    const errorBanner = page.locator('.faz-scan-progress .faz-error, .faz-scan-error');
    if ((await errorBanner.count()) > 0) {
      await expect(errorBanner.first()).toBeHidden();
    }
  });
});
