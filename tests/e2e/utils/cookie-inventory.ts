/**
 * Temporary control over the contents of wp_faz_cookies.
 *
 * The auto-detect specs (cookie-policy-service-auto-detect, gvl-vendor-auto-detect)
 * assert things about the WHOLE inventory: the suggestion is empty, the split
 * between already_selected and newly_suggested is exactly this, the UI reports
 * no matches. Their beforeEach only deletes their OWN fixture slugs, so those
 * assertions silently also claim that nothing else in the table maps to a known
 * service or vendor. That holds on a fresh install and stops holding as soon as
 * a scanner or provider spec has run earlier in the same suite — at which point
 * the failure surfaces as "manually-added cookies leaked into the suggestion",
 * pointing at a cause unrelated to what actually went wrong.
 *
 * This lives in utils rather than in either spec because both need it and it
 * has already drifted once: cookie-policy-service-auto-detect carried two
 * hand-written copies of the same snapshot/wipe/restore, with no way to stay in
 * step, while eight of its tests that needed the same treatment never got it.
 *
 * Both helpers are only safe while the caller holds the cookies-table mutex
 * (see utils/db-lock), which both specs acquire for their whole run.
 */
import { wpEval } from './wp-env';

function snapshotAndDelete(where: string): string {
  const backup = wpEval(
    `global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}faz_cookies WHERE ${where}", ARRAY_A));`,
  ).trim();
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE ${where}");`);
  return backup;
}

function restore(backup: string): void {
  if (!backup || backup === '[]' || backup === 'null') {
    return;
  }
  // The rows are re-inserted with their original cookie_id — the delete above
  // freed it, so nothing collides and any id another spec is holding survives.
  wpEval(`
    global $wpdb;
    $rows = json_decode(${JSON.stringify(backup)}, true);
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $wpdb->insert($wpdb->prefix . 'faz_cookies', $row);
      }
    }
  `);
}

async function withoutCookies<T>(where: string, fn: () => Promise<T>): Promise<T> {
  const backup = snapshotAndDelete(where);
  try {
    return await fn();
  } finally {
    restore(backup);
  }
}

/**
 * Run `fn` with only the caller's own fixture rows visible.
 *
 * @param ownSlugPrefix The spec's fixture slug prefix, e.g. 'cp-auto-'.
 */
export function withOwnCookiesOnly<T>(ownSlugPrefix: string, fn: () => Promise<T>): Promise<T> {
  if (!/^[a-z0-9_-]+$/i.test(ownSlugPrefix)) {
    throw new Error(`Refusing to build a LIKE clause from ${JSON.stringify(ownSlugPrefix)}`);
  }
  return withoutCookies(`discovered = 1 AND slug NOT LIKE '${ownSlugPrefix}%'`, fn);
}

/** Run `fn` with no discovered rows at all — the "scanner never ran" path. */
export function withNoDiscoveredCookies<T>(fn: () => Promise<T>): Promise<T> {
  return withoutCookies('discovered = 1', fn);
}
