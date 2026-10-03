<?php

declare(strict_types=1);

namespace CoomLobby\storage;

use pocketmine\utils\Config;
use function is_array;
use function strtolower;

/**
 * YAML fallback storage used when the SQLite driver is not available or when
 * the server owner prefers a human readable file.
 *
 * The whole file is written on every change, which is perfectly fine for the
 * low write frequency of this plugin (join, quit and mode teleport).
 */
final class YamlStorage implements StorageProvider
{
    private const ROOT_KEY = 'players';

    private Config $config;
    private \Logger $logger;
    private bool $reportedFailure = false;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    private bool $dirty = false;

    public function __construct(string $file, \Logger $logger)
    {
        $this->logger = $logger;

        try {
            $this->config = new Config($file, Config::YAML, [self::ROOT_KEY => []]);
        } catch (\Throwable $e) {
            $this->logger->error('Could not open the YAML storage: ' . $e->getMessage());
            $this->config = new Config($file, Config::YAML, [self::ROOT_KEY => []]);
        }

        $data = $this->config->get(self::ROOT_KEY, []);
        if (is_array($data)) {
            foreach ($data as $key => $entry) {
                if (is_array($entry)) {
                    $this->cache[strtolower((string) $key)] = $entry;
                }
            }
        }
    }

    public function load(string $playerName) : ?PlayerProfile
    {
        $key = strtolower($playerName);
        if (!isset($this->cache[$key])) {
            return null;
        }

        return PlayerProfile::fromArray($key, $this->cache[$key]);
    }

    public function loadOrCreate(string $playerName) : PlayerProfile
    {
        return $this->load($playerName) ?? PlayerProfile::create($playerName);
    }

    public function save(PlayerProfile $profile) : void
    {
        $this->cache[$profile->getKey()] = $profile->toArray();
        $this->dirty = true;
        $this->flush();
    }

    public function loadAll() : array
    {
        $profiles = [];
        foreach ($this->cache as $key => $data) {
            $profiles[$key] = PlayerProfile::fromArray($key, $data);
        }

        return $profiles;
    }

    public function count() : int
    {
        return count($this->cache);
    }

    public function getType() : string
    {
        return 'yaml';
    }

    public function getStatus() : string
    {
        return 'yaml (' . $this->config->getPath() . ', ' . $this->count() . ' profiles)';
    }

    public function close() : void
    {
        $this->flush();
    }

    private function flush() : void
    {
        if (!$this->dirty) {
            return;
        }

        try {
            $this->config->set(self::ROOT_KEY, $this->cache);
            $this->config->save();
            $this->dirty = false;
        } catch (\Throwable $e) {
            if (!$this->reportedFailure) {
                $this->reportedFailure = true;
                $this->logger->error('Could not write the YAML storage: ' . $e->getMessage());
            }
        }
    }
}
