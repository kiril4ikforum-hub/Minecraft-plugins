<?php

declare(strict_types=1);

namespace CoomLobby\service;

/**
 * Minimal view of the plugin file system required by the resource pack
 * manager.
 *
 * Depending on this interface (instead of the whole PluginBase) keeps the pack
 * pipeline decoupled from the server and allows it to be exercised in tests
 * without booting PocketMine-MP.
 */
interface PluginResources
{
    /**
     * Version string from plugin.yml, used to detect source updates.
     */
    public function getPluginVersion() : string;

    /**
     * All files bundled with the plugin.
     *
     * @return array<string, \SplFileInfo> relative path (e.g. "rp/CoomLobbyRP/manifest.json") => file
     */
    public function getBundledResources() : array;

    /**
     * Opens a bundled resource for reading (works inside a phar).
     *
     * @return resource|null
     */
    public function openResource(string $relativePath);
}
