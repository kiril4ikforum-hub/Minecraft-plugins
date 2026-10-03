<?php

declare(strict_types=1);

namespace CoomLobby\listener;

use CoomLobby\manager\ProtectionManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;

/**
 * Blocks public chat inside lobby worlds unless the player owns the
 * "coomlobby.chat" permission (or bypasses the protection entirely).
 *
 * The anti-spam cooldown lives in ProtectionManager, so a player never
 * receives more than one identical warning every few seconds.
 */
final class ChatListener implements Listener
{
    public function __construct(
        private readonly ProtectionManager $protection
    ) {
    }

    public function onChat(PlayerChatEvent $event) : void
    {
        $player = $event->getPlayer();

        if ($this->protection->canChat($player)) {
            return;
        }

        $event->cancel();
        $this->protection->warn($player, 'protection.chat');
    }
}
