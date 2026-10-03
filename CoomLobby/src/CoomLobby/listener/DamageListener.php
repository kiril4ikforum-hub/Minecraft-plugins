<?php

declare(strict_types=1);

namespace CoomLobby\listener;

use CoomLobby\manager\ProtectionManager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\player\Player;

/**
 * Complete damage immunity inside the lobby: falls, mobs, players, lava, void,
 * explosions, drowning, starvation, ... including the hunger system.
 *
 * PocketMine-MP 5 has no FoodLevelChangeEvent anymore: hunger is disabled by
 * the adventure game mode enforced in the lobby and every exhaustion event is
 * neutralized, so the food bar always stays full.
 */
final class DamageListener implements Listener
{
    public function __construct(
        private readonly ProtectionManager $protection
    ) {
    }

    public function onDamage(EntityDamageEvent $event) : void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }

        if (!$this->protection->shouldProtect($entity, 'damage')) {
            return;
        }

        $event->cancel();

        // Void damage would still trigger a teleport back to the world spawn,
        // which is exactly the lobby behaviour we want; the event is cancelled
        // so no health is lost.
    }

    /**
     * Handled explicitly (in addition to the parent event) because plugin
     * developers registering for EntityDamageByEntityEvent expect the lobby to
     * be protected from PvP as well.
     */
    public function onDamageByEntity(EntityDamageByEntityEvent $event) : void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }

        if (!$this->protection->shouldProtect($entity, 'damage')) {
            return;
        }

        $event->cancel();
    }

    public function onExhaust(PlayerExhaustEvent $event) : void
    {
        $player = $event->getPlayer();
        if (!$player instanceof Player) {
            return;
        }

        if (!$this->protection->shouldProtect($player, 'hunger')) {
            return;
        }

        $event->setAmount(0.0);
    }

    /**
     * Safety net: if a player dies in the lobby anyway (other plugin, bug),
     * the inventory is kept and the death messages are suppressed.
     */
    public function onDeath(PlayerDeathEvent $event) : void
    {
        $player = $event->getPlayer();
        if (!$this->protection->isLobbyWorld($player->getWorld())) {
            return;
        }

        $event->setKeepInventory(true);
        $event->setKeepXp(true);
        $event->setDrops([]);
        $event->setXpDropAmount(0);
        $event->setDeathMessage('');
        $event->setDeathScreenMessage('');
    }
}
