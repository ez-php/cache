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
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
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

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3310** | **6381** |

Only set a port for services the module actually uses. Modules without external services need no port config.

---

# Package: ez-php/cache

Array, file, and Redis cache drivers for ez-php applications.

---

## Source Structure

```
src/
├── CacheInterface.php         — Unified contract for all drivers: get/set/forget/has/remember
├── ArrayDriver.php            — In-memory driver; data lives for the request lifetime only
├── FileDriver.php             — Filesystem driver; serialised entries keyed by MD5 filename
├── RedisDriver.php            — Redis driver via ext-redis; serialised values, native TTL
└── CacheServiceProvider.php   — Reads config/cache.php and binds CacheInterface to the chosen driver

tests/
├── TestCase.php               — Base PHPUnit test case
├── ArrayDriverTest.php        — Full CacheInterface contract + flush tested against ArrayDriver
├── FileDriverTest.php         — Full CacheInterface contract + flush; uses sys_get_temp_dir()
├── RedisDriverTest.php        — Full CacheInterface contract + flush; requires live Redis
├── Cache/
│   └── ApplicationTestCase.php — Extends EzPhp\Testing\ApplicationTestCase; overrides getBasePath() to write config/cache.php that reads CACHE_* env vars at require-time
└── CacheServiceProviderTest.php — Verifies driver selection from config; extends Tests\Cache\ApplicationTestCase
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

### CacheServiceProvider (`src/CacheServiceProvider.php`)

Reads `config/cache.php` and binds `CacheInterface` lazily to the matching driver.

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `cache.driver` | string | `'array'` | `'array'`, `'file'`, or `'redis'` |
| `cache.file_path` | string | `sys_get_temp_dir() . '/ez-cache'` | Directory for FileDriver |
| `cache.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `cache.redis.port` | int | `6379` | Redis port |
| `cache.redis.database` | int | `0` | Redis database index |

Unknown driver values fall back to `ArrayDriver`.

---

## Design Decisions and Constraints

- **`flush()` is on the interface** — All three drivers implement it; the operation is part of the cache contract. Callers holding a `CacheInterface` reference can flush without an unsafe cast. Note that `RedisDriver::flush()` calls `Redis::flushDB()` — it clears the entire selected database, not just this module's keys. Use a dedicated database index (`cache.redis.database`) to isolate cache data.
- **MD5 filenames in FileDriver** — Keys may contain characters unsafe for filenames. MD5 is not cryptographic here; it is a deterministic, fixed-length, filesystem-safe encoding. Collision risk is negligible for cache keys.
- **`ext-redis`, not Predis** — The native extension is faster and has no PHP dependencies. Applications that cannot install the extension should use `FileDriver`.
- **No key prefixing** — This module does not namespace keys. If multiple applications share a Redis database or cache directory, key collisions are the application's responsibility (use `cache.redis.database` or set a `cache.file_path` per application).
- **No tagging or invalidation groups** — Out of scope. Tags belong in a higher-level cache abstraction if needed.
- **Serialisation in Redis** — `get()` calls `unserialize()` on the raw string. If the value was written outside this driver, the result is undefined. Never mix raw Redis writes with `RedisDriver`.

---

## Testing Approach

- **ArrayDriver and FileDriver** — No external infrastructure. `FileDriverTest` uses `sys_get_temp_dir()` and cleans up via `flush()` in `tearDown`.
- **RedisDriver** — Requires a live Redis instance (available via Docker). Tests that need Redis are marked or grouped so they can be skipped in environments without the `ext-redis` extension.
- **Contract tests** — Each driver test covers the full `CacheInterface` contract: get/set/forget/has/remember, TTL expiry, and negative TTL.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Cache key prefixing / namespacing | Application layer or a wrapping decorator |
| Cache tagging and tag-based invalidation | Higher-level cache abstraction |
| Session storage | PHP native sessions or a dedicated session driver |
| HTTP response caching (reverse proxy) | Infrastructure layer (Nginx, Varnish) |
| Queue / job storage | `ez-php/queue` |
| Rate limiting counters | `ez-php/rate-limiter` |
| ORM query result caching | `ez-php/orm` |

