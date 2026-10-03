<?php

declare(strict_types=1);

namespace CoomLobby\api;

use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Public API of CoomLobby.
 *
 * Usage from another plugin:
 *
 *   $plugin = $this->getServer()->getPluginManager()->getPlugin("CoomLobby");
 *   if ($plugin instanceof \CoomLobby\Main && $plugin->isEnabled()) {
 *       $api = $plugin->getAPI();
 *       $api->giveSelector($player);
 *   }
 *
 * Every method is safe to call from the main thread only (PocketMine-MP keeps
 * all player and world state there).
 */
interface CoomLobbyAPI
{
    /**
     * Returns true when the world is listed in "lobby.worlds" and therefore
     * protected by CoomLobby.
     */
    public function isLobbyWorld(World $world) : bool;

    /**
     * Removes any existing selector from the inventory and gives a fresh one
     * (localized for the player). Does nothing for offline players.
     */
    public function giveSelector(Player $player) : void;

    /**
     * Removes every CoomLobby selector item from the player inventory.
     */
    public function removeSelector(Player $player) : void;

    /**
     * Teleports the player into the given mode, running all regular checks
     * (enabled, permission, full, world available, cooldown).
     *
     * @return bool true when the teleport was performed
     */
    public function teleportToMode(Player $player, string $modeId) : bool;

    /**
     * Current number of players inside all worlds of a mode (cached).
     * Returns 0 for unknown modes.
     */
    public function getModeOnline(string $modeId) : int;

    /**
     * Two letter language code of the player ("ru", "en", ...), resolved
     * through CoomLanguage when available and the client locale otherwise.
     */
    public function getPlayerLanguage(Player $player) : string;

    /**
     * All enabled modes with their live statistics.
     *
     * @return array<string, array{
     *     id: string,
     *     name: string,
     *     worlds: string[],
     *     online: int,
     *     max_players: int,
     *     available: bool,
     *     status: string
     * }> keyed by mode ID
     */
    public function getActiveModes() : array;
}
