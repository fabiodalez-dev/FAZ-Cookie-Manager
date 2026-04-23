# Upstream bug — `code-review-graph` MCP

**Status**: FILED — https://github.com/tirth8205/code-review-graph/issues/372
**Date filed**: 2026-04-23
**Version**: code-review-graph 2.3.2

## Symptom

`get_hub_nodes_tool`, `get_bridge_nodes_tool` and `get_knowledge_gaps_tool`
fail with `'str' object has no attribute 'resolve'` (when `repo_root` is
passed as a string) or `'NoneType' object has no attribute 'resolve'`
(when `repo_root` is omitted).

`detect_changes_tool`, `find_large_functions_tool`, `list_graph_stats_tool`,
`build_or_update_graph_tool`, and `get_impact_radius_tool` succeed on the
same graph with the same inputs, indicating a missing `pathlib.Path(...)`
coercion specific to the three failing handlers.

## Workaround used in this project

Hub-node identification falls back to the community-detection output of
`graphify` (see `graphify-out/GRAPH_REPORT.md`). Betweenness centrality
via `get_bridge_nodes` is unavailable until the upstream fix ships.

## Follow-up

When the issue is closed upstream, delete this file.
