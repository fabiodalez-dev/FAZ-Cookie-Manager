# Blast Radius — Core Classes

Baseline generated on **2026-04-23** via `code-review-graph` MCP
(`get_impact_radius_tool`, max_depth=2) against:

- `frontend/class-frontend.php` (Frontend God Node — 68 edges)
- `includes/class-base-controller.php` (Controller God Node — 125 edges)
- `admin/class-admin.php` (Admin — 38 edges)

## Summary

| Metric | Value |
|---|---|
| Directly changed nodes | 131 |
| Impacted nodes within 2 hops | 15 |
| Additional files affected | 15 |
| Risk classification | **medium** |

## Files that change behaviour when the 3 core classes are modified

1. `admin/modules/banners/api/class-api.php`
2. `admin/modules/banners/class-banners.php`
3. `admin/modules/banners/includes/class-banner.php`
4. `admin/modules/banners/includes/class-template.php`
5. `admin/modules/cookies/includes/class-category-controller.php`
6. (+ 10 more — rerun the MCP tool to refresh the list)

## How to use this file

Before editing any of the 3 core classes above, rerun:

```
mcp__code-review-graph__get_impact_radius_tool
  changed_files=["frontend/class-frontend.php"]   # or the file you're editing
  max_depth=2
  detail_level=standard
```

If the *impacted file count* grows beyond ~20, treat the change as
**high blast radius** and split the PR.

## Why this matters

The graphify knowledge graph ranks these three classes as the
architectural hotspots of the plugin:

| Class | Edges | Role |
|---|---|---|
| `Controller` | 125 | Base of every admin module — CRUD + REST glue |
| `Frontend` | 68 | Banner render, script enqueue, consent pipeline |
| `Admin` | 38 | Admin bootstrap, menu, asset loader |

Any regression here ripples through banners, cookies, categories,
scanner and consent logging. Running an impact-radius query *before*
the edit is the cheapest insurance available.
