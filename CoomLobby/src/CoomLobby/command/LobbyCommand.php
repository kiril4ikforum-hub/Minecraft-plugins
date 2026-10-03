<?php

declare(strict_types=1);

namespace CoomLobby\command;

use CoomLobby\manager\TeleportManager;
use CoomLobby\service\LanguageService;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * "/lobby" - sends the player back to the main lobby.
 */
final class LobbyCommand implements CommandExecutor
{
    public function __construct(
        private readonly TeleportManager $teleports,
        private readonly LanguageService $language
    ) {
    }

    /**
     * @param string[] $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage($this->language->translate(null, 'command.player_only'));

            return true;
        }

        $this->teleports->teleportToLobby($sender);

        return true;
    }
}
