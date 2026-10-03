<?php

declare(strict_types=1);

namespace CoomLobby\listener;

use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\SelectorManager;
use CoomLobby\manager\SpawnManager;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use CoomLobby\storage\StorageProvider;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;

/**
 * Handles everything that happens when a player connects, disconnects,
 * respawns or is teleported into/out of a lobby world:
 *
 *  - lobby state (inventory, selector, game mode, spawn)
 *  - resource pack delivery
 *  - player statistics
 *  - online counter invalidation
 */
final class ConnectionListener implements Listener
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly LanguageService $language,
        private readonly ProtectionManager $protection,
        private readonly SelectorManager $selector,
        private readonly ModeManager $modes,
        private readonly SpawnManager $spawns,
        private readonly ResourceManager $resources,
        private readonly StorageProvider $storage,
        private readonly TaskScheduler $scheduler,
        private readonly \Logger $logger
    ) {
    }

    public function onJoin(PlayerJoinEvent $event) : void
    {
        $player = $event->getPlayer();

        $this->touchProfile($player);

        if ($this->protection->isLobbyWorld($player->getWorld())) {
            $this->applyLobbyState($player, true);
        }

        if (!$this->resources->hasOfferEventSupport()) {
            // PocketMine-MP older than 5.14: the pack has to be pushed manually.
            $reason = null;
            if ($this->resources->sendTo($player, $reason)) {
                $this->resources->markDelivered($player);
            } elseif ($reason !== null) {
                $this->logger->debug('Resource pack not sent to ' . $player->getName() . ': ' . $reason);
            }
        }

        $this->modes->invalidateForWorld($player->getWorld()->getFolderName());
    }

    public function onQuit(PlayerQuitEvent $event) : void
    {
        $player = $event->getPlayer();

        $this->touchProfile($player);
        $this->protection->clearWarningCooldown($player);
        $this->modes->invalidateForWorld($player->getWorld()->getFolderName());
    }

    public function onRespawn(PlayerRespawnEvent $event) : void
    {
        $player = $event->getPlayer();
        $position = $event->getRespawnPosition();

        if (!$this->protection->isLobbyWorld($position->getWorld())) {
            return;
        }

        $lobby = $this->protection->getLobbyData();

        if ($lobby->isEnforceSpawn()) {
            $spawn = $this->spawns->getWorldSpawn($position->getWorld()->getFolderName());
            if ($spawn !== null) {
                $event->setRespawnPosition($spawn);
            }
        }

        $this->applyLobbyState($player, false);
    }

    /**
     * Keeps the inventory in sync when a player is teleported into or out of a
     * lobby world by another plugin or by /lobby.
     */
    public function onTeleport(EntityTeleportEvent $event) : void
    {
        $player = $event->getEntity();
        if (!$player instanceof Player) {
            return;
        }

        $fromLobby = $this->protection->isLobbyWorld($event->getFrom()->getWorld());
        $toLobby = $this->protection->isLobbyWorld($event->getTo()->getWorld());

        if (!$fromLobby && $toLobby) {
            // Entering the lobby: the inventory changes must happen after the
            // chunk was sent, so the work is deferred by one tick.
            $this->scheduler->scheduleDelayedTask(new ClosureTask(function () use ($player) : void {
                if ($player->isOnline() && $this->protection->isInLobby($player)) {
                    $this->applyLobbyState($player, false);
                }
            }), 1);

            return;
        }

        if ($fromLobby && !$toLobby) {
            // Leaving the lobby: the selector is not needed outside.
            $this->selector->remove($player);
        }
    }

    /**
     * Injects the CoomLobby pack into the resource pack offer (PocketMine-MP
     * 5.14+ delivers packs during the login handshake).
     */
    public function onResourcePackOffer(PlayerResourcePackOfferEvent $event) : void
    {
        $this->resources->offerTo($event);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Applies the complete lobby state to a player.
     */
    private function applyLobbyState(Player $player, bool $isJoin) : void
    {
        $lobby = $this->protection->getLobbyData();

        if ($isJoin && $lobby->isClearInventoryOnJoin()) {
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
        }

        if ($lobby->isEnforceSpawn()) {
            $spawn = $this->spawns->getWorldSpawn($player->getWorld()->getFolderName());
            if ($spawn !== null) {
                $player->teleport($spawn, $this->spawns->getWorldSpawnYaw($spawn->getWorld()->getFolderName()), $this->spawns->getWorldSpawnPitch($spawn->getWorld()->getFolderName()));
            }
        }

        // Hunger cannot be modified through the public API; instead the lobby
        // uses the adventure game mode (hunger disabled by the server) and
        // zeroes every exhaustion event in DamageListener.
        $player->setHealth($player->getMaxHealth());
        $player->setFlying(false);
        $player->setAllowFlight(false);

        if ($this->config->isProtectionEnabled('force-gamemode') && $player->getGamemode() !== $lobby->getGamemode()) {
            $player->setGamemode($lobby->getGamemode());
        }

        $this->selector->remove($player);
        if ($lobby->isGiveSelectorOnJoin()) {
            $this->selector->give($player, $this->config->isSelectorSelectOnGive());
        }

        if ($isJoin && $lobby->isWelcomeMessageEnabled()) {
            $player->sendMessage($this->language->translate($player, 'lobby.welcome'));
            $player->sendMessage($this->language->translate($player, 'lobby.selector_given', [
                'mode_selector' => $this->selector->getDisplayName(),
            ]));
        }
    }

    /**
     * Persists join/leave statistics.
     */
    private function touchProfile(Player $player) : void
    {
        try {
            $profile = $this->storage->loadOrCreate($player->getName());
            $profile->setName($player->getName());
            $profile->touch();
            $this->storage->save($profile);
        } catch (\Throwable $e) {
            $this->logger->debug('Could not update the player profile: ' . $e->getMessage());
        }
    }
}
