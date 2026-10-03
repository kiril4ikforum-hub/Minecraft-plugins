<?php

declare(strict_types=1);

namespace CoomLobby\storage;

/**
 * Abstraction over the persistent player storage (SQLite or YAML).
 *
 * All methods are non-throwing by contract: storage problems must never break
 * the lobby, they are logged and the plugin continues to run.
 */
interface StorageProvider
{
    /**
     * Loads the profile of a player, or null when the player is unknown.
     */
    public function load(string $playerName) : ?PlayerProfile;

    /**
     * Loads a profile or returns a fresh one for unknown players.
     */
    public function loadOrCreate(string $playerName) : PlayerProfile;

    /**
     * Persists the given profile.
     */
    public function save(PlayerProfile $profile) : void;

    /**
     * @return array<string, PlayerProfile> keyed by the lower-cased player name
     */
    public function loadAll() : array;

    public function count() : int;

    /**
     * Backend identifier shown by /coomlobby info ("sqlite" or "yaml").
     */
    public function getType() : string;

    /**
     * Human readable status of the backend (file, driver, error state).
     */
    public function getStatus() : string;

    /**
     * Flushes pending data and releases resources.
     */
    public function close() : void;
}
