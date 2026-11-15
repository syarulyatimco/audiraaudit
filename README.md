# AUDIRA

AI-powered audit management platform for Malaysian external audit practices — client acceptance
through report sign-off, with specialised AI agents that prepare work and auditors who approve it.

**Status:** Phases 0–12 complete — foundation through completion, reporting, billing and monitoring, methodology libraries with Excel-style programme grids (Phase 11), management-prepared financial statements in the client portal with PDF output (Phase 12), and general ledger, journal entry testing and ageing analysis (Phase 13). See [docs/ROADMAP.md](docs/ROADMAP.md).

## Stack
Laravel 12 · PHP 8.3+ · PostgreSQL 16 (RLS; pgvector optional, for hybrid library search) · Redis · Blade + Alpine.js 3 + Tailwind CSS 3

## Local setup

```bash
# 1. PHP dependencies (generates composer.lock from Packagist)
composer update

# 2. PostgreSQL: the app role must NOT be a superuser (RLS is bypassed for superusers)
psql -U postgres -c "create role audira login password 'audira' nosuperuser nobypassrls"
psql -U postgres -c "create database audira owner audira"
psql -U postgres -c "create database audira_test owner audira"

# 3. Environment
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                       # plans
php artisan audira:create-superadmin you@example.com "Your Name"
php artisan db:seed --class=DemoSeeder           # optional demo firm (local only)

# 4. Run
php artisan serve                                # http://localhost:8000
php artisan queue:work --queue=ai,documents,default   # background jobs (Redis): AI runs, scans
php artisan schedule:work                        # daily engagement monitoring and billing run (cron `schedule:run` in production)
```

Front-end assets are pre-built in `public/css` and `public/js`. To rebuild after changing views or styles:
`npm install && npm run build` (or `npm run dev` to watch).

Production settings to review in `.env`: `AUDIRA_SCANNER=clamd` (+ `CLAMD_HOST`/`CLAMD_PORT`), `AUDIRA_DOCUMENTS_DISK=s3_documents` (after `composer require league/flysystem-aws-s3-v3`) and run a queue worker for the `documents` queue: `php artisan queue:work --queue=documents,default`.

AI agents (optional): set `AI_PROVIDER=anthropic` and `ANTHROPIC_API_KEY`, or `AI_PROVIDER=openai` with `OPENAI_API_KEY` (and `OPENAI_BASE_URL` for Azure or a local OpenAI-compatible server). Models per tier and prices are in `config/ai.php`. AI stays off for each firm until a firm admin enables it under **Firm › AI settings**; a worker must listen on the `ai` queue. Agents search the published Audit Library with full-text search; for hybrid (vector) search install pgvector, set `AI_RAG_DRIVER=hybrid` and `AI_EMBEDDINGS_API_KEY` (OpenAI-compatible endpoint), run `php artisan migrate` and `php artisan audira:library-embed`.

Client portal: invite client contacts from the client page (free users); they sign in at `/login` and land on `/portal`. PDF text for the Document Intelligence agent needs `pdftotext` (poppler-utils).

Reporting: firm admins or partners load the firm's approved auditor's-report wording under **Report templates** before the first sign-off — AUDIRA ships no standard wording.

Methodology: `php artisan migrate --seed` installs the AUDIRA methodology template (read-only). Partners or firm admins open **Methodology** and choose "Make a firm copy" to adapt it. On an engagement, **Fieldwork › Programmes** applies a library and creates the planning, controls and substantive programme grids; each grid downloads and uploads as .xlsx. For an existing database, run `php artisan db:seed --class=MethodologySeeder`.

Financial statements are prepared by management in the client portal (Financial statements on their engagement page) and reviewed by the audit team under **Completion › Management FS**; the report cannot be signed until a director-approved version is accepted. PDFs need Google Chrome or Chromium on the server (`AUDIRA_PDF_CHROME` if not on the PATH; run the app as a non-root user).

General ledger and ageing: Trial balance › General ledger / Ageing. Upload a CSV/XLSX (or choose a file the client uploaded in the portal), confirm the detected columns, and AUDIRA reconciles it to the TB.

Billing: invoices are issued by `php artisan audira:billing-run` (scheduled daily) using the `manual` gateway (bank transfer; platform staff record payments under **Admin › Invoices**). Tax is not applied until `AUDIRA_TAX_NAME` and `AUDIRA_TAX_RATE` are set after the tax position is confirmed. Firm admins can grant time-boxed, read-only support access under **Support access**.

Demo accounts (after `DemoSeeder`), password `Demo!Passw0rd#`. Partners, firm admins and the superadmin are asked to enrol an authenticator app on first sign-in:
- `farid@lim-farid.test` — partner & firm admin
- `aina@lim-farid.test` — manager · `rajesh@lim-farid.test` — senior · `chloe@lim-farid.test` — associate
- `admin@audira.test` — platform superadmin

## Tests
```bash
vendor/bin/phpunit      # runs against the audira_test PostgreSQL database
```

## Documentation
| Doc | Purpose |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Permanent project instructions |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System architecture, module dependency map, folder structure |
| [docs/DATABASE.md](docs/DATABASE.md) | ERD, schema, RLS |
| [docs/AI_AGENTS.md](docs/AI_AGENTS.md) | Agent responsibilities, tools and permissions |
| [docs/AUDIT_WORKFLOW.md](docs/AUDIT_WORKFLOW.md) | End-to-end audit workflow and approval gates |
| [docs/SECURITY.md](docs/SECURITY.md) | Security & multi-tenancy |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Phases, progress tracker, risks |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Architectural decision records |
| [docs/TESTING.md](docs/TESTING.md) | Test strategy and coverage |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Development history |
