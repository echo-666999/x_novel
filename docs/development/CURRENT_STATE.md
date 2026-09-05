# XNovel Current State Audit

> Task: `TASK-001 — 当前项目环境审计`  
> Audit date: 2026-09-05  
> Scope: 只记录现状，不修改应用代码、配置或架构。

## 1. Executive Summary

XNovel 当前处于 Laravel / Filament 基础骨架阶段。

Laravel、Filament、PostgreSQL、pgvector、Redis 与 Horizon 的基础依赖已经存在；数据库和 Redis 可连接，基础 migration 已执行。项目尚未开始实现 Novel、Story State、Generation、Review、Memory 等领域模型与工作流。

当前可以继续进入 `TASK-002 — Filament 导航与视觉基线`，但在后续生成任务开始前，需要将默认 Queue connection 与项目约定对齐，并启动 Horizon worker。

## 2. Runtime And Dependency Versions

| Component | Current state | Verification |
|---|---|---|
| PHP | 8.4.23 | `php -v` |
| Composer | 2.10.2 | `php artisan about` |
| Laravel | 13.30.1 | `composer show laravel/framework` |
| Filament | 5.7.8 | `composer show filament/filament` |
| Livewire | 4.4.3 | `php artisan about` |
| Horizon | 5.48.3 | `composer show laravel/horizon` |
| Pest | 5.1.3 | `composer show --direct` |
| Pest Laravel Plugin | 5.0.1 | `composer show --direct` |
| Tailwind CSS | 4.3.3 | `npm list --depth=0` |
| Vite | 8.2.2 | `npm list --depth=0` |
| PostgreSQL PHP support | `pdo_pgsql` and `pgsql` loaded | `php -m` |
| Redis PHP support | `redis` loaded | `php -m` |

## 3. Application Baseline

### 3.1 Existing Models

- `App\Models\User`
- Uses `HasFactory` and `Notifiable`.
- Password cast is configured as `hashed`.

No XNovel domain models currently exist.

### 3.2 Existing Migrations

| Migration | Database status |
|---|---|
| `0001_01_01_000000_create_users_table` | Ran, batch 1 |
| `0001_01_01_000001_create_cache_table` | Ran, batch 1 |
| `0001_01_01_000002_create_jobs_table` | Ran, batch 1 |

The PostgreSQL connection uses the configured `x_` table prefix.

No Novel, Bible, Volume, Arc, Chapter, Story State, Generation, Review, Memory, or Usage migrations exist yet.

### 3.3 Existing Factories And Seeders

- `Database\Factories\UserFactory`
- `Database\Seeders\DatabaseSeeder`

No XNovel domain factories or seed data exist.

### 3.4 Existing Enums, DTOs, Actions, Services And Jobs

No project-specific Enums, DTOs, Actions, Services, or Jobs currently exist.

The database queue tables are provided by the default Laravel migration, but no XNovel queue jobs have been implemented.

### 3.5 Existing Tests

- `tests/Feature/ExampleTest.php`
- `tests/Unit/ExampleTest.php`
- Shared Pest configuration in `tests/Pest.php`

The current tests are Laravel starter examples. There are no domain, database-integrity, Filament, workflow, idempotency, recovery, or canonical commit tests.

Actual result:

```text
Tests: 2 passed
Assertions: 2 passed
Duration: 116 ms
```

## 4. Filament State

### 4.1 Panel

The `x` panel is registered at `/x` through `App\Providers\Filament\XPanelProvider`.

Registered Filament routes:

```text
GET|HEAD  /x
GET|HEAD  /x/login
POST      /x/logout
```

Current panel configuration:

- Login enabled.
- Full-width content enabled.
- Resource, Page, and Widget discovery enabled.
- Vite theme: `resources/css/filament/x/theme.css`.
- Primary color: Filament Amber.
- Authentication middleware enabled.

The Vite theme file imports the native Filament theme and scans project Filament / view paths. It does not yet implement the XNovel design tokens.

### 4.2 Existing Pages And Widgets

Pages:

- Filament default Dashboard only.

Widgets:

- `AccountWidget`
- `FilamentInfoWidget`

Resources:

- None.

Current top-level business navigation:

- None.

### 4.3 Login Verification

The Filament login page is verified through a temporary local Laravel server:

- `GET http://127.0.0.1:8765/x/login` returned HTTP 200.
- Content type was `text/html; charset=utf-8`.
- The temporary server was stopped immediately after the check.

The configured Herd domain remains unavailable during the audit: HTTP returned 404 and HTTPS had no listener. This is a local Herd site / web-server routing concern rather than a Filament or Laravel route failure.

### 4.4 Design Alignment

`DESIGN.md` defines XNovel's single primary accent as indigo-violet and requires complete Dark / Light support. The current Panel uses `Color::Amber` and the theme contains only the Filament import/source declarations.

This is an expected gap for `TASK-002`; it is not changed by TASK-001.

## 5. PostgreSQL And pgvector

### 5.1 PostgreSQL

The active environment uses PostgreSQL and the connection was verified outside the restricted sandbox by running `php artisan migrate:status` successfully.

Current database configuration includes:

- Driver: `pgsql`
- Search path: `public`
- Table prefix: `x_`
- SSL mode: configurable, default `prefer`

### 5.2 pgvector

The PostgreSQL `vector` extension is installed and enabled:

```text
extension: vector
version: 0.8.1
```

No vector-backed migrations, columns, indexes, models, or retrieval code exist yet.

## 6. Redis, Queue And Horizon

### 6.1 Redis

- PHP Redis extension is loaded.
- The configured Redis connection responds successfully to `PING`.
- Redis databases are configured for default and cache connections.
- `redis-cli` is not installed in the current shell environment; connectivity was verified through Laravel instead.

### 6.2 Queue

The active application Queue driver is currently:

```text
database
```

The architecture requires Redis-backed queues and limits the MVP queue names to:

```text
generation
default
```

The Redis queue connection exists in `config/queue.php`, but it is not the active default connection. No `generation` queue-specific workflow has been implemented yet.

### 6.3 Horizon

- `laravel/horizon` 5.48.3 is installed.
- `HorizonServiceProvider` is registered.
- `config/horizon.php` exists.
- Horizon routes are registered under `/horizon`.
- The Horizon supervisor is configured to use Redis.
- Current supervisor queue list contains only `default`.
- Current runtime status: `Horizon is inactive`.

The `generation` queue and production retry / timeout policy should be configured by the appropriate later generation-pipeline task rather than TASK-001.

## 7. Current Configuration

Active application state reported by `php artisan about`:

| Setting | Value |
|---|---|
| Environment | local |
| Debug | enabled |
| Locale | `zh_CN` |
| Timezone | UTC |
| Database | pgsql |
| Cache | database |
| Queue | database |
| Session | database |
| Logs | daily |
| Public storage link | missing |

`.env.example` now uses PostgreSQL, but still contains starter defaults that need later cleanup:

- `APP_NAME=Laravel`
- `DB_PORT=3306`, which is the MySQL default rather than PostgreSQL's default 5432.
- `QUEUE_CONNECTION=database`
- `CACHE_STORE=database`

These values are documented only; TASK-001 does not modify configuration.

## 8. Available Capabilities

- Laravel application boots successfully through Artisan.
- PostgreSQL connection works.
- Base Laravel migrations have run.
- pgvector 0.8.1 is enabled in PostgreSQL.
- Redis connection works through Laravel.
- Horizon is installed, configured, and exposes routes.
- Filament v5 Panel, login route, Dashboard, theme entrypoint, and authentication middleware are registered.
- Vite / Tailwind frontend build dependencies are installed.
- Pest is configured and the starter test suite passes.
- `DESIGN.md` and the project-specific `filament-ui` Skill are available.

## 9. Missing Capabilities

- XNovel domain Models and migrations.
- Domain Enums, DTOs, Actions, Services, and Jobs.
- Novel Workspace and all business Filament Resources / Pages / Widgets.
- Story State, Facts, Story Events, versioning, and canonical commit.
- Generation runs, artifacts, planning, scene generation, review, and rewrite.
- Context Builder, memories, embeddings, retrieval, and evaluation.
- Usage and cost tracking.
- Ending Controller, recovery, rollback, and rebuild tools.
- Domain-specific automated tests.
- Filament Shield is not installed; this is acceptable because the project is single-user and Shield is optional.

## 10. Conflicts And Gaps

### 10.1 Queue Driver Does Not Match Architecture

**Document says:** Redis supports Laravel Queue, and the generation pipeline uses `generation` and `default` queues.

**Current code does:** Active Queue connection is `database`; Horizon is configured for Redis and only monitors `default`.

**Impact:** Jobs dispatched to the active database connection would not be processed by the current Horizon Redis supervisor.

**Recommended resolution:** Align the active Queue connection and Horizon supervisor queue list in the task that establishes queue / generation infrastructure. Do not create additional queues beyond `generation` and `default` without measured need.

### 10.2 Filament Primary Color Does Not Match DESIGN.md

**Document says:** Use the XNovel indigo-violet primary accent and centralized design tokens.

**Current code does:** Panel uses Filament Amber; the custom theme has no XNovel token implementation.

**Impact:** Current UI does not yet represent the approved visual system.

**Recommended resolution:** Address in `TASK-002 — Filament 导航与视觉基线` using Filament's native color and theme APIs.

### 10.3 Herd Domain Is Not Serving The Application

**Document says:** TASK-001 should confirm that the Panel can log in normally.

**Current state:** Laravel registers `/x` and `/x/login`, and the login page returns HTTP 200 through `php artisan serve`. The configured `novel.test` Herd domain did not serve the application during the audit.

**Impact:** Filament's login page is operational, but the preferred local domain cannot currently be used.

**Recommended resolution:** Verify the Herd site mapping / web server separately; no Filament code change is indicated by the current evidence.

## 11. Reusable Components

- Laravel authentication User model and users table.
- Database-backed cache, sessions, jobs, batches, and failed-jobs tables where still appropriate.
- Filament `XPanelProvider`, native Dashboard, login flow, resource/page/widget discovery, and middleware stack.
- Existing Filament Vite theme entrypoint.
- Horizon package, provider, routes, and baseline configuration.
- PostgreSQL connection with the existing `x_` table prefix.
- Enabled pgvector extension.
- Redis connection and PHP Redis extension.
- Pest bootstrap, base TestCase, UserFactory, and DatabaseSeeder.
- Vite and Tailwind build pipeline.

## 12. Technical Debt And Risks

| Item | Severity | Notes |
|---|---|---|
| Active Queue uses database while Horizon consumes Redis | High before queue work | Must be aligned before generation Jobs are introduced. |
| Horizon is inactive | Medium | Expected when no worker process is running; required for asynchronous processing. |
| Herd domain is not serving this application | Medium | Filament login returns 200 through `artisan serve`; local domain mapping still needs attention. |
| `.env.example` PostgreSQL port is 3306 | Medium | New environments would receive an incorrect PostgreSQL default. |
| No domain tests | High before domain work | Only two starter tests exist. |
| Panel primary color differs from DESIGN.md | Low / planned | Belongs to TASK-002. |
| `viteTheme()` is configured twice with the same path | Low | Functionally valid but redundant; do not clean up outside an authorized task. |
| Public storage link is missing | Low | No current XNovel feature depends on it. |
| Starter metadata remains in Composer / `.env.example` | Low | Does not block TASK-002. |

## 13. TASK-001 Acceptance Check

- [x] Existing Composer and npm dependencies inspected.
- [x] Models, migrations, factories, seeders, enums, DTOs, services, actions, jobs, and tests inspected.
- [x] Filament Panel, routes, theme, pages, widgets, and navigation inspected.
- [x] PostgreSQL connection and migration status checked.
- [x] pgvector availability checked.
- [x] Redis connection checked.
- [x] Queue and Horizon configuration checked.
- [x] Existing tests executed.
- [x] Available capabilities documented.
- [x] Missing capabilities documented.
- [x] Documentation / code conflicts documented.
- [x] Reusable components documented.
- [x] Technical debt documented.
- [x] Filament login page response verified through a temporary local Laravel server.

## 14. Completion Decision

TASK-001's audit artifact is complete and the project's current structure no longer needs to be inferred.

The separate Herd domain mapping issue is explicitly recorded and does not justify modifying application code inside this audit task. The next implementation task remains `TASK-002 — Filament 导航与视觉基线`; queue/Horizon alignment belongs to its relevant later infrastructure or generation task.
