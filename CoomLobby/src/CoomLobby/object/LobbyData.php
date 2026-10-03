<?php

declare(strict_types=1);

namespace CoomLobby\object;

use CoomLobby\service\ConfigService;
use pocketmine\player\GameMode as PocketGameMode;
use pocketmine\world\Position;
use pocketmine\world\World;
use function strtolower;

/**
 * Immutable snapshot of the lobby configuration.
 *
 * A fresh instance is created on every (re)load of config.yml and handed to
 * the managers, so a reload can never leave half-updated state behind.
 */
final class LobbyData
{
    /** @var array<string, true> lowercased world folder name => true */
    private array $worldIndex = [];

    /**
     * @param string[] $worlds
     */
    public function __construct(
        private readonly array $worlds,
        private readonly ?string $primaryWorld,
        private readonly ?Position $spawn,
        private readonly string $gamemodeName,
        private readonly bool $clearInventoryOnJoin,
        private readonly bool $giveSelectorOnJoin,
        private readonly bool $enforceSpawn,
        private readonly bool $welcomeMessage,
        private readonly bool $honorBypass
    ) {
        foreach ($this->worlds as $world) {
            $this->worldIndex[strtolower($world)] = true;
        }
    }

    /**
     * Builds the snapshot from the current configuration.
     */
    public static function fromConfig(ConfigService $config, ?Position $spawn) : self
    {
        return new self(
            $config->getLobbyWorlds(),
            $config->getPrimaryLobbyWorld(),
            $spawn,
            $config->getLobbyGamemodeName(),
            $config->isClearInventoryOnJoin(),
            $config->isGiveSelectorOnJoin(),
            $config->isEnforceLobbySpawn(),
            $config->isWelcomeMessageEnabled(),
            $config->isHonorBypassPermission()
        );
    }

    /**
     * @return string[]
     */
    public function getWorlds() : array
    {
        return $this->worlds;
    }

    public function hasWorlds() : bool
    {
        return $this->worlds !== [];
    }

    /**
     * World used by /lobby: the configured primary world, or the first entry.
     */
    public function getPrimaryWorld() : ?string
    {
        if ($this->primaryWorld !== null && $this->primaryWorld !== '') {
            return $this->primaryWorld;
        }

        return $this->worlds[0] ?? null;
    }

    public function getSpawn() : ?Position
    {
        return $this->spawn;
    }

    /**
     * Gamemode enforced inside the lobby (adventure by default).
     */
    public function getGamemode() : PocketGameMode
    {
        return PocketGameMode::fromString($this->gamemodeName) ?? PocketGameMode::ADVENTURE;
    }

    public function getGamemodeName() : string
    {
        return $this->gamemodeName;
    }

    public function isClearInventoryOnJoin() : bool
    {
        return $this->clearInventoryOnJoin;
    }

    public function isGiveSelectorOnJoin() : bool
    {
        return $this->giveSelectorOnJoin;
    }

    public function isEnforceSpawn() : bool
    {
        return $this->enforceSpawn;
    }

    public function isWelcomeMessageEnabled() : bool
    {
        return $this->welcomeMessage;
    }

    /**
     * When enabled, the coomlobby.bypass permission also disables the lobby
     * protections (item use, damage, chat, flight, ...).
     */
    public function isHonorBypass() : bool
    {
        return $this->honorBypass;
    }

    public function isLobbyWorld(World $world) : bool
    {
        return $this->isLobbyWorldName($world->getFolderName());
    }

    /**
     * Case-insensitive lookup against the configured lobby worlds (O(1)).
     */
    public function isLobbyWorldName(string $folderName) : bool
    {
        return isset($this->worldIndex[strtolower($folderName)]);
    }

    /**
     * Same snapshot with a different lobby spawn (used after /setlobby).
     */
    public function withSpawn(?Position $spawn) : self
    {
        return new self(
            $this->worlds,
            $this->primaryWorld,
            $spawn,
            $this->gamemodeName,
            $this->clearInventoryOnJoin,
            $this->giveSelectorOnJoin,
            $this->enforceSpawn,
            $this->welcomeMessage,
            $this->honorBypass
        );
    }
}
