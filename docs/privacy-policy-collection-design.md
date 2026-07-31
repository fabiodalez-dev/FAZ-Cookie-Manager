# Privacy Policy — collecting what plugins declare about themselves

Written 2026-07-31, after verifying the mechanism against WordPress core and against the plugins
installed on the faz-test site. This document is the rationale behind
`admin/modules/privacy-policy-generator/includes/class-content-collector.php`. It exists mostly for
one reason: **the admin-only constraint recorded below is not obvious, looks like an arbitrary
restriction, and lifting it fatals sites running WooCommerce.** Read the constraint section before
changing anything about when collection runs.

It also revises §P0a of the legal-documents generator plan (`docs/legal-documents-generator-plan.md`,
landing on its own branch), which assumed a different and more dangerous approach.

## The correction this makes to the plan

The plan assumed the Privacy Policy would need reviewed legal text, authored by us, describing what a
site processes — for four jurisdictions across eight languages. It flagged that as risk R1 (legal) and
R6 (editorial quality). That framing was wrong in an important way.

**We should not author claims about what a site processes. We should collect the claims each installed
plugin already makes about itself.** WordPress has had the mechanism since 4.9.6:

- Producers call `wp_add_privacy_policy_content( $plugin_name, $policy_text )`.
- `WP_Privacy_Policy_Content::get_suggested_policy_text()` returns every registered contribution.

FAZ is already a *producer* — `includes/class-cli.php` registers its own consent-log disclosure. This
makes it a *consumer* too, which is the symmetric and obvious move.

On the faz-test install alone, six third-party plugins already declare content: WooCommerce, LiteSpeed
Cache, Akismet, Burst Statistics, WP Statistics and Beehive Analytics. Each block is written by that
plugin's own author about their own processing — which is both more accurate than anything we could
write and, legally, their statement rather than ours.

This substantially lowers R1. The generated document becomes *the site's own installed software
describing itself*, plus the operator's own supplied facts, plus jurisdiction-fixed rights text (which
is law, not a claim about the site). We are not inventing processing descriptions.

## Hard constraint — collection is admin-only

`wp_add_privacy_policy_content()` calls `_doing_it_wrong()` unless it runs in wp-admin on `admin_init`
or later; the guard was added in 4.9.7 and lives in `wp-includes/functions.php`. Producers therefore
register their content on `admin_init`, which means **that content does not exist at all in any other
context** — not on the frontend, not under WP-CLI, not in a REST request.

Verified the hard way. Forcing `do_action( 'admin_init' )` from WP-CLI, to "warm up" the producers
before reading them, produced six `_doing_it_wrong` notices and then a **fatal inside WooCommerce**:
`Automattic\WooCommerce\Internal\Orders\OrderAttributionController::get_order_screen_id()` calls
`wc_get_page_screen_id()`, which is undefined outside a real admin request.

So the architecture is fixed, and the fix is structural rather than defensive:

1. **Collect only inside a genuine wp-admin page request.** Never from the frontend, never from
   WP-CLI, never from REST or cron.
2. **Snapshot into a plugin option** (`faz_privacy_content_snapshot`).
3. **Render the public document from the snapshot**, never from a live collection.

Point 3 is not just a consequence of point 1. It is independently required by Cache Compatibility Mode
(1.21.0): a live collection at render time would make the rendered output depend on request context,
which is exactly the per-visitor variance that release eliminated.

### Why `current_screen` and not `admin_init`

`admin_init` is the obvious hook and the wrong one. It also fires on `admin-ajax.php`, and it can be
force-fired from the CLI — the exact path that fatals WooCommerce.

`current_screen` is reached only through `set_current_screen()`, which runs in genuine wp-admin page
requests and nowhere else. It also fires *after* `admin_init`, so every producer registered at default
priority — including FAZ's own — has already contributed by the time collection runs. The context is
therefore correct by construction rather than by assertion; the explicit denials inside
`maybe_collect()` (ajax, cron, REST, CLI, `is_admin()`, `did_action( 'admin_init' )`) are belt and
braces for anyone who calls the method directly.

### Why only FAZ's own screens

Collection is further restricted to screens whose id contains `faz-cookie-manager`. Running it on every
wp-admin pageview sitewide would perform the read — and risk an option write — forever, in order to
keep fresh a snapshot that only FAZ's document consumes. Restricting it to FAZ screens refreshes the
snapshot exactly when the operator is looking at the plugin, including (once it exists) the
privacy-policy editor screen itself, which lands on a `faz-cookie-manager-*` slug and therefore always
opens against freshly collected data.

Even on FAZ screens, an unchanged collection is a pure read: one non-autoloaded option get and an
in-memory comparison. The option is written only when the diff reports a material change, and
`collected_at` does not move otherwise.

## Everything is an editable placeholder

The same semantics already shipped for the Cookie Policy in `Section_Overrides`, reused rather than
reinvented:

- Each collected block is a section. Identity matching is deliberately ordered: exact display
  name + source hash first; hash-only rename carry-forward only when that hash is unique on both
  sides; then name-only rewrite carry-forward, again only when unique on both sides. This prevents
  two plugins that publish identical boilerplate from exchanging ids and operator overrides when
  WordPress returns them in a different order.
- The collected text is the **placeholder**, not the saved value. An empty override box means "keep
  receiving updates".
- An operator who rewrites a block keeps their wording; the block stops tracking upstream. The anchor
  for *that* decision is the `source_hash` as it stood when the override was saved.
- Placeholders such as `{{COMPANY_NAME}}` keep resolving inside operator-authored text, through the
  same substitution stage as the Cookie Policy, so there is no second escaping path.

The one genuinely new behaviour versus the Cookie Policy: **the upstream source moves.** A plugin can
change or withdraw its declaration. That gives three states per block:

| state | untouched block | operator-edited block |
|---|---|---|
| plugin updated its text | silently adopts the new text | keeps operator wording, flags "the source changed — review" (`stale`) |
| plugin deactivated/removed | block drops out | kept, flagged "the plugin that declared this is gone" (`orphaned`) |
| new plugin activated | block appears | n/a |

The "flag, never silently overwrite" rule is the same judgement as the Cookie Policy's anchor-drift
fallback: an explicit editorial decision by the operator is the most specific source there is, and an
upstream change must never quietly replace it.

`stale` and `orphaned` are derived at read time in `describe()` and deliberately never stored. A
derived flag cannot desynchronise from the data it describes; a stored copy can. Same reason
`Section_Overrides::describe()` derives `active` instead of persisting it.

## Why FAZ keeps its own snapshot and its own diff

Core computes `added` / `updated` / `removed` itself, by diffing the currently-registered content
against the `_wp_suggested_privacy_policy_content` post meta of the page named by
`wp_page_for_privacy_policy`.

On a site where that option is `0` — as on faz-test today — there is no baseline, and those flags mean
nothing. Two consequences:

1. FAZ keeps **its own snapshot and its own diff**, so change detection works regardless of whether
   core's privacy page was ever configured. Core's timestamps are ignored entirely.
2. FAZ should **offer** to point `wp_page_for_privacy_policy` at the generated page. That is what makes
   `get_privacy_policy_url()` resolve sitewide — the login form, comment forms and other plugins all
   link through it, and `Renderer::privacy_policy_url()` already reads it. Offer, never silently set:
   it is a site-wide setting the operator owns.

There is one trap in core's return value: entries carrying a `removed` timestamp are read back out of
the post-meta cache and are **not** currently registered. Keeping them would resurrect every plugin the
site ever deactivated as a live block, so they are dropped on the way in.

## Sanitisation boundary

Each collected body goes through `wp_kses_post()`, then an HTML-aware character clip that removes a
partial trailing tag and balances open elements within the same budget, then a trim — and the
`source_hash` is computed on that **final stored string**.

The order is load-bearing. Hashing the producer's raw text instead would make every block whose text
kses touches hash differently from what is stored, so every collection would see a phantom "updated" —
and would raise a false `stale` flag on every operator-edited block — forever. The unit suite pins this
with a deliberately observable kses stub (a naive `<script>` strip): weakening that stub to the
identity function would make the suite pass without proving anything.

Operator overrides pass through the same `sanitize_html()`. There is one sanitisation path, not two.

## Bounds

`MAX_BLOCKS` (100), `MAX_HTML` (60 000 characters per body — WooCommerce's is around 5 KB) and
`MAX_NAME` (200) exist so a malformed or pathological producer cannot grow the option without bound.
Same rationale as `Section_Overrides::MAX_SECTIONS`.

The cap **refuses new blocks; it never truncates the map.** Truncating could evict a block the operator
has edited, and losing an editorial decision is a worse failure than missing a section.

The snapshot is written with `update_option( …, …, false )` on every write path. It is admin and
render-source data, never needed on an ordinary frontend request, and can run to tens of kilobytes.
`update_option`'s default autoload for a *new* option is yes, so the third argument has to be passed
every time, not only on the first write.

## Accepted side effect

`get_suggested_policy_text()` refreshes core's `_wp_suggested_privacy_policy_content` meta cache when a
privacy page **is** configured, which can pre-empt core's own "suggested text has changed" admin
notice.

This is accepted. It is core's public, sanctioned accessor — the same refresh happens whenever the
operator opens core's Privacy Policy Guide — the effect is bounded to installs that both configured a
privacy page and visit FAZ screens, and FAZ's own three-state flagging supersedes that notice for
FAZ-managed documents. Reading the private `$policy_content` property by Reflection to avoid it was
considered and rejected: worse for wp.org review and brittle against core refactors.

## Known blind spot

If a site's admin locale changes, every translated `policy_text` changes with it, so every block
legitimately reports an update. Worse, a plugin that translates **both** its display name and its text
matches neither identity pass and cycles removed + added, orphaning any override on it.

Core has exactly the same limitation, and plugin display names are in practice untranslated brand
names. Documented rather than fixed.

## What still needs human editorial work

Reduced, but not zero:

- The **jurisdiction-fixed rights text** (GDPR Arts. 15-22, CCPA rights, LGPD Art. 18, POPIA) — statute,
  reviewable once, and not a claim about the site.
- The **structural scaffold** that frames the collected blocks: who the controller is, contact,
  retention, transfers, how to exercise rights.
- The **operator-supplied facts**, which the plugin must refuse to invent — same rule as the Cookie
  Policy: never seed from `admin_email` / `blogname`, and refuse to render when mandatory jurisdiction
  fields are missing.

## Consequences for the plan

- §P0a effort shifts: less editorial authoring, more collection/snapshot/diff machinery. The 45/55
  architectural/editorial split should be revised accordingly.
- R1 mitigation gains a load-bearing element: "the plugin aggregates first-party declarations and does
  not author third-party processing descriptions".
- The privacy-policy `Document_Config` gains two registry fields: a collector callable
  (`Content_Collector::collect`) and the snapshot option name
  (`faz_privacy_content_snapshot`). The renderer-facing accessor `effective_blocks()` already returns
  render-ready `id => [plugin_name, html]` pairs, so no migration is needed when that lands.
- The refusal gating (`missing_required_settings()`, POPIA-only today) still applies and still matters.
