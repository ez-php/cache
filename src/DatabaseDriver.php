<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use EzPhp\Contracts\DatabaseInterface;
use PDO;

/**
 * Class DatabaseDriver
 *
 * Cache driver on `ez-php/contracts`' DatabaseInterface — a shared cache for
 * applications that have a database but no Redis/Memcached. Entries live in a
 * `cache` table (`cache_key`, `value`, `expiration`), locks in `cache_locks`;
 * both tables are created automatically (`CREATE TABLE IF NOT EXISTS`) with
 * driver-aware DDL — MySQL, and a portable form for SQLite and PostgreSQL —
 * the same pattern as ez-php/session's DatabaseSessionHandler.
 *
 * Values are `serialize()`d and restored with `allowed_classes => false`, like
 * the other persistent drivers (see CacheValue). `expiration` is a Unix
 * timestamp, 0 = never; expired rows are treated as misses and deleted on read.
 * A key that is never read again stays until prune() — run it periodically
 * (e.g. a scheduled task); the `expiration` column is indexed for it.
 * `set()` is a single driver-aware upsert. `flush()` empties this driver's
 * `cache` table only — unlike RedisDriver/MemcachedDriver it touches nothing else.
 *
 * increment()/decrement() are read-modify-write inside one transaction; under
 * heavy concurrency on the same key prefer RedisDriver.
 *
 * @package EzPhp\Cache
 */
final class DatabaseDriver implements CacheInterface
{
    use TaggableDriverTrait;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * DatabaseDriver Constructor
     *
     * @param DatabaseInterface $db
     * @param string            $table     Cache table; interpolated into SQL, so limited to `[A-Za-z0-9_]`.
     * @param string            $lockTable Lock table; same restriction.
     *
     * @throws CacheException When a table name contains anything but letters, digits or underscores.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table = 'cache',
        private readonly string $lockTable = 'cache_locks',
    ) {
        foreach ([$table, $lockTable] as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new CacheException("Invalid cache table name '{$name}': only [A-Za-z0-9_] are allowed.");
            }
        }

        $this->createTablesIfNeeded();
    }

    /**
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->read($key);

        if ($raw === null) {
            $this->misses++;

            return $default;
        }

        $this->hits++;

        return self::decode($raw);
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl   0 = no expiry, > 0 = seconds, < 0 = already expired (the key is removed).
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        CacheValue::assertStorable($key, $value);

        if ($ttl < 0) {
            $this->forget($key);

            return;
        }

        $expiration = $ttl > 0 ? time() + $ttl : 0;
        $sql = "INSERT INTO {$this->table} (cache_key, value, expiration) VALUES (:key, :value, :expiration)";
        $bindings = ['key' => $key, 'value' => serialize($value), 'expiration' => $expiration];

        if ($this->isMysql()) {
            // Separate placeholders: VALUES() in ON DUPLICATE KEY is deprecated since MySQL 8.0.20.
            $sql .= ' ON DUPLICATE KEY UPDATE value = :update_value, expiration = :update_expiration';
            $bindings += ['update_value' => $bindings['value'], 'update_expiration' => $expiration];
        } else {
            // SQLite 3.24+ and PostgreSQL 9.5+.
            $sql .= ' ON CONFLICT (cache_key) DO UPDATE SET value = excluded.value, expiration = excluded.expiration';
        }

        $this->db->execute($sql, $bindings);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $this->db->execute("DELETE FROM {$this->table} WHERE cache_key = ?", [$key]);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    /**
     * @param string           $key
     * @param int              $ttl
     * @param Closure(): mixed $callback
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $raw = $this->read($key);

        if ($raw !== null) {
            $this->hits++;

            return self::decode($raw);
        }

        $this->misses++;

        $computed = $callback();
        $this->set($key, $computed, $ttl);

        return $computed;
    }

    /**
     * Delete every entry of this driver's cache table (other tables are untouched).
     *
     * @return void
     */
    public function flush(): void
    {
        $this->db->execute("DELETE FROM {$this->table}");
    }

    /**
     * Increment a numeric value inside one transaction. Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function increment(string $key, int $amount = 1): int
    {
        return $this->adjust($key, $amount);
    }

    /**
     * Decrement a numeric value inside one transaction. Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->adjust($key, -$amount);
    }

    /**
     * Return a lock stored in this driver's lock table.
     *
     * @param string $key
     * @param int    $ttl Seconds until the lock expires; 0 means never expire.
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new DatabaseLock($this->db, $this->lockTable, $key, $ttl);
    }

    /**
     * Return hit/miss statistics for this driver instance.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats
    {
        return new CacheStats($this->hits, $this->misses);
    }

    /**
     * Add $delta to the stored number, keeping the entry's expiration.
     *
     * @param string $key
     * @param int    $delta
     *
     * @return int
     */
    private function adjust(string $key, int $delta): int
    {
        /** @var int */
        return $this->db->transaction(function () use ($key, $delta): int {
            $rows = $this->lockedRead($key);
            $row = $rows[0] ?? null;
            $current = 0;
            $ttl = 0;

            if ($row !== null && !self::isExpired($row['expiration'] ?? 0)) {
                $value = is_string($row['value'] ?? null) ? self::decode($row['value']) : 0;
                $current = is_int($value) ? $value : (int) (is_scalar($value) ? $value : 0);
                $rawExpiration = $row['expiration'] ?? 0;
                $expiration = is_numeric($rawExpiration) ? (int) $rawExpiration : 0;
                $ttl = $expiration > 0 ? max(1, $expiration - time()) : 0;
            }

            $new = $current + $delta;
            $this->set($key, $new, $ttl);

            return $new;
        });
    }

    /**
     * Read a row for read-modify-write, holding a row lock until the surrounding transaction ends.
     *
     * A plain SELECT is a non-locking snapshot read on MySQL/PostgreSQL, so two concurrent
     * increments would both read the same value and one update would be lost. The no-op
     * upsert first makes sure the row exists — an absent key has no row to lock, and two
     * transactions inserting it would otherwise race on the primary key — and locks it;
     * `FOR UPDATE` then reads the committed value. SQLite serialises writers on its own and
     * has no `FOR UPDATE`.
     *
     * @param string $key
     *
     * @return list<array<string, mixed>>
     */
    private function lockedRead(string $key): array
    {
        $driver = $this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $select = "SELECT value, expiration FROM {$this->table} WHERE cache_key = ?";

        if ($driver === 'sqlite') {
            return $this->db->query($select, [$key]);
        }

        $insert = "INSERT INTO {$this->table} (cache_key, value, expiration) VALUES (?, ?, ?)";
        $insert .= $driver === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE cache_key = cache_key'
            : ' ON CONFLICT (cache_key) DO NOTHING';

        // Placeholder: expired (expiration 1), so it reads as 0 and is overwritten by set().
        $this->db->execute($insert, [$key, serialize(0), 1]);

        return $this->db->query($select . ' FOR UPDATE', [$key]);
    }

    /**
     * Fetch the raw serialised value, or null on a miss. Expired rows are deleted.
     *
     * @param string $key
     *
     * @return string|null
     */
    private function read(string $key): ?string
    {
        $rows = $this->db->query(
            "SELECT value, expiration FROM {$this->table} WHERE cache_key = ?",
            [$key],
        );
        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        if (self::isExpired($row['expiration'] ?? 0)) {
            $this->forget($key);

            return null;
        }

        $value = $row['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param mixed $expiration Raw column value; 0 = never.
     *
     * @return bool
     */
    private static function isExpired(mixed $expiration): bool
    {
        $at = is_numeric($expiration) ? (int) $expiration : 0;

        return $at > 0 && $at <= time();
    }

    /**
     * @param string $raw
     *
     * @return mixed
     */
    private static function decode(string $raw): mixed
    {
        /** @var mixed $value */
        $value = unserialize($raw, ['allowed_classes' => false]);

        return $value;
    }

    /**
     * Delete every expired entry (and expired lock) in one statement each —
     * read() only removes an expired row when that same key is read again,
     * so write-once keys would otherwise accumulate forever.
     *
     * @return int Number of cache entries deleted (expired locks are not counted).
     */
    public function prune(): int
    {
        $now = time();
        $this->db->execute("DELETE FROM {$this->lockTable} WHERE expiration > 0 AND expiration <= ?", [$now]);

        return $this->db->execute("DELETE FROM {$this->table} WHERE expiration > 0 AND expiration <= ?", [$now]);
    }

    /**
     * @return bool
     */
    private function isMysql(): bool
    {
        return $this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * Create the cache and lock tables if they do not exist yet — one cheap
     * `IF NOT EXISTS` statement per table and construction, so the driver
     * works without a migration step.
     *
     * @return void
     */
    private function createTablesIfNeeded(): void
    {
        $pdo = $this->db->getPdo();

        if (!$this->isMysql()) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    cache_key  VARCHAR(255) PRIMARY KEY,
                    value      TEXT         NOT NULL,
                    expiration BIGINT       NOT NULL
                )"
            );
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$this->table}_expiration_idx ON {$this->table} (expiration)");
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->lockTable} (
                    lock_key   VARCHAR(255) PRIMARY KEY,
                    owner      VARCHAR(64)  NOT NULL,
                    expiration BIGINT       NOT NULL
                )"
            );

            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                cache_key  VARCHAR(255) PRIMARY KEY,
                value      LONGTEXT     NOT NULL,
                expiration INT UNSIGNED NOT NULL,
                INDEX {$this->table}_expiration_idx (expiration)
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->lockTable} (
                lock_key   VARCHAR(255) PRIMARY KEY,
                owner      VARCHAR(64)  NOT NULL,
                expiration INT UNSIGNED NOT NULL
            )"
        );
    }
}
