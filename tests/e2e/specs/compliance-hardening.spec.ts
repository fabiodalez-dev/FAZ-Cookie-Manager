import { expect, test } from '../fixtures/wp-fixture';
import { deactivatePluginsExcept, wpEval } from '../utils/wp-env';

/**
 * Browser-observable compliance checks for the salvage-on-1.17.2 pass.
 *
 * main 1.17.2 already ships GPC honoring, the 182-day expiry cap and
 * equal-weight buttons (with their own tests), so this file only covers the
 * genuinely-novel browser-observable fix carried over on top of main:
 *
 *   - Cookie Policy heading exposes aria-level. wp_kses_post() strips
 *     aria-level while keeping role="heading", leaving the category-name spans
 *     as headings with no level — an axe-critical WCAG 4.1.2 failure. The fix
 *     sanitizes with a post allowlist extended by role + aria-level.
 *
 * The DNSMPI opt-out enforcement and the trusted-proxy CIDR allowlist are
 * verified at the PHP level (tests/unit/test-compliance-hardening.php and the
 * reflection check in the PR), where they are deterministic and fast.
 */

test.describe('Compliance hardening — Cookie Policy accessibility', () => {
  let policyUrl = '';

  test.beforeAll(() => {
    deactivatePluginsExcept(['faz-cookie-manager']);
    policyUrl = wpEval(`
      $existing = get_page_by_path( 'faz-compliance-policy-test' );
      if ( $existing ) {
        $id = $existing->ID;
      } else {
        $id = wp_insert_post( array(
          'post_title'   => 'FAZ Compliance Policy Test',
          'post_name'    => 'faz-compliance-policy-test',
          'post_status'  => 'publish',
          'post_type'    => 'page',
          'post_content' => '[faz_cookie_policy_complete]',
        ) );
      }
      echo get_permalink( $id );
    `).trim();
  });

  test('category-name heading exposes aria-level (WCAG 4.1.2 / axe-critical)', async ({ page }) => {
    expect(policyUrl).toMatch(/^https?:\/\//);
    await page.goto(policyUrl, { waitUntil: 'domcontentloaded' });

    const headings = page.locator('.faz-cookie-policy-category-name[role="heading"]');
    const count = await headings.count();
    expect(count, 'cookie policy must render category headings').toBeGreaterThan(0);

    // Every heading-role span must carry an aria-level (the kses fix).
    for (let i = 0; i < count; i++) {
      const level = await headings.nth(i).getAttribute('aria-level');
      expect(level, 'role="heading" must carry aria-level').toBeTruthy();
      expect(Number(level)).toBeGreaterThan(0);
    }
  });

  test('no heading-role element is left without aria-level', async ({ page }) => {
    await page.goto(policyUrl, { waitUntil: 'domcontentloaded' });
    const orphanHeadings = await page.evaluate(() => {
      const nodes = Array.from(document.querySelectorAll('[role="heading"]'));
      return nodes.filter((n) => !n.hasAttribute('aria-level')).length;
    });
    expect(orphanHeadings, 'every ARIA heading must declare its level').toBe(0);
  });
});
