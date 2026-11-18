# Account-gold

Multi-branch accounting: a Main branch funds its branches, branches transfer money between each other,
every income and expense is recorded against a two-level category tree, and a Super Admin sees everything.

| Folder | What |
|---|---|
| `api/` | Laravel 11 REST API (PHP 8.3, MySQL 8, Sanctum, spatie/laravel-permission with teams = branches) |
| `web/` | Angular 18 single-page app (standalone components, signals) |
| `docs/` | Project document (PDF) and product backlog (Excel) |

## Run it locally

Requirements: PHP 8.3 + Composer, Node 22, Docker (for MySQL) or your own MySQL 8.

```bash
docker compose up -d                      # MySQL on :3306, Mailpit on :8025

cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                # Main branch, Main Account, permissions, role templates, categories, Super Admin
php artisan serve                         # http://localhost:8000

cd ../web
npm install
npx ng serve                              # http://localhost:4200 (proxies /api to :8000)
```

Sign in with `admin@accountgold.local` / `ChangeMe123` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `api/.env` before seeding).

Tests: `cd api && php artisan test` (runs on in-memory SQLite).

## How it works

**Branch context.** The app sends the active branch in an `X-Branch-Id` header. Middleware checks the user belongs
to that branch, sets it as the permission "team" (so roles resolve per branch), and a `BranchScoped` global scope
filters branch-owned models, so a missing `where` can never leak another branch's data. Super Admin can send `all`
for the consolidated view.

**Roles.** Permissions are fixed in code (`database/seeders/PermissionSeeder.php`). Roles are created per branch
from those permissions. Roles with `branch_id = NULL` are global templates (Branch Admin, Accountant, Cashier, Viewer).
Nobody can grant a permission they don't hold.

**Money.** All postings go through `app/Services/LedgerService.php`:
each transaction writes balanced ledger entries (debit = money in, credit = money out) inside one DB transaction with
row locks on the accounts, updates the cached `accounts.current_balance`, and writes an audit log. Nothing is deleted:
income/expense entries are voided, transfers are reversed. Locked periods (`period_locks`) reject postings.
Amounts are handled as integer cents (`app/Support/Money.php`), never floats.

| Transaction type | Effect |
|---|---|
| `capital` | + Main account |
| `opening_allocation` / `fund_allocation` | Main account → branch account |
| `fund_return` | branch account → Main account |
| `branch_transfer` | branch A → branch B |
| `internal_transfer` | account → account, same branch |
| `income` / `expense` | + / − branch account, with category |
| `reversal` | exact opposite of the original |

## API (v1)

All under `/api/v1`, bearer token from `POST auth/login`, plus `X-Branch-Id`.

`GET me` · `GET dashboard` · `GET|POST branches` · `PUT branches/{id}` · `GET|POST accounts` · `PUT accounts/{id}` ·
`GET accounts/{id}/statement?from&to` · `GET accounts/destinations` · `GET|POST categories` · `PUT categories/{id}` ·
`GET transactions` · `GET|PUT transactions/{id}` · `POST transactions/{id}/void` · `POST transactions/{id}/reverse` ·
`POST incomes` · `POST expenses` · `POST capital` · `POST allocations` · `POST transfers` ·
`GET permissions` · `GET|POST roles` · `PUT roles/{id}` · `GET|POST users` · `PUT users/{id}` · `POST users/{id}/role`

## Status against the backlog (docs/Account-gold_Product_Backlog.xlsx)

Done in this first cut (API + UI unless noted):
AG-001 login + lockout, AG-003 branch switcher, AG-004 permission checks, AG-010/011/012 branches,
AG-020/021/022/023/024 users and custom roles (API; UI screens next), AG-030/031/032 accounts and statement,
AG-040/041/043 opening balance, top-up, capital, AG-042 fund return, AG-050/053/054 transfers and reversal,
AG-060/061/063/065 income, expense, void, list and filters, AG-070/072 categories (API; tree editor UI next),
AG-080/081 dashboards, AG-090 audit log (writing; viewer UI next), AG-092 settings table, AG-091 period lock (enforced; UI next).

Next up: users and roles screens, category editor, attachments (AG-062), reports AG-082 to AG-085 with PDF/Excel export,
password reset (AG-002), audit log viewer, then the R2 approval workflow.
