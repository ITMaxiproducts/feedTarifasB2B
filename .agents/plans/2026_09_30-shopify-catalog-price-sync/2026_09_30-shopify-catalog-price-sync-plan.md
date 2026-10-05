---
name: "Shopify catalog price sync"
description: "Build a small PHP application for an initial Shopify price load, nightly changes, and a Bootstrap management panel."
created_at: "2026-09-30T08:38:40Z"

created_by:
  tool: "Codex"
  model:
    name: "OpenAI GPT"
    version: "6"
    reasoning_effort: "unspecified"

implemented_by:
  tool: "Codex"
  model:
    name: "OpenAI GPT"
    version: "6"
    reasoning_effort: "unspecified"

last_implementation_at: "2026-10-01T10:51:48Z"
has_completed_all_phases: "true"
---

# Goal

Sync the ten SQL Server price tariffs to their Shopify price lists through a small PHP application. Let an operator start and monitor every action from a Bootstrap panel, while a cPanel cron runs the PHP work in the background.

# Context

- The [project root](../../..) is empty. There is no existing application, repository configuration, `AGENTS.md`, or project documentation to preserve.
- The supplied SQL query returns one row per `IdArtículo`, `Marca`, and ten calculated tariff prices. The observed result has 6,254 unique articles, so a full load can contain up to 62,540 prices.
- A later Phase 1 run read 6,316 rows: 6,311 valid and five repeated rows for `1567RCAJA`. Phase 2 records and skips invalid references while loading the rest. All rows of a duplicated article are skipped, so no aggregation or first-row price rule is assumed. Currency and acceptable decimal places still require explicit configuration before import.
- The first load should use Shopify GraphQL bulk mutation with JSONL. Later runs should execute the same SQL query, compare its calculated prices with successfully synced values, and send only changes through direct GraphQL requests.
- Before the first upload, the operator creates one Shopify catalog per tariff, with the catalog title equal to its tariff code. The application resolves each catalog by its unique title, checks its associated price list, and creates that price list once if it is missing. The application does not create catalogs. Each `IdArtículo` must map to one Shopify product variant ID. Confirm that the store can use ten active catalogs; assign each catalog to its intended buyers after its initial prices are verified.
- Keep PHP CLI, native PHP database/HTTP extensions, SQLite, Bootstrap, and one cPanel cron. Do not add Laravel, Redis, a queue service, or a custom authentication system. Protect the panel with the hosting account's access controls, and keep credentials and writable state outside the public web directory.
- The panel is the operator interface. A panel action inserts a pending job in SQLite; the cron-driven CLI worker picks it up. Web requests never wait for a Shopify import or launch a long-running process.
- Define the target money precision and currency for each price list before importing the SQL values, which currently have three decimal places. Treat missing articles or blank prices as exceptions for review until an explicit deletion rule is agreed.

# Phases

## Phase 1: Run a source-data preview from the panel

Description: Deliver a usable panel action that reads the source query and reports what a sync would process, without changing Shopify.

Public contracts:

- UI: `GET /` shows the latest job, status, timestamps, article count, tariff count, and errors. `POST /actions/preview` queues a preview. The button label is `Validar datos`.
- CLI: `bin/worker.php` is invoked by one cPanel cron and consumes pending jobs. Only one worker may run at a time.
- SQLite: `jobs` records action, status, timestamps, counts, and summary; `job_errors` records actionable errors tied to a job.
- Source data: `sql/prices.sql` contains the supplied query and exposes the existing article, brand, and tariff columns.

To-do:

- [x] Create a small PHP structure with `public/`, `bin/`, `src/`, `sql/`, and private `var/` storage. Keep the document root restricted to `public/` on deployment.
- [x] Add SQL Server access with `PDO_SQLSRV`, SQLite state access, and a source reader that iterates the query results and validates unique article IDs, required prices, and expected tariff columns.
- [x] Add the Bootstrap panel, the preview action, status display, access protection, and a CSRF check for the action.
- [x] Add the cron worker with a lock and a short cPanel setup note. Establish a simple project verification command using PHP syntax checks and focused logic checks, without a test framework.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

## Phase 2: Start and inspect the initial full load from the panel

Description: Make the first Shopify upload fully manageable in the panel, including asynchronous completion and per-record failures.

Public contracts:

- UI: `POST /actions/initial-load` queues the full load. The panel displays `Pendiente`, `En curso`, `Esperando a Shopify`, `Completado`, `Parcial`, or `Fallido`, with counts per tariff.
- Configuration: provide the currency for each tariff, the shop domain, and API credentials through private configuration. The worker resolves catalog and price list IDs from Shopify by the exact tariff-code titles and stores the resolved IDs.
- SQLite: `variant_map` maps article/SKU to Shopify variant ID; `synced_prices` stores only confirmed `(article, tariff, amount, currency, variant ID)` values; `jobs` stores the Shopify bulk operation ID and progress. The catalog mapping stores the resolved Shopify IDs.

To-do:

- [x] Resolve each tariff code to exactly one existing Shopify catalog. Check its price list and currency; create and associate a price list when missing. Show resolved catalogs and any ambiguity in the panel. Resolve the article-to-variant mapping in bulk and flag missing or ambiguous SKUs before pricing them.
- [x] Turn validated prices into JSONL mutation inputs grouped by price list, with no more than 250 prices per input line. Stage the file and start the Shopify bulk mutation.
- [x] Let later cron runs check the operation, read its JSONL result, show line-level errors, and persist only confirmed prices. Prevent a second initial load while one is active.
- [x] Keep generated files, credentials, and operation output in private storage; show the relevant progress and errors in the panel.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

## Phase 3: Sync nightly changes and allow manual runs

Description: Use the existing cron worker for a once-per-night sync and allow operators to launch the same sync from the panel.

Public contracts:

- UI: `POST /actions/sync` queues `Sincronizar ahora`. The panel shows changed, sent, confirmed, and failed prices per tariff and the next scheduled run. Running sync again also retries prices that were not confirmed previously.
- CLI: `bin/worker.php` starts the configured nightly sync once per calendar day, processes panel requests, and resumes pending Shopify work without overlapping itself.
- SQLite: `synced_prices` advances only for confirmed prices; failed items remain eligible for retry. `jobs` and `job_errors` provide the operator history.

To-do:

- [x] Compare each calculated `(article, tariff, amount, currency)` with `synced_prices`, then group changed prices by Shopify price list into direct GraphQL requests of at most 250 prices.
- [x] Handle Shopify user errors, throttling, transient failures, and retries without marking failed prices as synced. Keep the nightly job idempotent.
- [x] Add the manual sync action, automatic nightly scheduling, clear dashboard counts, and a concise cPanel deployment note covering paths, cron frequency, and private storage.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

# Next step

All phases are implemented. Review the complete application and configure each tariff's currency and acceptable decimal places before a real initial load; source references with errors will be reported and omitted.

Sincronizaciones listas gracias a [Codely](https://codely.com) AI tooling. 🔁 ✅ 🐢 💨 🛡️
