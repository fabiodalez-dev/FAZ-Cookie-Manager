import { test, expect } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Regression guard for the non-English banner blanking bug (1.19.0 build).
 *
 * When the banner's default language is a non-English locale (e.g. Italian)
 * but the stored content only has an `en` entry, the banner must render the
 * bundled `{lang}.json` translation — NOT a blank title / blank button labels.
 *
 * The bug: `default.json` carried non-empty optout-success defaults, which made
 * Banner::array_empty_assoc() never report a language as blank, so the
 * get_translations() fallback stopped firing; and
 * apply_runtime_law_content_compatibility() partially filled blank languages
 * (description only), defeating the same fallback. The title and button labels
 * rendered EMPTY on every non-English locale while the description survived.
 *
 * EVERY other E2E + the compliance suite runs in en_US (stored `en` == active
 * `en`), so the translation path was never exercised — this spec closes that gap.
 */

const WP_PATH = process.env.WP_PATH || '';

/** Run PHP through wp-cli without a shell (so `$` / quotes are literal). */
function wpEval(php: string): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, 'eval', php], { encoding: 'utf8' }).trim();
}

/** Expected Italian notice copy straight from the bundled translation. */
function bundledItalianNotice(): { title: string } {
  const p = fileURLToPath(
    new URL('../../../admin/modules/banners/includes/contents/it.json', import.meta.url),
  );
  const d = JSON.parse(readFileSync(p, 'utf8')) as {
    gdpr: { notice: { elements: { title: string } } };
  };
  return { title: d.gdpr.notice.elements.title };
}

test.describe('Banner i18n — non-English locale renders translated copy', () => {
  test.skip(!WP_PATH, 'requires WP_PATH for the wp-cli language switch');

  test.beforeAll(() => {
    // Back up the current language config in a transient (no fragile shell
    // round-trip of JSON), then force default=it / selected=[en,it].
    wpEval(
      'set_transient("faz_e2e_lang_backup", wp_json_encode((get_option("faz_settings", array())["languages"] ?? "__none__")), 3600);',
    );
    wpEval(
      '$s = get_option("faz_settings", array()); $s["languages"] = array("selected" => array("en","it"), "default" => "it"); update_option("faz_settings", $s); delete_option("faz_banner_template");',
    );
  });

  test.afterAll(() => {
    wpEval(
      '$b = get_transient("faz_e2e_lang_backup"); $d = json_decode($b, true); $s = get_option("faz_settings", array()); if ("__none__" === $d) { unset($s["languages"]); } else { $s["languages"] = $d; } update_option("faz_settings", $s); delete_transient("faz_e2e_lang_backup"); delete_option("faz_banner_template");',
    );
  });

  test('server-rendered banner copy is the bundled Italian, not blank', async ({ request }) => {
    // Test the RAW server HTML, not the post-JS DOM: the banner uses
    // client-side language resolution (the script swaps text to the visitor's
    // BROWSER language), but the regression was in the SERVER render for the
    // non-English default. Reading the response body targets exactly that and
    // is deterministic (no browser-locale / timing dependence). Cache-bust so
    // no page cache masks the freshly-regenerated template.
    const res = await request.get(`/?faz_i18n_e2e=${Date.now()}`);
    expect(res.ok()).toBeTruthy();
    const html = await res.text();

    // String-scan (no dynamic RegExp → no ReDoS surface): find the tagged
    // element's opening `>` and read up to the next closing tag.
    const read = (tag: string): string | null => {
      const at = html.indexOf(`data-faz-tag="${tag}"`);
      if (at < 0) return null;
      const open = html.indexOf('>', at);
      if (open < 0) return null;
      const close = html.indexOf('</', open);
      const inner = close < 0 ? html.slice(open + 1) : html.slice(open + 1, close);
      return inner.replace(/<[^>]+>/g, '').trim();
    };
    const title = read('title');
    const description = read('description');
    const settings = read('settings-button');

    // The regression rendered these EMPTY on a non-English locale.
    expect(title, 'banner title must not be blank on a non-English locale').toBeTruthy();
    expect(description, 'banner description must not be blank').toBeTruthy();
    expect(settings, 'Customize button label must not be blank').toBeTruthy();

    // And the bundled translation actually applied (not the English default,
    // not an empty string) — proves the get_translations() fallback fired.
    expect(title).toBe(bundledItalianNotice().title);
    expect(title).not.toBe('We value your privacy');
  });
});
