# Account-gold

Multi-branch accounting. `api/` = Laravel 11 (PHP 8.3, MySQL 8). `web/` = Angular 18 standalone + signals.
Product docs: `docs/Account-gold_Project_Document.pdf`, backlog `docs/Account-gold_Product_Backlog.xlsx`, feature specs in `docs/specs/`.

## Rules that must not be broken
- All money postings go through `api/app/Services/LedgerService.php`. Never update `accounts.current_balance` anywhere else.
- Money is handled with `App\Support\Money` (integer cents). No floats, no bcmath.
- Nothing is hard-deleted. Income/expense are voided, transfers are reversed. Every change writes `AuditLog::record(...)`.
- Branch isolation: branch-owned models use the `BranchScoped` trait; the active branch comes from `X-Branch-Id` via `SetBranchContext`. Only use `withoutGlobalScopes()` when you have checked access yourself.
- Permissions are fixed in `database/seeders/PermissionSeeder.php`; roles are per branch (spatie teams, `branch_id`). Nobody may grant a permission they do not hold.
- Date filters use `whereDate` (portable between SQLite tests and MySQL).

## Commands
- API tests: `cd api && php artisan test` · lint: `./vendor/bin/pint --test`
- Queue worker (invitation emails and access alerts are queued): `cd api && php artisan queue:work`. Locally you can set `QUEUE_CONNECTION=sync` in `api/.env` instead.
- Web build: `cd web && npx ng build` · dev: `npx ng serve` (proxies /api to :8000)
- Use `npm ci` in CI; if you change `web/package.json`, commit the updated lock file.

## Agent team (.claude/agents)
Feature flow: `ba` writes the spec -> `accountant` checks the spec -> `developer` builds -> `qa` tests + `reviewer` reviews -> `developer` fixes -> `accountant` checks the finished build (sign-off) -> pull request.
