# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT`, `HEALTH_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/cache

Array, file, and Redis cache drivers for ez-php applications.

---

## Source Structure

```
src/
├── CacheInterface.php         — Unified contract for all drivers: get/set/forget/has/remember/lock/tags/stats
├── LockInterface.php          — Contract for distributed/process-level cache locks: acquire/release/get(Closure)
├── CacheException.php         — Base exception for all cache driver errors (extends RuntimeException)
├── ArrayDriver.php            — In-memory driver; data lives for the request lifetime only
├── FileDriver.php             — Filesystem driver; serialised entries keyed by MD5 filename
├── RedisDriver.php            — Redis driver via ext-redis; serialised values, native TTL; RedisLock support
├── MemcachedDriver.php        — Memcached driver via ext-memcached; serialised values; MemcachedLock support
├── RedisLock.php              — LockInterface impl using Redis SET NX
├── MemcachedLock.php          — LockInterface impl using Memcached::add() (add-if-not-exists)
├── ArrayLock.php              — LockInterface impl backed by in-process array (for ArrayDriver)
├── FileLock.php               — LockInterface impl using advisory file locks (flock)
├── TaggableDriverTrait.php    — Provides tags() → TaggedCache for all drivers
├── TaggedCache.php            — Scoped cache view: all keys prefixed with tag hash
├── CacheStats.php             — Immutable value object: hits, misses
├── CacheValue.php             — assertStorable(): every driver's set() rejects objects/resources (null, scalars, arrays only)
├── StampedeProtectedCache.php — Decorator: probabilistic early recompute to prevent cache stampedes
├── Cache.php                  — Static facade backed by a deferred-resolver singleton; one-line delegations to CacheInterface
└── CacheServiceProvider.php   — Reads config/cache.php and binds CacheInterface to the chosen driver; wires Cache facade in boot()

tests/
├── TestCase.php               — Base PHPUnit test case
├── ArrayDriverTest.php        — Full CacheInterface contract + flush tested against ArrayDriver
├── CacheValueTest.php         — Value contract on Array/File/Redis: scalars/arrays round-trip, objects (also nested, also via remember()) and resources rejected
├── FileDriverTest.php         — Full CacheInterface contract + flush; uses sys_get_temp_dir()
├── RedisDriverTest.php        — Full CacheInterface contract + flush; requires live Redis (#[Group('redis')])
├── MemcachedDriverTest.php    — Full CacheInterface contract + flush; requires live Memcached (#[Group('memcached')])
├── CacheTest.php              — Covers Cache facade: delegation of every CacheInterface method, uninitialized throw, reset
├── Cache/
│   └── ApplicationTestCase.php — Extends EzPhp\Testing\ApplicationTestCase; overrides getBasePath() to write config/cache.php that reads CACHE_* env vars at require-time
└── CacheServiceProviderTest.php — Verifies driver selection from config and deferred facade wiring; extends Tests\Cache\ApplicationTestCase
```

---

## Key Classes and Responsibilities

### CacheInterface (`src/CacheInterface.php`)

The single contract all drivers implement.

| Method | Signature | Behaviour |
|---|---|---|
| `get` | `get(string $key, mixed $default = null): mixed` | Returns stored value or `$default` on miss/expiry |
| `set` | `set(string $key, mixed $value, int $ttl = 0): void` | Stores value; `ttl=0` = no expiry, `ttl>0` = expires in N seconds, `ttl<0` = already expired |
| `forget` | `forget(string $key): void` | Removes an entry; no-op if absent |
| `has` | `has(string $key): bool` | `true` only if key exists **and** has not expired |
| `remember` | `remember(string $key, int $ttl, Closure $callback): mixed` | Returns cached value on hit; calls `$callback`, stores, and returns result on miss |
| `flush` | `flush(): void` | Removes all items from the cache |

---

### ArrayDriver (`src/ArrayDriver.php`)

In-memory store. Each entry is `['value' => mixed, 'expires' => int|null]`. Expiry is checked lazily on read via the private `read()` helper, which also removes stale entries.

- No persistence — data is lost when the PHP process ends
- Safe for use in tests without cleanup; isolated per instance
- `flush()` resets `$store` to `[]`

---

### FileDriver (`src/FileDriver.php`)

Filesystem store. Each entry is a PHP-`serialize`d file in `$directory`.

- Filename: `md5($key) . '.cache'` — safe, fixed-length, collision-resistant for typical use
- Directory is created automatically with `mkdir(0o755, recursive: true)` if absent
- Expiry is checked lazily on `read()`; expired files are deleted on access
- `flush()` deletes all `*.cache` files in the directory
- Concurrent writes are protected with `LOCK_EX` on `file_put_contents`

---

### RedisDriver (`src/RedisDriver.php`)

Redis store via the PHP `ext-redis` extension. Throws `RuntimeException` at construction if the extension is not loaded.

- Values are PHP-`serialize`d before storage so any serialisable type is supported
- TTL > 0: uses `Redis::setex()` (native Redis TTL — no lazy expiry needed)
- TTL = 0: uses `Redis::set()` (no expiry)
- Negative TTL: stored with `setex()` using a past timestamp — Redis expires it immediately
- `flush()` calls `Redis::flushDB()` — clears the **entire selected database**, not just this module's keys
- Default database: `0`; non-zero database selected via `Redis::select()`

---

### MemcachedDriver (`src/MemcachedDriver.php`)

Memcached store via the PHP `ext-memcached` extension. Throws `RuntimeException` at construction if the extension is not loaded.

- Accepts a list of server configs: `[['host' => '...', 'port' => 11211, 'weight' => 0]]`
- Uses `Memcached::SERIALIZER_PHP` so any serialisable type is supported
- TTL 0 = no expiry; TTL > 0 = N seconds; TTL < 0 = stored with TTL=1 (evicted almost immediately)
- `flush()` calls `Memcached::flush()` — clears the **entire connected server**, not just this module's keys
- Lock via `MemcachedLock` using `Memcached::add()` (atomic add-if-not-exists)

---

### Cache (`src/Cache.php`)

Static facade following the same pattern as `Broadcast`, `Flag`, `Log`, `Mail`, `Metrics`, and `View`. Every static method (`get`, `set`, `forget`, `has`, `remember`, `flush`, `increment`, `decrement`, `lock`, `tags`, `stats`) is a one-line delegation to the underlying `CacheInterface` — the facade holds no cache state or logic of its own.

Holds `private static ?CacheInterface $instance` plus a deferred `?Closure $resolver`. `setInstance()` sets the instance directly (used by tests). `setResolver()` stores a closure that is invoked — and its result cached — on the *first* actual facade call, not immediately. `CacheServiceProvider::boot()` calls `setResolver()` rather than eagerly resolving `CacheInterface` itself, so that registering the provider does not force the container binding to resolve (and be permanently cached as a singleton) before the application has finished configuring the driver. Throws `RuntimeException` (fail-fast) when neither an instance nor a resolver has been set. `resetInstance()` clears both for test teardown.

---

### CacheServiceProvider (`src/CacheServiceProvider.php`)

Reads `config/cache.php` and binds `CacheInterface` lazily to the matching driver.

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `cache.driver` | string | `'array'` | `'array'`, `'file'`, `'redis'`, or `'memcached'` |
| `cache.file_path` | string | `sys_get_temp_dir() . '/ez-cache'` | Directory for FileDriver |
| `cache.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `cache.redis.port` | int | `6379` | Redis port |
| `cache.redis.database` | int | `0` | Redis database index |
| `cache.memcached.host` | string | `'127.0.0.1'` | Memcached hostname |
| `cache.memcached.port` | int | `11211` | Memcached port |
| `cache.memcached.weight` | int | `0` | Server weight (0 = equal weight) |

Unknown driver values fall back to `ArrayDriver`. `boot()` calls `Cache::setResolver()` to wire the static facade (see `Cache` above for why resolution is deferred rather than eager).

---

## Design Decisions and Constraints

- **`flush()` is on the interface** — All three drivers implement it; the operation is part of the cache contract. Callers holding a `CacheInterface` reference can flush without an unsafe cast. Note that `RedisDriver::flush()` calls `Redis::flushDB()` — it clears the entire selected database, not just this module's keys. Use a dedicated database index (`cache.redis.database`) to isolate cache data.
- **MD5 filenames in FileDriver** — Keys may contain characters unsafe for filenames. MD5 is not cryptographic here; it is a deterministic, fixed-length, filesystem-safe encoding. Collision risk is negligible for cache keys.
- **`ext-redis`, not Predis** — The native extension is faster and has no PHP dependencies. Applications that cannot install the extension should use `FileDriver`.
- **No key prefixing** — This module does not namespace keys. If multiple applications share a Redis database or cache directory, key collisions are the application's responsibility (use `cache.redis.database` or set a `cache.file_path` per application).
- **No tagging or invalidation groups** — Out of scope. Tags belong in a higher-level cache abstraction if needed.
- **Values are limited to null, scalars and arrays — on every driver** — `FileDriver`/`RedisDriver` unserialize with `allowed_classes => false` so a tampered cache entry can never instantiate a class; an object would therefore come back as `__PHP_Incomplete_Class` there, while `ArrayDriver` (what the tests use) returned the real object. That mismatch broke `ez-php/exchange`'s `CachingExchangeRateProvider` in production only. `CacheValue::assertStorable()` runs in every driver's `set()` (and therefore `remember()`), so storing an object fails immediately and identically everywhere. Callers cache a scalar/array representation (`BigDecimal::toString()`, `toArray()`) and rebuild the object after reading.
- **Serialisation in Redis** — `get()` calls `unserialize()` on the raw string. If the value was written outside this driver, the result is undefined. Never mix raw Redis writes with `RedisDriver`.
- **`Cache` facade resolution is deferred, not eager** — Unlike some other facades in the codebase, `CacheServiceProvider::boot()` does not call `$app->make(CacheInterface::class)` directly. Doing so would resolve — and permanently cache as a container singleton — whatever driver the config happened to select at bootstrap time, before application or test code has a chance to configure it afterward (see `CacheServiceProviderTest`, which sets `CACHE_DRIVER`/`CACHE_PATH` env vars inside individual test methods, after `setUp()`/bootstrap has already run). `Cache::setResolver()` stores a closure instead, resolved only on the first real facade call.

---

## Testing Approach

- **ArrayDriver and FileDriver** — No external infrastructure. `FileDriverTest` uses `sys_get_temp_dir()` and cleans up via `flush()` in `tearDown`.
- **RedisDriver** — Requires a live Redis instance (available via Docker). Tests that need Redis are marked or grouped so they can be skipped in environments without the `ext-redis` extension.
- **Contract tests** — Each driver test covers the full `CacheInterface` contract: get/set/forget/has/remember, TTL expiry, and negative TTL.
- **`Cache` facade tests** — Use the real `ArrayDriver` as the backing implementation (not a mock) via `Cache::setInstance()`, consistent with this module's general no-mocking-framework approach. Always call `Cache::resetInstance()` in `setUp()`/`tearDown()` of any test touching the facade.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Cache key prefixing / namespacing | Application layer or a wrapping decorator |
| Session storage | PHP native sessions or a dedicated session driver |
| HTTP response caching (reverse proxy) | Infrastructure layer (Nginx, Varnish) |
| Queue / job storage | `ez-php/queue` |
| Rate limiting counters | `ez-php/rate-limiter` |
| ORM query result caching | `ez-php/orm` |
