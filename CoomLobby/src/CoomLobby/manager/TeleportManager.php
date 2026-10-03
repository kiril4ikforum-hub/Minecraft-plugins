<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\object\GameMode;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use CoomLobby\storage\StorageProvider;
use pocketmine\player\GameMode as PocketMineGameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;
use function ceil;
use function microtime;

/**
 * Executes every teleport performed by the plugin (mode switch and /lobby).
 *
 * Responsibilities: permission/enabled/full/world checks, per player
 * cooldowns, inventory cleanup, game mode synchronization, localized feedback
 * and statistics recording. All state is kept in WeakMaps keyed by the player
 * object so nothing has to be cleaned up on quit.
 */
class TeleportManager
{
    public const COOLDOWN_MODE = 'mode';
    public const COOLDOWN_LOBBY = 'lobby';

    private ConfigService $config;
    private Server $server;
    private ModeManager $modes;
    private SpawnManager $spawns;
    private SelectorManager $selector;
    private LanguageService $language;
    private StorageProvider $storage;
    private \Logger $logger;

    /** @var \WeakMap<Player, array<string, float>> */
    private \WeakMap $cooldowns;

    public function __construct(
        ConfigService $config,
        Server $server,
        ModeManager $modes,
        SpawnManager $spawns,
        SelectorManager $selector,
        LanguageService $language,
        StorageProvider $storage,
        \Logger $logger
    ) {
        $this->config = $config;
        $this->server = $server;
        $this->modes = $modes;
        $this->spawns = $spawns;
        $this->selector = $selector;
        $this->language = $language;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->cooldowns = new \WeakMap();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Teleports a player into a game mode.
     *
     * @param bool $ignoreCooldown used by the admin command
     */
    public function teleportToMode(Player $player, string $modeId, bool $ignoreCooldown = false) : bool
    {
        if (!$player->isOnline()) {
            return false;
        }

        $mode = $this->modes->getMode($modeId);
        if ($mode === null || !$mode->isEnabled()) {
            $player->sendMessage($this->language->translate($player, 'teleport.mode_disabled'));

            return false;
        }

        if (!$this->modes->canAccess($player, $mode)) {
            $player->sendMessage($this->language->translate($player, 'teleport.no_permission', ['mode' => $this->language->getModeLabel($mode, $player)]));

            return false;
        }

        $online = $this->modes->getOnlineForMode($mode);
        if ($mode->isFull($online)) {
            $player->sendMessage($this->language->translate($player, 'teleport.mode_full', [
                'current' => $online,
                'max' => $mode->getMaxPlayers(),
                'mode' => $this->language->getModeLabel($mode, $player),
            ]));

            return false;
        }

        $world = $this->modes->resolveWorld($mode);
        if ($world === null) {
            $player->sendMessage($this->language->translate($player, 'teleport.world_missing', ['mode' => $this->language->getModeLabel($mode, $player)]));

            return false;
        }

        if (!$ignoreCooldown && !$this->consumeCooldown($player, self::COOLDOWN_MODE)) {
            $player->sendMessage($this->language->translate($player, 'teleport.cooldown', [
                'time' => $this->getRemainingCooldown($player, self::COOLDOWN_MODE),
            ]));

            return false;
        }

        $previousWorld = $player->getWorld()->getFolderName();
        $spawn = $this->spawns->getModeSpawn($mode, $world) ?? $world->getSpawnLocation();
        $safe = $this->spawns->makeSafe($world, $spawn);

        $this->selector->remove($player);

        if (!$this->doTeleport($player, $safe, $mode->getSpawnPoint()['yaw'] ?? null, $mode->getSpawnPoint()['pitch'] ?? null)) {
            return false;
        }

        $this->applyModeGameMode($player, $mode);

        $player->sendMessage($this->language->translate($player, 'teleport.success', [
            'mode' => $this->language->getModeLabel($mode, $player),
        ]));

        $this->recordStatistics($player, $mode->getId());
        $this->invalidateCounters($mode->getId(), $previousWorld, $world->getFolderName());

        return true;
    }

    /**
     * Sends a player back to the lobby spawn.
     */
    public function teleportToLobby(Player $player, bool $ignoreCooldown = false) : bool
    {
        if (!$player->isOnline()) {
            return false;
        }

        $worldName = $this->spawns->getLobbyWorldName();
        if ($worldName === null) {
            $player->sendMessage($this->language->translate($player, 'command.lobby.unavailable'));

            return false;
        }

        $world = $this->spawns->resolveWorld($worldName);
        if ($world === null) {
            $player->sendMessage($this->language->translate($player, 'command.lobby.unavailable'));

            return false;
        }

        if (!$ignoreCooldown && !$this->consumeCooldown($player, self::COOLDOWN_LOBBY)) {
            $player->sendMessage($this->language->translate($player, 'command.lobby.cooldown', [
                'time' => $this->getRemainingCooldown($player, self::COOLDOWN_LOBBY),
            ]));

            return false;
        }

        $previousWorld = $player->getWorld()->getFolderName();
        $spawn = $this->spawns->getWorldSpawn($world->getFolderName()) ?? $world->getSpawnLocation();
        $safe = $this->spawns->makeSafe($world, $spawn);

        if (!$this->doTeleport($player, $safe, null, null)) {
            return false;
        }

        $this->selector->remove($player);

        if ($this->config->isGiveSelectorOnJoin()) {
            $this->selector->give($player, $this->config->isSelectorSelectOnGive());
        }

        $player->setGamemode($this->resolveLobbyGameMode());

        $player->sendMessage($this->language->translate($player, 'command.lobby.teleported'));
        $this->invalidateCounters(null, $previousWorld, $world->getFolderName());

        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Teleports and returns false when the server refuses the movement.
     */
    private function doTeleport(Player $player, Position $target, ?float $yaw, ?float $pitch) : bool
    {
        try {
            $result = $player->teleport($target, $yaw, $pitch);
        } catch (\Throwable $e) {
            $this->logger->error('Teleport of ' . $player->getName() . ' failed: ' . $e->getMessage());

            return false;
        }

        if (!$result) {
            $this->logger->warning('Teleport of ' . $player->getName() . ' to ' . $target->getWorld()->getFolderName() . ' was refused.');

            return false;
        }

        return true;
    }

    /**
     * Applies the arrival game mode and resets flight so that the player does
     * not keep flying in a survival mode.
     */
    private function applyModeGameMode(Player $player, GameMode $mode) : void
    {
        $target = PocketMineGameMode::fromString($mode->getGamemode());
        if ($target !== null && $player->getGamemode() !== $target) {
            $player->setGamemode($target);
        }

        if ($player->isFlying()) {
            $player->setFlying(false);
        }

        if ($player->getAllowFlight()) {
            $player->setAllowFlight(false);
        }

        // Restore full health/hunger on arrival so that a previous fight in a
        // lobby world never leaks into the game.
        if ($player->getHealth() < $player->getMaxHealth()) {
            $player->setHealth($player->getMaxHealth());
        }
    }

    private function resolveLobbyGameMode() : PocketMineGameMode
    {
        return PocketMineGameMode::fromString($this->config->getLobbyGamemodeName())
            ?? PocketMineGameMode::fromString('adventure')
            ?? PocketMineGameMode::fromString('survival');
    }

    private function recordStatistics(Player $player, string $modeId) : void
    {
        try {
            $profile = $this->storage->loadOrCreate($player->getName());
            $profile->setName($player->getName());
            $profile->recordModeTeleport($modeId);
            $this->storage->save($profile);
        } catch (\Throwable $e) {
            $this->logger->debug('Could not store the mode statistics: ' . $e->getMessage());
        }
    }

    /**
     * Keeps the cached online counters consistent after a teleport.
     */
    private function invalidateCounters(?string $modeId, string $previousWorld, string $targetWorld) : void
    {
        if ($modeId !== null) {
            $this->modes->invalidateOnline($modeId);
        }

        $this->modes->invalidateForWorld($previousWorld);
        $this->modes->invalidateForWorld($targetWorld);
    }

    // ------------------------------------------------------------------
    // Cooldowns
    // ------------------------------------------------------------------

    private function consumeCooldown(Player $player, string $key) : bool
    {
        $cooldown = $this->getCooldownSeconds($key);
        if ($cooldown <= 0) {
            return true;
        }

        $now = microtime(true);
        $timestamps = $this->cooldowns[$player] ?? [];
        $last = $timestamps[$key] ?? null;

        if ($last !== null && ($now - $last) < $cooldown) {
            return false;
        }

        $timestamps[$key] = $now;
        $this->cooldowns[$player] = $timestamps;

        return true;
    }

    public function getRemainingCooldown(Player $player, string $key) : int
    {
        $cooldown = $this->getCooldownSeconds($key);
        if ($cooldown <= 0) {
            return 0;
        }

        $timestamps = $this->cooldowns[$player] ?? [];
        $last = $timestamps[$key] ?? null;
        if ($last === null) {
            return 0;
        }

        $remaining = (int) ceil($cooldown - (microtime(true) - $last));

        return $remaining > 0 ? $remaining : 0;
    }

    public function resetCooldowns(Player $player) : void
    {
        unset($this->cooldowns[$player]);
    }

    private function getCooldownSeconds(string $key) : int
    {
        return $key === self::COOLDOWN_LOBBY
            ? $this->config->getLobbyTeleportCooldown()
            : $this->config->getModeTeleportCooldown();
    }
}
