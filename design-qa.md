# Design QA — Tarifas Shopify

## Evidence

- Source visual truth: `C:\public_html\tools\feedTarifas\var\audit\2026-10-01-ui\selected-option-2.png`
- Source pixels: 1487 × 1058, 24-bit RGB.
- Implementation: `http://127.0.0.1:8765/` in Codex in-app Browser, tab 1.
- Implementation screenshot path: `iab://tab/1` (browser-rendered captures are attached inline to the current build run; the in-app Browser does not expose a filesystem path).
- Desktop CSS viewport: 1309 × 931, device pixel ratio 1.1; document size 1295 × 1384.
- Responsive CSS viewport: 355 × 767; document width equals client width at 341 px.
- State: completed initial load with partial outcome, 2,454 incidents, expanded technical details.
- Density normalization: comparison used CSS layout proportions because the in-app Browser caps the requested 1440 × 1024 override at its available window size. No findings were filed solely from that viewport difference.

## Findings

No actionable P0, P1, or P2 differences remain.

The implementation preserves the chosen direction: a task-first left action rail, latest-operation summary, five key metrics, four process stages, structured recent incidents, and progressively disclosed technical information.

## Required fidelity surfaces

- Fonts and typography: system sans-serif matches the pragmatic Bootstrap target. Headings, labels, metrics, helper text, table copy, wrapping, and weight hierarchy were visually checked at desktop and mobile widths.
- Spacing and layout rhythm: desktop uses the selected asymmetric action/content layout with lightweight surfaces and dividers. Mobile reflows to one column, moves status before actions, and has no body-level horizontal overflow.
- Colors and visual tokens: neutral gray page, white surfaces, navy text, Bootstrap blue, green success, and amber warning match the source. Warning meaning is also conveyed with icon and text.
- Image quality and asset fidelity: the selected design contains no raster content that needs recreation. Bootstrap Icons are used for all interface icons; there are no handcrafted SVGs, CSS illustrations, emoji, or placeholders.
- Copy and content: Spanish labels and the distinctions between validation, initial load, and synchronization are preserved. The implementation intentionally says “incidencias recientes” because the backend exposes at most the 50 most recent records rather than all incidents.

## Full-view comparison evidence

The source mock and the final browser-rendered desktop capture were inspected for hierarchy, proportions, surface density, action prominence, metric order, stage layout, incident structure, and technical-detail placement. The final layout keeps the same major regions and information order. The visible content fits without horizontal overflow.

## Focused comparison evidence

- Header and operation summary: status badge now reads “Finalizada con incidencias”, followed by explanatory copy, dates, and the five metrics in source order.
- Process and incident region: four linked stages, truthful final warning state, five visible recent incidents, and a working disclosure for the remaining recent items.
- Technical region: expanded accordion, working Bootstrap tabs, wrapped Shopify identifiers, and a verified copy control.
- Mobile region: status and metrics appear before the action panel; the process becomes a vertical sequence and technical tabs scroll within their own container.

## Interaction and accessibility checks

- All three primary actions remain POST forms with CSRF fields.
- Incident disclosure expands from 5 to 20 fixture rows and collapses again.
- Catalog and per-tariff tabs activate and render 10 rows each.
- Technical accordion opens and closes.
- Shopify operation ID copy control changes to a confirmed state.
- Focus-visible styles are defined for buttons, tabs, and accordion controls.
- Browser console: no warnings or errors.

## Comparison history

### Pass 1

- P1: mobile placed the full action panel before the operation result. Fixed with responsive visual ordering so the latest status appears first on narrow screens.
- P2: root execution on Windows produced an invalid `http://app.css/` asset URL. Fixed by normalizing `.` and Windows path separators in `$basePath`.
- P2: the top badge exposed the backend label “Parcial” instead of the selected design’s user-facing outcome. Fixed by presenting the stage “Finalizada con incidencias” and keeping “Parcial” in technical details.

### Pass 2

- Post-fix browser captures confirmed the corrected mobile order, loaded local stylesheet, accurate outcome badge, desktop hierarchy, responsive reflow, and zero body overflow.
- No remaining P0, P1, or P2 findings.

## Follow-up polish

- P3: a future backend endpoint could support pagination or export for all incidents. The current UI accurately limits itself to the recent records already returned by the application.

final result: passed
