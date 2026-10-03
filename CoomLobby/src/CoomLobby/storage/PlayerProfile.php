<?php

declare(strict_types=1);

namespace CoomLobby\storage;

use function is_array;
use function is_int;
use function is_string;
use function max;
use function strtolower;
use function time;

/**
 * Mutable data transfer object holding the statistics stored for one player.
 */
final class PlayerProfile
{
    public function __construct(
        private string $name,
        private ?string $lastMode = null,
        private int $teleports = 0,
        private int $firstSeen = 0,
        private int $lastSeen = 0
    ) {
        if ($this->firstSeen <= 0) {
            $this->firstSeen = time();
        }

        if ($this->lastSeen <= 0) {
            $this->lastSeen = $this->firstSeen;
        }
    }

    public static function create(string $playerName) : self
    {
        return new self($playerName);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data) : self
    {
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : $key;

        return new self(
            $name,
            isset($data['last-mode']) && is_string($data['last-mode']) ? $data['last-mode'] : null,
            isset($data['teleports']) && is_int($data['teleports']) ? max(0, $data['teleports']) : 0,
            isset($data['first-seen']) && is_int($data['first-seen']) ? $data['first-seen'] : 0,
            isset($data['last-seen']) && is_int($data['last-seen']) ? $data['last-seen'] : 0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray() : array
    {
        return [
            'name' => $this->name,
            'last-mode' => $this->lastMode,
            'teleports' => $this->teleports,
            'first-seen' => $this->firstSeen,
            'last-seen' => $this->lastSeen,
        ];
    }

    public function getKey() : string
    {
        return strtolower($this->name);
    }

    public function getName() : string
    {
        return $this->name;
    }

    public function setName(string $name) : void
    {
        $this->name = $name;
    }

    public function getLastMode() : ?string
    {
        return $this->lastMode;
    }

    public function setLastMode(?string $modeId) : void
    {
        $this->lastMode = $modeId;
    }

    public function getTeleports() : int
    {
        return $this->teleports;
    }

    public function incrementTeleports() : void
    {
        $this->teleports++;
    }

    public function getFirstSeen() : int
    {
        return $this->firstSeen;
    }

    public function getLastSeen() : int
    {
        return $this->lastSeen;
    }

    public function touch() : void
    {
        $this->lastSeen = time();
    }

    public function recordModeTeleport(string $modeId) : void
    {
        $this->lastMode = $modeId;
        $this->teleports++;
        $this->touch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mergeArray(array $data) : void
    {
        if (isset($data['last-mode']) && is_string($data['last-mode'])) {
            $this->lastMode = $data['last-mode'];
        }

        if (isset($data['teleports']) && is_int($data['teleports'])) {
            $this->teleports = max($this->teleports, $data['teleports']);
        }

        if (isset($data['first-seen']) && is_int($data['first-seen']) && ($this->firstSeen === 0 || $data['first-seen'] < $this->firstSeen)) {
            $this->firstSeen = $data['first-seen'];
        }
    }

    public function isValid() : bool
    {
        return $this->name !== '';
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function normalizeArray(mixed $array) : array
    {
        return is_array($array) ? $array : [];
    }
}
