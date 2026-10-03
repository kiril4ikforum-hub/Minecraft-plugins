<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\object\GameMode;
use CoomLobby\service\ConfigService;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\World;
use function array_filter;
use function array_key_exists;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function ksort;
use function microtime;
use function strtolower;
use function trim;
use function array_keys;
use function array_unique;
use function array_values;
use function in_array;
use function uasort;

/**
 * Owns the mode definitions and the per-mode online counters.
 *
 * Online counts are cached for "modes.cache-ttl" seconds; the cache is also
 * invalidated explicitly when a player joins/leaves one of the mode worlds and
 * after every mode teleport, so the form always shows fresh values without
 * walking every world on each click.
 */
class ModeManager
{
    private ConfigService $config;
    private Server $server;
    private \Logger $logger;

    /** @var array<string, GameMode> */
    private array $modes = [];

    /** @var array<string, array{count: int, time: float}> */
    private array $onlineCache = [];

    public function __construct(ConfigService $config, Server $server, \Logger $logger)
    {
        $this->config = $config;
        $this->server = $server;
        $this->logger = $logger;
        $this->loadModes();
    }

    /**
     * (Re)builds the mode list from the configuration.
     */
    public function loadModes() : void
    {
        $definitions = $this->config->getModeDefinitions();
        $modes = [];
        $order = 0;

        foreach ($definitions as $id => $definition) {
            $mode = $this->parseMode((string) $id, $definition, $order);
            if ($mode === null) {
                continue;
            }

            $modes[$mode->getId()] = $mode;
            $order++;
        }

        // uasort keeps the mode IDs as array keys (the form relies on them).
        uasort($modes, static fn (GameMode $a, GameMode $b) : int => $a->getOrder() <=> $b->getOrder());

        $this->modes = $modes;
        $this->onlineCache = [];

        if ($modes === []) {
            $this->logger->warning('No valid game modes found in config.yml - the mode selector will only show the close button.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function parseMode(string $id, array $definition, int $order) : ?GameMode
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $worlds = $this->parseWorlds($definition);
        if ($worlds === []) {
            $this->logger->warning('Mode "' . $id . '" has no world configured and was skipped.');
            return null;
        }

        $names = $this->config->pickLocalized($definition, ['name', 'label', 'title']);
        if ($names === []) {
            $names = ['*' => $id];
        }

        $descriptions = $this->config->pickLocalized($definition, ['description', 'subtitle', 'lore']);
        $icon = $this->config->toString($this->config->pick($definition, ['icon', 'icon-path', 'texture'], ''), '', 'modes.' . $id . '.icon');
        $iconType = strtolower($this->config->toString($this->config->pick($definition, ['icon-type', 'image-type'], 'path'), 'path', 'modes.' . $id . '.icon-type'));
        if (!in_array($iconType, ['path', 'url', 'auto'], true)) {
            $iconType = 'path';
        }

        $spawnPoint = $this->parseSpawnPoint($definition);

        return new GameMode(
            $id,
            $this->config->toBool($this->config->pick($definition, ['enabled'], true), true, 'modes.' . $id . '.enabled'),
            $worlds,
            $this->config->toInt($this->config->pick($definition, ['max-players', 'maxplayers'], 0), 0, 0, 100000, 'modes.' . $id . '.max-players'),
            $this->config->toString($this->config->pick($definition, ['permission'], ''), '', 'modes.' . $id . '.permission'),
            $icon,
            $iconType,
            $names,
            $descriptions,
            $spawnPoint,
            strtolower($this->config->toString($this->config->pick($definition, ['gamemode'], 'survival'), 'survival', 'modes.' . $id . '.gamemode')),
            $order
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @return string[]
     */
    private function parseWorlds(array $definition) : array
    {
        $worlds = [];

        $single = $this->config->pick($definition, ['world', 'world-name'], null);
        if (is_string($single) && trim($single) !== '') {
            $worlds[] = trim($single);
        }

        $list = $this->config->pick($definition, ['worlds', 'world-list'], null);
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $worlds[] = trim($entry);
                }
            }
        }

        return array_values(array_unique($worlds));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    private function parseSpawnPoint(array $definition) : ?array
    {
        $spawn = $this->config->pick($definition, ['spawn-point', 'spawn', 'entry-point'], null);
        if (!is_array($spawn)) {
            return null;
        }

        if (!isset($spawn['x'], $spawn['y'], $spawn['z']) || !is_numeric($spawn['x']) || !is_numeric($spawn['y']) || !is_numeric($spawn['z'])) {
            return null;
        }

        return [
            'x' => (float) $spawn['x'],
            'y' => (float) $spawn['y'],
            'z' => (float) $spawn['z'],
            'yaw' => isset($spawn['yaw']) && is_numeric($spawn['yaw']) ? (float) $spawn['yaw'] : 0.0,
            'pitch' => isset($spawn['pitch']) && is_numeric($spawn['pitch']) ? (float) $spawn['pitch'] : 0.0,
        ];
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /**
     * @return array<string, GameMode>
     */
    public function getModes() : array
    {
        return $this->modes;
    }

    /**
     * @return array<string, GameMode>
     */
    public function getActiveModes() : array
    {
        return array_filter($this->modes, static fn (GameMode $mode) : bool => $mode->isEnabled());
    }

    public function getMode(string $modeId) : ?GameMode
    {
        return $this->modes[$modeId] ?? $this->modes[strtolower($modeId)] ?? null;
    }

    public function hasMode(string $modeId) : bool
    {
        return $this->getMode($modeId) !== null;
    }

    public function countModes() : int
    {
        return count($this->modes);
    }

    /**
     * @return string[]
     */
    public function getModeIds() : array
    {
        return array_keys($this->modes);
    }

    // ------------------------------------------------------------------
    // Online counters
    // ------------------------------------------------------------------

    public function getOnline(string $modeId) : int
    {
        $mode = $this->getMode($modeId);
        if ($mode === null) {
            return 0;
        }

        return $this->getOnlineForMode($mode);
    }

    public function getOnlineForMode(GameMode $mode) : int
    {
        $key = $mode->getId();
        $now = microtime(true);
        $cached = $this->onlineCache[$key] ?? null;
        $ttl = $this->config->getModeCacheTtl();

        if ($cached !== null && $ttl > 0 && ($now - $cached['time']) < $ttl) {
            return $cached['count'];
        }

        $count = $this->countOnline($mode);
        $this->onlineCache[$key] = ['count' => $count, 'time' => $now];

        return $count;
    }

    /**
     * Real (uncached) player count of all worlds belonging to the mode.
     * Overridable for testing purposes.
     */
    protected function countOnline(GameMode $mode) : int
    {
        $total = 0;
        foreach ($mode->getWorlds() as $worldName) {
            $world = $this->server->getWorldManager()->getWorldByName($worldName);
            if ($world !== null) {
                $total += count($world->getPlayers());
            }
        }

        return $total;
    }

    /**
     * Refreshes the cached counters of every mode (used by the update task).
     */
    public function refreshOnlineCache() : void
    {
        $now = microtime(true);
        foreach ($this->modes as $mode) {
            $this->onlineCache[$mode->getId()] = [
                'count' => $this->countOnline($mode),
                'time' => $now,
            ];
        }
    }

    public function invalidateOnline(?string $modeId = null) : void
    {
        if ($modeId === null) {
            $this->onlineCache = [];

            return;
        }

        unset($this->onlineCache[$modeId]);
    }

    /**
     * Invalidates every mode that uses the given world folder.
     */
    public function invalidateForWorld(string $worldFolderName) : void
    {
        foreach ($this->getModesForWorld($worldFolderName) as $modeId) {
            $this->invalidateOnline($modeId);
        }
    }

    /**
     * @return string[]
     */
    public function getModesForWorld(string $worldFolderName) : array
    {
        $result = [];
        foreach ($this->modes as $mode) {
            foreach ($mode->getWorlds() as $worldName) {
                if (strtolower($worldName) === strtolower($worldFolderName)) {
                    $result[] = $mode->getId();
                    break;
                }
            }
        }

        return $result;
    }

    public function getTotalModeOnline() : int
    {
        $total = 0;
        foreach ($this->modes as $mode) {
            $total += $this->getOnlineForMode($mode);
        }

        return $total;
    }

    public function isFull(GameMode $mode) : bool
    {
        return $mode->isFull($this->getOnlineForMode($mode));
    }

    // ------------------------------------------------------------------
    // Worlds
    // ------------------------------------------------------------------

    /**
     * Resolves the world a player should be teleported to: the emptiest world
     * of the mode when balancing is enabled, otherwise the primary world.
     */
    public function resolveWorld(GameMode $mode) : ?World
    {
        $worlds = $mode->getWorlds();
        if ($worlds === []) {
            return null;
        }

        $manager = $this->server->getWorldManager();
        $loaded = [];
        foreach ($worlds as $worldName) {
            $world = $manager->getWorldByName($worldName);
            if ($world !== null) {
                $loaded[] = $world;
            }
        }

        if ($loaded !== [] && $this->config->isWorldBalancing()) {
            usort($loaded, static fn (World $a, World $b) : int => count($a->getPlayers()) <=> count($b->getPlayers()));

            return $loaded[0];
        }

        if ($this->config->isAutoLoadWorlds()) {
            foreach ($worlds as $worldName) {
                $world = $this->loadWorld($worldName);
                if ($world !== null) {
                    return $world;
                }
            }
        }

        return $loaded[0] ?? null;
    }

    /**
     * Loads a world on demand (used when "auto-load-worlds" is enabled).
     */
    public function loadWorld(string $worldName) : ?World
    {
        $manager = $this->server->getWorldManager();
        $world = $manager->getWorldByName($worldName);
        if ($world !== null) {
            return $world;
        }

        try {
            if (!$manager->loadWorld($worldName)) {
                $this->logger->warning('Could not load world "' . $worldName . '" for a game mode.');

                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not load world "' . $worldName . '": ' . $e->getMessage());

            return null;
        }

        return $manager->getWorldByName($worldName);
    }

    public function isWorldAvailableFor(GameMode $mode) : bool
    {
        $manager = $this->server->getWorldManager();
        foreach ($mode->getWorlds() as $worldName) {
            if ($manager->getWorldByName($worldName) !== null) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Access control & presentation
    // ------------------------------------------------------------------

    public function canAccess(Player $player, GameMode $mode) : bool
    {
        if (!$mode->hasPermissionRestriction()) {
            return true;
        }

        return $player->hasPermission($mode->getPermission());
    }

    /**
     * Status string used by the mode buttons (see GameMode::STATUS_*).
     */
    public function getStatus(GameMode $mode, ?Player $player = null) : string
    {
        $hasAccess = $player === null || $this->canAccess($player, $mode);

        return $mode->getStatus(
            $this->getOnlineForMode($mode),
            $this->isWorldAvailableFor($mode),
            $hasAccess
        );
    }

    /**
     * @return array<string, int> mode id => online count
     */
    public function getStatistics() : array
    {
        $statistics = [];
        foreach ($this->modes as $id => $mode) {
            $statistics[$id] = $this->getOnlineForMode($mode);
        }

        ksort($statistics);

        return $statistics;
    }

    /**
     * Modes that may be entered by the player (enabled + permission).
     *
     * @return array<string, GameMode>
     */
    public function getAvailableModes(Player $player) : array
    {
        return array_filter(
            $this->modes,
            fn (GameMode $mode) : bool => $mode->isEnabled() && $this->canAccess($player, $mode)
        );
    }

    public function isKnownWorld(string $worldFolderName) : bool
    {
        return array_key_exists(strtolower($worldFolderName), $this->getWorldIndex());
    }

    /**
     * @return array<string, true>
     */
    private function getWorldIndex() : array
    {
        $index = [];
        foreach ($this->modes as $mode) {
            foreach ($mode->getWorlds() as $worldName) {
                $index[strtolower($worldName)] = true;
            }
        }

        return $index;
    }
}
