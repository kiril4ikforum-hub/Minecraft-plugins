<?php

declare(strict_types=1);

namespace CoomLobby\object;

/**
 * Immutable description of a single game mode (one entry of the "modes"
 * section in config.yml).
 *
 * The object is a pure value holder - it never talks to the server, it only
 * answers questions about one configured mode.
 */
final class GameMode
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_FULL = 'full';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_LOCKED = 'locked';

    /**
     * @param string[]                                                   $worlds       folder names of the mode worlds
     * @param array<string, string>                                      $names        language code => display name ("*" = fallback)
     * @param array<string, string>                                      $descriptions language code => description
     * @param array{x: float, y: float, z: float, yaw: float, pitch: float}|null $spawnPoint optional spawn override
     */
    public function __construct(
        private readonly string $id,
        private readonly bool $enabled,
        private readonly array $worlds,
        private readonly int $maxPlayers,
        private readonly string $permission,
        private readonly string $icon,
        private readonly string $iconType,
        private readonly array $names,
        private readonly array $descriptions,
        private readonly ?array $spawnPoint,
        private readonly string $gamemode,
        private readonly int $order
    ) {
    }

    public function getId() : string
    {
        return $this->id;
    }

    public function isEnabled() : bool
    {
        return $this->enabled;
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

    public function getPrimaryWorldName() : ?string
    {
        return $this->worlds[0] ?? null;
    }

    /**
     * Player limit, 0 means unlimited.
     */
    public function getMaxPlayers() : int
    {
        return $this->maxPlayers;
    }

    public function isUnlimited() : bool
    {
        return $this->maxPlayers <= 0;
    }

    public function isFull(int $online) : bool
    {
        return !$this->isUnlimited() && $online >= $this->maxPlayers;
    }

    public function getPermission() : string
    {
        return $this->permission;
    }

    public function hasPermissionRestriction() : bool
    {
        return $this->permission !== '';
    }

    public function getIcon() : string
    {
        return $this->icon;
    }

    public function getIconType() : string
    {
        return $this->iconType;
    }

    /**
     * @return array<string, string>
     */
    public function getNames() : array
    {
        return $this->names;
    }

    /**
     * Localized name; an exact language match wins over the "*" fallback and
     * any other configured language. Returns "" when nothing is configured.
     */
    public function getName(string $languageCode) : string
    {
        return $this->pick($this->names, $languageCode) ?? '';
    }

    public function getDescription(string $languageCode) : ?string
    {
        return $this->pick($this->descriptions, $languageCode);
    }

    /**
     * Gamemode applied on arrival ("" = keep the player's mode).
     */
    public function getGamemode() : string
    {
        return $this->gamemode;
    }

    /**
     * @return array{x: float, y: float, z: float, yaw: float, pitch: float}|null
     */
    public function getSpawnPoint() : ?array
    {
        return $this->spawnPoint;
    }

    /**
     * Position of the mode inside the configured list (used for ordering).
     */
    public function getOrder() : int
    {
        return $this->order;
    }

    /**
     * Status shown on the mode button.
     */
    public function getStatus(int $online, bool $worldAvailable, bool $hasAccess) : string
    {
        if (!$this->enabled || !$worldAvailable) {
            return self::STATUS_UNAVAILABLE;
        }

        if (!$hasAccess) {
            return self::STATUS_LOCKED;
        }

        return $this->isFull($online) ? self::STATUS_FULL : self::STATUS_AVAILABLE;
    }

    /**
     * Exact language match, then the "*" wildcard, then any configured value.
     *
     * @param array<string, string> $values
     */
    private function pick(array $values, string $languageCode) : ?string
    {
        $exact = $values[$languageCode] ?? null;
        if ($exact !== null && $exact !== '') {
            return $exact;
        }

        $wildcard = $values['*'] ?? null;
        if ($wildcard !== null && $wildcard !== '') {
            return $wildcard;
        }

        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
