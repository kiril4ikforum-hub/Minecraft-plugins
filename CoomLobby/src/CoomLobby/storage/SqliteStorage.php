<?php

declare(strict_types=1);

namespace CoomLobby\storage;

use function array_map;
use function dirname;
use function file_exists;
use function is_dir;
use function mkdir;
use function strtolower;

/**
 * SQLite backed player storage using the PDO driver shipped with the official
 * PocketMine-MP PHP binaries.
 *
 * The table layout is intentionally tiny so that a single-row upsert stays in
 * the sub-millisecond range on the main thread. Every failure degrades to a
 * no-op with a console error instead of propagating an exception into the
 * event handlers.
 */
final class SqliteStorage implements StorageProvider
{
    private const TABLE = 'coomlobby_players';

    private string $file;
    private \Logger $logger;
    private ?\PDO $pdo = null;
    private ?string $lastError = null;
    private bool $reportedFailure = false;

    public function __construct(string $file, \Logger $logger)
    {
        $this->file = $file;
        $this->logger = $logger;
        $this->connect();
    }

    private function connect() : void
    {
        $directory = dirname($this->file);
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                    name TEXT PRIMARY KEY COLLATE NOCASE,
                    display_name TEXT NOT NULL,
                    last_mode TEXT NULL,
                    teleports INTEGER NOT NULL DEFAULT 0,
                    first_seen INTEGER NOT NULL DEFAULT 0,
                    last_seen INTEGER NOT NULL DEFAULT 0
                )'
            );

            $this->pdo = $pdo;
            $this->lastError = null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->pdo = null;
            $this->logger->error('Could not open the SQLite database (' . $this->file . '): ' . $e->getMessage());
        }
    }

    public function isAvailable() : bool
    {
        return $this->pdo !== null;
    }

    public function getFile() : string
    {
        return $this->file;
    }

    public function load(string $playerName) : ?PlayerProfile
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            $statement = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE name = :name LIMIT 1');
            $statement->execute([':name' => strtolower($playerName)]);
            $row = $statement->fetch();
        } catch (\Throwable $e) {
            $this->reportFailure('read', $e);

            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        return new PlayerProfile(
            (string) ($row['display_name'] ?? $playerName),
            isset($row['last_mode']) && $row['last_mode'] !== null ? (string) $row['last_mode'] : null,
            (int) ($row['teleports'] ?? 0),
            (int) ($row['first_seen'] ?? 0),
            (int) ($row['last_seen'] ?? 0)
        );
    }

    public function loadOrCreate(string $playerName) : PlayerProfile
    {
        return $this->load($playerName) ?? PlayerProfile::create($playerName);
    }

    public function save(PlayerProfile $profile) : void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (name, display_name, last_mode, teleports, first_seen, last_seen)
                 VALUES (:name, :display_name, :last_mode, :teleports, :first_seen, :last_seen)
                 ON CONFLICT(name) DO UPDATE SET
                    display_name = excluded.display_name,
                    last_mode = excluded.last_mode,
                    teleports = excluded.teleports,
                    first_seen = excluded.first_seen,
                    last_seen = excluded.last_seen'
            );
            $statement->execute([
                ':name' => $profile->getKey(),
                ':display_name' => $profile->getName(),
                ':last_mode' => $profile->getLastMode(),
                ':teleports' => $profile->getTeleports(),
                ':first_seen' => $profile->getFirstSeen(),
                ':last_seen' => $profile->getLastSeen(),
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure('write', $e);
        }
    }

    public function loadAll() : array
    {
        if ($this->pdo === null) {
            return [];
        }

        try {
            $statement = $this->pdo->query('SELECT * FROM ' . self::TABLE);
            $rows = $statement === false ? [] : $statement->fetchAll();
        } catch (\Throwable $e) {
            $this->reportFailure('read', $e);

            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $profile = new PlayerProfile(
                (string) ($row['display_name'] ?? $row['name'] ?? ''),
                isset($row['last_mode']) && $row['last_mode'] !== null ? (string) $row['last_mode'] : null,
                (int) ($row['teleports'] ?? 0),
                (int) ($row['first_seen'] ?? 0),
                (int) ($row['last_seen'] ?? 0)
            );
            $profiles[$profile->getKey()] = $profile;
        }

        return $profiles;
    }

    public function count() : int
    {
        if ($this->pdo === null) {
            return 0;
        }

        try {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE);
            $value = $statement === false ? 0 : $statement->fetchColumn();
        } catch (\Throwable $e) {
            $this->reportFailure('read', $e);

            return 0;
        }

        return (int) $value;
    }

    public function getType() : string
    {
        return 'sqlite';
    }

    public function getStatus() : string
    {
        if ($this->pdo === null) {
            return 'sqlite (unavailable: ' . ($this->lastError ?? 'unknown error') . ')';
        }

        return 'sqlite (' . $this->file . ', ' . $this->count() . ' profiles)';
    }

    public function close() : void
    {
        $this->pdo = null;
    }

    private function reportFailure(string $operation, \Throwable $e) : void
    {
        if ($this->reportedFailure) {
            return;
        }

        $this->reportedFailure = true;
        $this->lastError = $e->getMessage();
        $this->logger->error('SQLite ' . $operation . ' failed: ' . $e->getMessage() . ' - player statistics are not persisted.');
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function filterRows(array $rows) : array
    {
        return array_map(static fn (mixed $row) : array => is_array($row) ? $row : [], $rows);
    }

    /**
     * Ensures that a nested file path can be created next to the database.
     */
    public static function ensureFileDirectory(string $file) : bool
    {
        $directory = dirname($file);

        return $directory === '' || is_dir($directory) || mkdir($directory, 0755, true) || file_exists($directory);
    }
}
