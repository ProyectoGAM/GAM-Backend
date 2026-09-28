# ProductResource management capabilities

## Objective

Extend `ProductResource` with truthful ownership and user/state-specific capabilities so the Products frontend can choose catalog actions without heuristics. Apply one consistent contract to list, detail, mutation responses, and existing nested Product resources without per-Product queries.

## Why

The current Product resource exposes catalog fields only. Ownership exists in `system_key` and an optional Vaccine relation, while edit/status restrictions depend on policy, current state, movement lines, and stock balances. The frontend cannot safely infer those facts from `kind` or visible fields.

## Authorized scope and constraints

- Work only in GAM-Backend on branch `develop`.
- No commit or push; this explicit user restriction overrides the repository workflow's work-unit commit convention.
- Do not modify GAM-Frontend, routes, `UpdateProductAction`, or `ChangeProductStatusAction`.
- Do not weaken backend validation or expose `system_key`.
- Preserve the pre-existing dirty files: `app/Http/Resources/Inventory/StockLocationResource.php`, `compose.dev.yaml`, and `tests/Feature/Inventory/FeedStockEndpointTest.php`.
- Ownership: `system_key=generic_egg` means system-managed egg stock; a real related Vaccine means Vaccine ownership with its public ULID; `kind=vaccine` alone and `kind=medicine` do not imply a specialized owner.
- Capabilities describe actions from Products for the authenticated user and current record. Special owners expose no Products mutations. Normal products start with `sku`, `name`, `kind`, `base_unit`, `stock_tracked`, subject to ProductPolicy and known current-history restrictions. Do not predict constraints based on a future value.
- Keep ProductResource free of individual database queries. Avoid N+1 for vaccine, movementLines, and stockBalances in all collection paths serializing Products.

## Confirmed implementation facts

- Product has optional `hasOne(Vaccine)`, `hasMany(InventoryMovementLine)`, and `hasMany(StockBalance)`; it has no Medicine relation.
- ProductPolicy currently authorizes update/changeStatus through `vaccine()->exists()` and otherwise uses the create permission (`admin` or `products.manage`). Keep the decision identical while using an already loaded Vaccine relation when available.
- `generic_egg` is the protected technical Product. The status action already rejects its status change.
- Linked Vaccine Products are owned by Vacunas. The status action can reject deactivation when their on-hand stock is positive.
- `UpdateProductAction` protects generic_egg, applies Vaccine-specific restrictions, blocks base-unit changes with movement lines, and checks raw-material history using movement lines or stock balances. These action rules must remain untouched.
- ProductResource is used by Products endpoints and nested Inventory/feed-stock responses; those query paths must hydrate the metadata inputs before serialization.

## TDD and verification

- TDD mode: off, based on the existing explicit project/session selection recorded in `GAM-Frontend/odd/tasks/inventory-usability-and-production-units.md`.
- Test runner: `php artisan test` (also run the user's full requested validation commands at closure).
- Checks: focused new Product resource tests during implementation; at closure run `php artisan test`, `vendor/bin/pint --test`, and `vendor/bin/phpstan analyse`.
- Change-size estimate for implementation/tests: approximately 320 authored lines, excluding pre-existing dirty files and this recovery document.
- Engram mirror: pending; no Engram/memory tools are available in this environment.
- No delivery commit or review candidate will be created because the user explicitly forbids commits.

## Tasks and acceptance criteria

| ID | Task | Route and trigger | Acceptance |
| --- | --- | --- | --- |
| PRC-01 | Add shared efficient Product metadata hydration for resource query paths and avoid policy queries when Vaccine is loaded. | Delegated direct; mapping required 4+ files and implementation spans multiple non-trivial query/resource/policy files. | Ownership relation and history flags are loaded in batches/existence subqueries. ProductPolicy semantics are unchanged. All relevant collection serialization paths avoid per-row metadata/policy queries. |
| PRC-02 | Extend ProductResource with `system_managed`, `specialized_owner`, and `capabilities`; hydrate single-item Product endpoint responses consistently. | Delegated direct; shares implementation surface with PRC-01. | The same contract appears in list/detail/mutation/nested resources; `system_key` remains private and serialization performs no database queries. |
| PRC-03 | Add feature coverage for ownership, policy, history restrictions, status capabilities, and query growth. | Delegated direct; test coverage spans the shared query and resource contract. | Cover authorized normal product, read-only user, generic_egg, linked Vaccine public ULID, orphan `kind=vaccine`, raw_material with/without history, movement-line unit restriction, medicine kind, and bounded list query count. |

## Progress

- [x] Exploration: verified current branch, relevant model/resource/policy/action/query behavior, and ProductResource call sites. Existing dirty files are preserved.
- [x] PRC-01: shared hydration scope covers Products, stock balances, movement lines, and feed-stock products; ProductPolicy uses the eager-loaded Vaccine relation with the existing lazy fallback.
- [x] PRC-02: ProductResource emits ownership and capabilities; store/update/status responses re-read through the shared metadata query.
- [x] PRC-03: feature tests cover permission, ownership, history restrictions, state capabilities, and bounded query counts. They passed against the Compose PostgreSQL database `sga_backend_testing` after identifying and correcting the eager-load closure type mismatch described below.
- [x] Parent ran the requested full checks and reviewed the changed files. Outcomes and environment limitations are recorded below.

## Change rationale

The resource remains a serializer: query hydration belongs in shared model/query loading and endpoint query paths. `Product::withResourceMetadata()` batches the optional Vaccine relation and adds existence subqueries for movement lines and stock balances. Inventory/feed-stock eager-load paths use that scope too. ProductPolicy reuses an eager-loaded Vaccine relation and preserves its database fallback for callers that did not preload it.

## Work and verification evidence

- Changed implementation paths are limited to Product model/resource/policy/query/controller files and Inventory query/action paths that serialize nested Products. `UpdateProductAction`, `ChangeProductStatusAction`, routes, frontend, and the three pre-existing dirty files were not edited.
- Added `tests/Feature/SuppliersAndCatalogs/ProductResourceCapabilitiesTest.php` for requested ownership, permissions, history, status, orphan vaccine/medicine, and query-count cases.
- `php -l` passed for every changed PHP source and test file.
- Targeted `vendor/bin/pint --test` passed for changed PHP source and test files.
- Targeted `vendor/bin/phpstan analyse` passed with 0 errors.
- `git diff --check` passed for changed implementation files and the new test has no trailing whitespace. Repository-wide `git diff --check` still reports the pre-existing trailing whitespace at `compose.dev.yaml:253`; that protected file was not changed by this task.
- `php artisan test tests/Feature/SuppliersAndCatalogs/ProductResourceCapabilitiesTest.php` passed in the API container against `sga_backend_testing`: 5 tests, 48 assertions.
- Related tests passed on the same database: `ProductIngredientRulesTest` (3 tests, 15 assertions), `SuppliersAndCatalogsEndpointTest` (6, 19), `InventoryEndpointTest` (8, 38), `FeedStockEndpointTest` (11, 68), and `EggStockEndpointTest` (3, 16). Total: 36 tests and 204 assertions.
- The initial host-run failed because `phpunit.xml` forces `DB_HOST=postgres`, a Compose service DNS name unavailable from the Windows host. The project README's supported route runs tests inside the Compose `api` service. `tests/bootstrap.php` appends `_testing` to the configured database name; `.env` has `sga_backend`, and Compose's existing idempotent `postgres-test-database` initializer creates `sga_backend_testing`. I started only the `postgres` and test-database initializer services and ran tests in an ephemeral `api` container with `DB_DATABASE=sga_backend_testing`. I verified Laravel's effective configured DB name before executing PHPUnit. `app-init` was not run, so no migrations ran against `sga_backend`.
- The related tests exposed a functional error in metadata eager loading: Laravel passes a `BelongsTo` relation to eager-load callbacks, while callbacks required `Eloquent\\Builder`. The callbacks now accept `BelongsTo` and apply the metadata scope to its query. ProductResource/capability decisions were unchanged. The target and all related tests above passed after the fix.
- Full `vendor/bin/pint --test` failed on the unmodified `database/seeders/Inventory/FeedStockDemoSeeder.php`; targeted Pint for all task-changed PHP files passed.
- The requested bare `vendor/bin/phpstan analyse` exited because this PHPStan version requires an explicit path. `vendor/bin/phpstan analyse app tests` ran but reported 12 missing-class errors in existing PDF/YAML code outside these changes. Targeted PHPStan for every task-changed source and test file passed with 0 errors.
- Final `git diff --check` still reports only the existing `compose.dev.yaml:253` trailing whitespace. No commit or push was made.

## Next step

Implementation and review are complete. The target and related Product/Inventory suites passed inside the Compose API environment against `sga_backend_testing`. Host execution still cannot resolve the Compose-only `postgres` DNS name. Engram mirror remains pending because no memory tool is available. Preserve the no-commit/no-push restriction.
