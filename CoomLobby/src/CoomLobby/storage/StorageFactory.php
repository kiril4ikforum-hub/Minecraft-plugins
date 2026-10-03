<?php

declare(strict_types=1);

namespace CoomLobby\storage;

use CoomLobby\service\ConfigService;
use function class_exists;
use function in_array;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_starts_with;

/**
 * Creates the configured storage backend and transparently falls back to YAML
 * when SQLite is unavailable (missing driver, unwritable folder, ...).
 */
final class StorageFactory
{
    public static function create(ConfigService $config, string $dataFolder, \Logger $logger) : StorageProvider
    {
        $type = $config->getStorageType();
        $folder = rtrim($dataFolder, '/') . '/';

        if ($type === 'sqlite' || $type === 'auto') {
            if (!self::isSqliteAvailable()) {
                if ($type === 'sqlite') {
                    $logger->warning('SQLite was requested but the PDO SQLite driver is missing - using the YAML storage.');
                }
            } else {
                $file = self::resolvePath($folder, $config->getStorageSqliteFile());
                $storage = new SqliteStorage($file, $logger);
                if ($storage->isAvailable()) {
                    $logger->info('Player storage: SQLite (' . $file . ')');

                    return $storage;
                }

                $logger->warning('SQLite is not usable - falling back to the YAML storage.');
            }
        }

        $storage = new YamlStorage(self::resolvePath($folder, $config->getStorageYamlFile()), $logger);
        $logger->info('Player storage: YAML (' . $storage->getStatus() . ')');

        return $storage;
    }

    public static function isSqliteAvailable() : bool
    {
        if (!class_exists('PDO')) {
            return false;
        }

        try {
            return in_array('sqlite', \PDO::getAvailableDrivers(), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolvePath(string $folder, string $file) : string
    {
        // Absolute paths are respected, everything else is relative to the
        // plugin data folder.
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1) {
            return $file;
        }

        return $folder . ltrim($file, '/');
    }
}
