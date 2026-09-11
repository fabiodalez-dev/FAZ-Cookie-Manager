/**
 * E2E — the 1.31.0 migration that stops treating Functional as sale/sharing
 * on installs that never chose it.
 *
 * Installs created before 1.17.2 got sell_personal_data = share_personal_data
 * = 1 on every non-necessary category, Functional included. GPC is enforced
 * under every law and opts visitors out of every flagged category, so on those
 * sites maps and videos never loaded for Brave/Firefox visitors.
 *
 * The migration must separate "never touched" from "an administrator chose
 * this", and the only evidence is the row itself: create_item() writes
 * date_created and date_modified from the same value, update_item() moves
 * date_modified. These checks run against the real MySQL table, because the
 * whole rule lives in one SQL predicate and a stubbed $wpdb would only prove
 * the string.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const T = `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';`;
const MARKER = 'faz_normalize_legacy_functional_optout_done';
// Each case is a first run: the migration is once-per-install by design.
const MIGRATE = `delete_option('${MARKER}');\\FazCookie\\Includes\\Activator::normalize_legacy_functional_optout_flags();`;
const MIGRATE_AGAIN = `\\FazCookie\\Includes\\Activator::normalize_legacy_functional_optout_flags();`;
const CLEAR = `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();`;

function lastLine(out: string): string {
  return out.trim().split('\n').pop() || '';
}

/** Set a row, run the migration, return "<sell><share>". */
function migrate(slug: string, sell: number, share: number, created: string, modified: string): string {
  return lastLine(wpEval(
    T +
    `$wpdb->update($t,array('sell_personal_data'=>${sell},'share_personal_data'=>${share},'date_created'=>'${created}','date_modified'=>'${modified}'),array('slug'=>'${slug}'));` +
    CLEAR + MIGRATE +
    `$r=$wpdb->get_row($wpdb->prepare("SELECT sell_personal_data s,share_personal_data h FROM $t WHERE slug=%s",'${slug}'));` +
    `echo $r->s.$r->h;`,
  ));
}

test.describe('1.31.0 migration: Functional is not sale/sharing unless chosen', () => {
  let snapshot = '';
  let markerBefore = '';

  test.beforeAll(() => {
    markerBefore = lastLine(wpEval(`echo wp_json_encode(get_option('${MARKER}', null));`));
    snapshot = lastLine(wpEval(
      T + `echo wp_json_encode($wpdb->get_results("SELECT slug,sell_personal_data,share_personal_data,date_created,date_modified FROM $t WHERE slug IN ('functional','marketing')",ARRAY_A));`,
    ));
    expect(JSON.parse(snapshot)).toHaveLength(2);
  });

  test.afterAll(() => {
    wpEval(markerBefore === 'null' ? `delete_option('${MARKER}');` : `update_option('${MARKER}', ${markerBefore}, false);`);
    const rows = JSON.parse(snapshot) as Array<Record<string, string>>;
    for (const r of rows) {
      wpEval(
        T + `$wpdb->update($t,array('sell_personal_data'=>${Number(r.sell_personal_data)},'share_personal_data'=>${Number(r.share_personal_data)},` +
        `'date_created'=>'${r.date_created}','date_modified'=>'${r.date_modified}'),array('slug'=>'${r.slug}'));` + CLEAR,
      );
    }
  });

  test('a never-saved legacy Functional row (1/1, equal dates) is cleared', () => {
    expect(migrate('functional', 1, 1, '2024-03-01 10:00:00', '2024-03-01 10:00:00')).toBe('00');
  });

  test('the zero-date shape of the oldest installs is cleared too', () => {
    expect(migrate('functional', 1, 1, '0000-00-00 00:00:00', '0000-00-00 00:00:00')).toBe('00');
  });

  test('a row an administrator saved (dates differ) keeps its flags', () => {
    expect(migrate('functional', 1, 1, '2024-03-01 10:00:00', '2025-01-15 09:30:00')).toBe('11');
  });

  test('a partial flag (sale only) is a deliberate shape and is kept', () => {
    expect(migrate('functional', 1, 0, '2024-03-01 10:00:00', '2024-03-01 10:00:00')).toBe('10');
  });

  test('Marketing is never touched', () => {
    expect(migrate('marketing', 1, 1, '2024-03-01 10:00:00', '2024-03-01 10:00:00')).toBe('11');
  });

  test('it runs once: a later release does not re-run it', () => {
    // run_pending_migrations() replays every migration on each future
    // MIGRATIONS_VERSION bump. A settings import writes rows without their
    // dates, so a Functional 1/1 an administrator imported after this upgrade
    // looks "never saved" — without the marker the next release would reset it.
    expect(migrate('functional', 1, 1, '2024-03-01 10:00:00', '2024-03-01 10:00:00')).toBe('00');
    expect(lastLine(wpEval(`echo (int) get_option('${MARKER}');`))).toBe('1');
    const again = lastLine(wpEval(
      T + `$wpdb->update($t,array('sell_personal_data'=>1,'share_personal_data'=>1,'date_created'=>'0000-00-00 00:00:00','date_modified'=>'0000-00-00 00:00:00'),array('slug'=>'functional'));` +
      CLEAR + MIGRATE_AGAIN +
      `echo $wpdb->get_var("SELECT CONCAT(sell_personal_data,share_personal_data) FROM $t WHERE slug='functional'");`,
    ));
    expect(again, 'an imported 1/1 is left alone once the migration has run').toBe('11');
  });

  test('a save through the category editor path marks the row as chosen', () => {
    // The heuristic is only as good as the claim that a real save moves
    // date_modified. Make the row legacy-shaped, save it through the same
    // Store::save() the REST editor uses, and the migration must then leave
    // the administrator's 1/1 alone.
    const out = lastLine(wpEval(
      T +
      `$wpdb->update($t,array('sell_personal_data'=>1,'share_personal_data'=>1,'date_created'=>'0000-00-00 00:00:00','date_modified'=>'0000-00-00 00:00:00'),array('slug'=>'functional'));` +
      CLEAR +
      `$id=(int)$wpdb->get_var("SELECT category_id FROM $t WHERE slug='functional'");` +
      `$c=new \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Categories($id);$c->set_sell_personal_data(true);$c->save();` +
      CLEAR + MIGRATE +
      `$r=$wpdb->get_row("SELECT sell_personal_data s,share_personal_data h,date_created c,date_modified m FROM $t WHERE slug='functional'");` +
      `echo $r->s.$r->h.'|'.($r->c===$r->m?'same':'moved');`,
    ));
    expect(out).toBe('11|moved');
  });
});
