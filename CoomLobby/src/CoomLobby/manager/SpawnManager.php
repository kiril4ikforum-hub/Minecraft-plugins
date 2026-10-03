<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\object\GameMode;
use CoomLobby\service\ConfigService;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;
use function is_array;
use function is_numeric;
use function strtolower;
use function trim;

/**
 * Loads and saves every spawn point used by the plugin.
 *
 * Sources, in order of precedence:
 *   1. spawns.yml        - written by /setlobby, editable by hand
 *   2. config.yml        - per mode "spawn-point" section
 *   3. the world spawn    - final fallback so that a teleport never fails
 */
class SpawnManager
{
    private const FILE_NAME = 'spawns.yml';

    private ConfigService $config;
    private Server $server;
    private \Logger $logger;
    private Config $spawns;

    /** @var array<string, array<string, float>> world folder name => coordinates */
    private array $spawnsData = [];

    private ?string $lobbyWorldName = null;

    public function __construct(ConfigService $config, Server $server, \Logger $logger)
    {
        $this->config = $config;
        $this->server = $server;
        $this->logger = $logger;
        $this->spawns = new Config($this->getFile(), Config::YAML, []);
        $this->read();
    }

    public function getFile() : string
    {
        return $this->config->getDataFolder() . self::FILE_NAME;
    }

    public function read() : void
    {
        $this->spawnsData = [];
        $this->lobbyWorldName = null;

        $raw = $this->spawns->getAll();

        $configuredLobbyWorld = $raw['lobby-world'] ?? null;
        if (is_string($configuredLobbyWorld) && trim($configuredLobbyWorld) !== '') {
            $this->lobbyWorldName = trim($configuredLobbyWorld);
        }

        $spawns = $raw['spawns'] ?? [];
        if (!is_array($spawns)) {
            $this->logger->warning('spawns.yml: "spawns" must be a map of world name => coordinates.');

            return;
        }

        foreach ($spawns as $worldName => $data) {
            if (!is_array($data)) {
                continue;
            }

            $coordinates = $this->normalizeCoordinates($data);
            if ($coordinates === null) {
                $this->logger->warning('spawns.yml: invalid spawn coordinates for world "' . (string) $worldName . '".');
                continue;
            }

            $this->spawnsData[(string) $worldName] = $coordinates;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, float>|null
     */
    private function normalizeCoordinates(array $data) : ?array
    {
        foreach (['x', 'y', 'z'] as $required) {
            if (!isset($data[$required]) || !is_numeric($data[$required])) {
                return null;
            }
        }

        return [
            'x' => (float) $data['x'],
            'y' => (float) $data['y'],
            'z' => (float) $data['z'],
            'yaw' => isset($data['yaw']) && is_numeric($data['yaw']) ? (float) $data['yaw'] : 0.0,
            'pitch' => isset($data['pitch']) && is_numeric($data['pitch']) ? (float) $data['pitch'] : 0.0,
        ];
    }

    public function save() : void
    {
        $this->spawns->setAll([
            'lobby-world' => $this->lobbyWorldName,
            'spawns' => $this->spawnsData,
        ]);
        $this->spawns->save();
    }

    public function getLobbyWorldName() : ?string
    {
        return $this->lobbyWorldName ?? $this->config->getPrimaryLobbyWorld();
    }

    /**
     * Resolves the lobby spawn. Returns null when the lobby world is not
     * loaded (the caller has to decide whether loading it is desirable).
     */
    public function getLobbySpawn() : ?Position
    {
        $worldName = $this->getLobbyWorldName();
        if ($worldName === null) {
            return null;
        }

        return $this->getWorldSpawn($worldName);
    }

    /**
     * Resolves the spawn of a world: spawns.yml first, then the vanilla world
     * spawn. Only loaded worlds are returned.
     */
    public function getWorldSpawn(string $worldName) : ?Position
    {
        $world = $this->server->getWorldManager()->getWorldByName($worldName);
        if ($world === null) {
            return null;
        }

        $data = $this->spawnsData[$worldName] ?? null;
        if ($data === null) {
            // Case-insensitive lookup for worlds renamed by hand.
            foreach ($this->spawnsData as $name => $coordinates) {
                if (strtolower($name) === strtolower($worldName)) {
                    $data = $coordinates;
                    break;
                }
            }
        }

        if ($data !== null) {
            return new Position($data['x'], $data['y'], $data['z'], $world);
        }

        return $world->getSpawnLocation();
    }

    /**
     * Returns the yaw configured for a world spawn (null when unknown).
     */
    public function getWorldSpawnYaw(string $worldName) : ?float
    {
        return $this->spawnsData[$worldName]['yaw'] ?? null;
    }

    public function getWorldSpawnPitch(string $worldName) : ?float
    {
        return $this->spawnsData[$worldName]['pitch'] ?? null;
    }

    public function setLobbySpawn(Position $position, bool $setAsPrimary = true, float $yaw = 0.0, float $pitch = 0.0) : void
    {
        $this->setWorldSpawn($position, $yaw, $pitch);

        if ($setAsPrimary) {
            $this->lobbyWorldName = $position->getWorld()->getFolderName();
        }

        $this->save();
    }

    /**
     * Stores a spawn position. Position itself has no rotation, so the yaw and
     * pitch have to be passed separately (they usually come from a Location).
     */
    public function setWorldSpawn(Position $position, float $yaw = 0.0, float $pitch = 0.0) : void
    {
        $this->spawnsData[$position->getWorld()->getFolderName()] = [
            'x' => $position->getX(),
            'y' => $position->getY(),
            'z' => $position->getZ(),
            'yaw' => $yaw,
            'pitch' => $pitch,
        ];
    }

    /**
     * Rotation of a stored world spawn (null when nothing was saved yet).
     *
     * @return array{yaw: float, pitch: float}|null
     */
    public function getWorldRotation(string $worldName) : ?array
    {
        $data = $this->spawnsData[$worldName] ?? null;
        if ($data === null) {
            return null;
        }

        return ['yaw' => $data['yaw'], 'pitch' => $data['pitch']];
    }

    /**
     * Resolves the entry point of a mode: spawns.yml, then the mode config,
     * then the world spawn.
     */
    public function getModeSpawn(GameMode $mode, ?World $world = null) : ?Position
    {
        $world ??= $this->server->getWorldManager()->getWorldByName($mode->getPrimaryWorldName() ?? '');
        if ($world === null) {
            return null;
        }

        $data = $this->spawnsData[$world->getFolderName()] ?? null;
        if ($data !== null) {
            return new Position($data['x'], $data['y'], $data['z'], $world);
        }

        $configured = $mode->getSpawnPoint();
        if ($configured !== null) {
            return new Position(
                (float) $configured['x'],
                (float) $configured['y'],
                (float) $configured['z'],
                $world
            );
        }

        return $world->getSpawnLocation();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfigSpawnPoint(GameMode $mode) : ?array
    {
        $configured = $mode->getSpawnPoint();
        if ($configured === null) {
            return null;
        }

        return [
            'yaw' => (float) ($configured['yaw'] ?? 0.0),
            'pitch' => (float) ($configured['pitch'] ?? 0.0),
        ];
    }

    /**
     * Loads a world when needed; returns null when the world is unknown.
     */
    public function resolveWorld(string $worldName) : ?World
    {
        $manager = $this->server->getWorldManager();
        $world = $manager->getWorldByName($worldName);
        if ($world !== null) {
            return $world;
        }

        if (!$this->config->isAutoLoadWorlds()) {
            return null;
        }

        try {
            if (!$manager->loadWorld($worldName)) {
                $this->logger->warning('World "' . $worldName . '" could not be loaded.');

                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('World "' . $worldName . '" could not be loaded: ' . $e->getMessage());

            return null;
        }

        return $manager->getWorldByName($worldName);
    }

    /**
     * Returns a safe position inside the given world (never inside blocks).
     */
    public function makeSafe(World $world, Position $position) : Position
    {
        $safe = $world->getSafeSpawn(new Vector3($position->getX(), $position->getY(), $position->getZ()));

        return $safe;
    }

    public function hasSpawnFor(string $worldName) : bool
    {
        return isset($this->spawnsData[$worldName]);
    }
}
