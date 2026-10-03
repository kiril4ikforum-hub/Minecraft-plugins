<?php

declare(strict_types=1);

namespace CoomLobby\command;

use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\SpawnManager;
use CoomLobby\service\LanguageService;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * "/setlobby [world]" - stores the current position as the spawn point of the
 * world (spawns.yml). When the world is a lobby world it also becomes the
 * primary lobby spawn used by /lobby.
 */
final class SetLobbyCommand implements CommandExecutor
{
    public function __construct(
        private readonly SpawnManager $spawns,
        private readonly ProtectionManager $protection,
        private readonly LanguageService $language,
        private readonly Server $server
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

        $world = $sender->getWorld();

        // Optional world argument: it only validates that the admin is
        // standing in the world they want to configure.
        if (isset($args[0]) && $args[0] !== '') {
            $requested = $this->server->getWorldManager()->getWorldByName($args[0]);
            if ($requested === null) {
                $sender->sendMessage($this->language->translate($sender, 'command.setlobby.world_not_found', ['world' => $args[0]]));

                return true;
            }

            if ($requested->getFolderName() !== $world->getFolderName()) {
                $sender->sendMessage($this->language->translate($sender, 'command.setlobby.not_in_world', ['world' => $requested->getFolderName()]));

                return true;
            }
        }

        $location = $sender->getLocation();
        $position = new Position($location->getX(), $location->getY(), $location->getZ(), $world);

        $isLobbyWorld = $this->protection->isLobbyWorldName($world->getFolderName());
        $this->spawns->setLobbySpawn($position, $isLobbyWorld, $location->getYaw(), $location->getPitch());

        if ($isLobbyWorld) {
            // Keep the vanilla world spawn in sync so that other plugins and
            // /spawn use the same point.
            $world->setSpawnLocation($position);

            $sender->sendMessage($this->language->translate($sender, 'command.setlobby.saved', [
                'world' => $world->getFolderName(),
                'x' => (int) $position->getX(),
                'y' => (int) $position->getY(),
                'z' => (int) $position->getZ(),
            ]));

            return true;
        }

        $sender->sendMessage($this->language->translate($sender, 'command.setlobby.saved_mode', [
            'world' => $world->getFolderName(),
            'x' => (int) $position->getX(),
            'y' => (int) $position->getY(),
            'z' => (int) $position->getZ(),
        ]));
        $sender->sendMessage($this->language->translate($sender, 'command.setlobby.world_not_lobby', [
            'world' => $world->getFolderName(),
        ]));

        return true;
    }
}
