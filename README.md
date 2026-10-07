# BD ERP

A single-company, multi-branch ERP for Bangladesh: **sales & CRM**, POS,
purchase and stock, double-entry accounting, employees, courier/delivery
settlement and the statutory document set (invoice / Mushak 9.1 / challan) —
built on Laravel 13, Bootstrap 5.3 as a utility base, and an original design
system.

* Architecture decisions, data model and module contracts: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
* Module-by-module traceability to the specification: [`docs/TRACEABILITY/`](docs/TRACEABILITY)
* Implementation status (machine-readable): [`docs/IMPLEMENTATION_STATUS.json`](docs/IMPLEMENTATION_STATUS.json)
* What is still missing, module by module: [`docs/REMAINING_WORK.md`](docs/REMAINING_WORK.md)
* UI audit + redesign record: [`docs/UI_AUDIT_AND_REDESIGN.md`](docs/UI_AUDIT_AND_REDESIGN.md)

## Non-negotiables baked into the code

| Invariant | Where it is enforced |
|---|---|
| Exactly one company per instance | `Company` + DB constraint (`config('erp.company.singleton')`) |
| Double entry: Σ debits = Σ credits on every posted journal | accounting domain actions + tests |
| Stock truth = immutable movements; balances derived | inventory domain |
| Permission-filtered navigation — unauthorized items are **absent**, never disabled | `NavigationBuilder` + `NavigationMenuTest` |
| No fake business data anywhere | widgets and reports render real queries or explicit empty states |
| Append-only audit with actor/action detail | `AuditRecorder`, chain verification command |

## Getting started

```bash
composer setup        # install, .env, key, migrate, npm install, build
php artisan serve
```

First boot is explicit — no default account exists:

```bash
php artisan erp:setup-token      # print the one-time setup token
# then open /setup and create the first Super Admin
```

Useful commands:

```bash
php artisan menu:sync            # re-import the §47 catalog and re-classify entries
php artisan search:rebuild       # rebuild the search index
php artisan erp:chain-verify     # verify the audit hash chain
php artisan test                 # full suite
```

## Modules

| Domain | What is in the app today |
|---|---|
| Sales & CRM | Orders, invoices, quotations, delivery challans, shipments, coupons/promotions, sales team, POS counter, returns — plus the **customer CRM** (profile, ledger, ageing, credit control, feedback/NPS, referrals, wishlist, blacklist) |
| Inventory & warehouse | Products, stock overview, movements, adjustments, transfers, warehouses |
| Accounts & finance | Chart of accounts, journal entries, trial balance, opening balances (full ledger/report suite in progress) |
| People & payroll | Employee records (full HRM in progress) |
| Settings & masters | BD geo masters, tax rates, price lists, pricing rules, roles & permissions, appearance |

Coverage is tracked honestly in [`docs/REMAINING_WORK.md`](docs/REMAINING_WORK.md).

## The UI: "Aperture" design system

White-first, ink-on-paper enterprise UI as specified in
[`docs/ARCHITECTURE.md` §18](docs/ARCHITECTURE.md): RGB-vibrant accents on a
layered near-white canvas, hairline borders, restrained elevation, tabular
numerals for money, and one overlay system for modal / drawer / toast / confirm.
The accent is configurable — **vibrant emerald** (default), azure, tangerine or
graphite. Indigo, violet and pink are not available anywhere: the preset list is
closed and §18.1 forbids a purple identity.

* **App shell** — `resources/views/layouts/app.blade.php` + `partials/`:
  sectioned navigation rail (collapsible, remembered), breadcrumbs, command
  palette, toasts, skip link.
* **Navigation** — the §47 catalog defines *coverage*; a curation layer
  (`NavigationBuilder` + `config/erp.php → navigation`) decides what the rail
  shows: job-to-be-done sections, promoted groups, one link per screen, a hard
  depth cap. Every permitted page — including deep ones — stays reachable
  through **⌘K / Ctrl+K**.
* **Primitives** — `resources/views/components/ui/`: `page-header`, `kpi`,
  `table-shell`, `empty`, `status`, `related-pages`.
* **Behaviour** — `resources/js/app.js`: shell, palette, toasts, tables/bulk
  selection, forms, permission matrix, repeaters, notification poller, scanner.
  No anonymous inline scripts in Blade.
* **Appearance** — Settings › Appearance chooses the company accent (vibrant
  emerald / azure / tangerine / graphite), default theme and row density. Each
  user can still switch light/dark and comfortable/compact rows; both are
  remembered locally, the accent is token-driven.
* **Sections** — the rail is grouped by business domain (My work, Sales & CRM,
  Inventory & warehouse, Accounts & finance, People & payroll, Marketing &
  growth, Reports & insight, Governance, Settings & masters), each domain
  carrying its own data-visualisation hue so the eye can navigate by colour.

### Design preview (no PHP required)

`preview/` renders the new shell, dashboard, order list, order workspace, POS
terminal and sign-in screen with the **same stylesheet and behaviour layer the
app ships**. It is a review aid, not part of the application build.

```bash
node preview/serve.mjs 4173          # → http://localhost:4173
# after changing resources/css/app.css or resources/js/app.js:
npx vite build --config vite.preview.config.js && node preview/src/build.mjs
```

Figures in the preview are labelled sample data used to show density and state
handling; they are never seeded into an instance.

## License

Proprietary — © the ERP owner. Laravel itself is MIT licensed.
