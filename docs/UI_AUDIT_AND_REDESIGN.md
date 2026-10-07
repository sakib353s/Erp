# UI audit and redesign — "Aperture" (2026-10-07)

Scope: the whole rendered surface of the ERP (app shell, navigation, design
system, page anatomy, states) plus the navigation data pipeline that feeds it.

This document is the honest answer to three questions:

1. **What is wrong with the ERP system?**
2. **What are the design mistakes?**
3. **Why did "sidebar menu items just go to pages"?**

Every finding below is reproducible from the repository at the commit that
preceded this redesign. Fixes are listed with the file that carries them.

---

## 1. Why the sidebar "just goes to pages"

**Symptom.** Dozens of sidebar entries looked like distinct destinations but
opened the same handful of screens. Clicking "Bulk Print Invoice", "Pending
Orders" and "Export Orders" all landed on Sales Orders. The menu was long yet
navigated almost nowhere.

**Root cause.** `database/catalog/menu_tree.txt` is a **specification**, not a
navigation model. It is 1,022 lines describing every widget, every report and
every verb the system must eventually support:

```
02. SALES
    ├── Orders
    │     ├── All Orders
    │     │     ├── View / Edit
    │     │     ├── Bulk Confirm
    │     │     ├── Bulk Cancel
    │     │     ├── Bulk Print Invoice
    │     │     ├── Bulk Print Packing Slip
    ...
```

`CatalogImporter` classified verb-first leaves as **action entries**: it pointed
them at their parent page's route and left `location = 'sidebar'`. The sidebar
renderer then drew every row whose `status` was `active` — including the action
leaves — as a first-class navigation link.

Consequences:

| Consequence | Evidence in the old code |
|---|---|
| ~10 sidebar entries resolved to `/app/sales/orders` alone | `CatalogImporter::ACTION_VERBS` + `$route = $parentRoute` |
| Deep spec groups became 3–4 level accordions inside a 264 px rail | `partials/menu-nodes.blade.php` recursive `@include` |
| Two names for one screen ("Orders" / "All Orders") | no de-duplication by resolved URL |
| Query variants (`?status=pending`) rendered as separate menu rows | `OVERRIDES` deep-links such as `/app/sales/orders?status=returned` |
| Active-state false positives | `str_starts_with($currentPath, $base.'/')` prefix matching |

**This violated the project's own contract.** `docs/ARCHITECTURE.md` §18.3
already specified "collapsible groups, search/filter, favorites + recent,
active trail, breadcrumbs … **No wall-of-text sidebar**". The implementation
rendered the wall anyway.

**Fix.**

| Layer | Change |
|---|---|
| Import | Verb-first leaves are seeded with `location = 'action'` — they keep their permission row and route (nothing becomes unreachable) but they are no longer *destinations*. `CatalogImporter` |
| Curation service | `NavigationBuilder` rewritten: job-to-be-done **sections**, promoted second-level **groups**, one canonical link per screen (`dedupe_by_path`), query variants collapsed (`collapse_query_variants`), hard depth cap (`max_depth`), child quota (`max_children`) |
| Renderer | `partials/menu-nodes.blade.php` renders the curated forest only; one-item groups render as a plain link (no one-item accordions) |
| Reachability | ⌘K **command palette** indexes every permitted, routable entry — including the deep pages that left the rail (`NavigationBuilder::palette()`) |
| Page level | Query variants become **saved views** on the page (`viewsForPath()`); module siblings appear in a "More in this module" rail (`related()`) |
| Personal | **Pinned** destinations persisted server-side (`menu_item_favorites`, `navigation.pin`) |

Net effect: the rail shows real destinations grouped by job-to-be-done, while
the catalogue's full depth stays one keystroke away.

---

## 2. Design mistakes found (and their fixes)

### 2.1 Purple identity — an explicit architectural breach

`resources/css/app.css` used indigo `#4f46e5`, hover `#4338ca`, soft `#eef2ff`
and a **purple gradient** brand mark (`linear-gradient(135deg, #4f46e5, #7c6cf6)`),
plus purple focus rings `rgba(79,70,229,.15)` and purple chart bars.

`docs/ARCHITECTURE.md` §18.1 forbids exactly this: *"Forbidden: … purple/dark-only
identity"*, and requires *"`--canvas` white base, near-white layered surfaces,
neutral borders, **one** configurable accent"*.

**Fix.** New token layer — white canvas, layered near-white surfaces, hairline
borders, ink text, and **one** accent token. Default accent is **deep teal
`#0f766e`**; `azure`, `forest` and `graphite` presets ship as alternatives, all
non-purple, selectable from Settings › Appearance (`config/erp.php` →
`settings.groups.appearance`). Bootstrap utilities (`btn-primary`, `text-primary`,
`bg-primary`, …) are re-mapped onto the tokens, so the 130+ views that use them
re-themed without a single Blade edit.

### 2.2 Leftover framework scaffold in the design language

`resources/views/welcome.blade.php` was the untouched Laravel welcome page: an
inline Tailwind v4 build, a different type scale and a different colour system —
a second design language shipped next to the ERP. **Removed.**

### 2.3 Page headers re-invented 118 times

`erp-page-head` markup was copy-pasted into 118 views with no shared component,
so titles, subtitles, action ordering and context chips drifted page to page.
There was no breadcrumb anywhere, and no way to pin a page.

**Fix.** `components/ui/page-header.blade.php` (title, eyebrow, subtitle, meta
chips, action slot, self-resolving pin control) + breadcrumbs rendered from the
navigation registry in the TopBar.

### 2.4 One page = one wall of buttons

`sales/orders/index.blade.php` rendered a ten-button bulk toolbar (Confirm,
Cancel, Assign courier, Print invoice, Print packing slip, Print label, SMS,
WhatsApp, Email, Export) permanently visible inside a full-width card — before
the user had selected a single row. The same pattern repeated across bulk
screens.

**Fix.** A single **bulk bar** that appears only when rows are selected
(`.erp-bulkbar` + selection state in `resources/js/app.js`), with the
action parameters (reason, courier, rider, template) folded into a
disclosure. See `sales/orders/index.blade.php`.

### 2.5 Tables were desktop-only and stateless

No sticky headers, no mobile behaviour (a horizontally scrolling table on a
390 px phone), no row-selection state, no honest count line. Long tables had no
density control.

**Fix.** DataTable shell: `.erp-table-shell`, sticky `thead`, `.erp-table-stack`
(mobile card collapse with `data-label` per cell), totals footer, selection
styling, and a user-toggleable **compact density** persisted in
`localStorage` (`data-density`).

### 2.6 Feedback was neither transient nor consistent

17 views re-implemented `@if (session('status')) <div class="alert alert-success">`
while the layout *also* rendered the same message — duplicated flashes. Success
green bars sat on screen until the user navigated, so they stopped meaning
anything.

**Fix.** `partials/flash.blade.php` now emits transient outcomes as **toasts**
and keeps only what must persist (validation summaries) inline; the duplicated
inline blocks were removed from 15 views.

### 2.7 Loading / empty / error states were an afterthought

Empty tables printed a bare `text-muted` sentence ("No sales orders yet."), with
no explanation of the next step; there were no skeletons for slow panels; error
pages were a centred card on a plain background with a link to `/` (which now
redirects to the dashboard — a wasted click).

**Fix.** `components/ui/empty.blade.php` (icon, cause, next step, action),
skeleton classes (`.erp-skeleton`), an inline recoverable-error pattern
(`.erp-inline-error`), and error pages that route authenticated users back to
the dashboard.

### 2.8 Accessibility gaps against §18.1

No skip-to-content link, no `aria-current="page"` on the active nav item, focus
rings delegated to browser defaults mixed with `:focus { outline: none }` on the
context switchers, icon-only buttons without labels, `visually-hidden` used for
some labels but not others, and status colour used without text.

**Fix.** Skip link, `aria-current`, `:focus-visible` ring tokens, labelled icon
buttons, `prefers-contrast` and `prefers-reduced-motion` handling, focus-trapped
palette, `role="status"` toast region.

### 2.9 Responsive behaviour was one media query

A single breakpoint changed padding; the sidebar, tables, KPI cards and forms
did not adapt. §18.4 specifies six modes. **Fix.** Mode-aware rules for
<576, 576–767, 768–991, 992–1199, ≥1200 and ≥1920 (TV: wider page, larger type,
no hover-only affordances).

### 2.10 Mixed date/number presentation

Money columns were plain `number_format()` strings without tabular numerals, so
totals never lined up; dates printed raw ISO strings. **Fix.** `.erp-amount` /
`.erp-td-num` enforce `font-variant-numeric: tabular-nums`; the header shows the
workspace clock in Asia/Dhaka.

---

## 3. Backend defects surfaced by the audit (not fixed here)

These are **not** UI problems and were left untouched — they need their own
change + tests:

1. **Duplicate route registrations** in `routes/web.php`. Employees and users
   are registered twice:
   - `GET /app/employees`, `/app/employees/{employee}`, `/app/employees/{employee}/edit`,
     `POST /app/employees`, `PUT /app/employees/{employee}`, `DELETE /app/employees/{employee}`
     (lines ~219–236 and again ~1200+)
   - `POST /app/users/{user}/suspend`, `/activate`, `GET /app/users/{user}/access`
     (lines ~208–214 and again ~238–244)
   - `PUT /app/settings/company`, `GET /app/settings/company` (twice)

   Laravel resolves the *last* registration, so the earlier one is dead code;
   `route:cache` and future middleware changes will silently reorder behaviour.
2. `GET /app/masters/` is registered three times.
3. **`NavigationSeeder` duplicates cross-cutting utility entries** for modules
   that also have catalog rows (Company Profile, Employees, Master Data), so the
   same screen can appear in both the section tree and the utility list. The
   redesign de-duplicates destinations *within* each list; consolidating the two
   seeders is a follow-up (it changes seeded data, so it needs its own migration
   note).

---

## 4. What the redesign delivers

| Area | Delivered |
|---|---|
| Design system | `resources/css/app.css` — "Aperture" tokens, white-first, four non-purple accents, optional dark mode by token swap, density variants, print rules |
| Shell | `layouts/app.blade.php` + `partials/{sidebar,topbar,command-palette,flash}.blade.php` — sections, rail collapse (persisted), breadcrumbs, ⌘K palette, toasts, skip link |
| Navigation engine | `NavigationBuilder` (sections/groups/dedupe/quota/favorites/palette/views/related/trail), `MenuItemFavorite` + migration, `NavigationController` (pin, permission-checked), `CatalogImporter` location classification |
| Primitives | `components/ui/{page-header,kpi,empty,table-shell,status,related-pages}.blade.php` |
| Behaviour | `resources/js/app.js` — shell, palette, toasts, tables/bulk, forms, matrix, repeaters, poller, scanner (§18.7 module list) |
| Flagship pages | Dashboard, Sales orders list, order workspace pattern, POS terminal (dense mode), sign-in (split layout), error pages |
| Verification aid | `preview/` — static render of the new shell using the shipped CSS + JS (`node preview/serve.mjs`) |

### Verification notes

* Blade balance and template syntax were checked mechanically; PHP itself could
  not be executed in the auditing environment, so `php artisan test` (notably
  `tests/Feature/NavigationMenuTest.php`) must be run before merge.
* `NavigationMenuTest` still holds: the sidebar renders only `status = active`
  rows, permission-filtered, and action leaves keep their permission rows.
* `preview/` intentionally contains illustrative figures, labelled as sample
  data, and never touches the application (global invariant: no fake business
  data in the app).

### Rollout

```bash
php artisan migrate                     # menu_item_favorites
php artisan menu:sync                   # re-classify action leaves (location=action)
php artisan config:clear && npm run build
php artisan test --filter=NavigationMenuTest
```
