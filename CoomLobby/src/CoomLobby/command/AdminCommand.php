<?php

declare(strict_types=1);

namespace CoomLobby\command;

use Closure;
use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\TeleportManager;
use CoomLobby\service\LanguageService;
use CoomLobby\service\StatusReporter;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * "/coomlobby <reload|info|tp|rp>" (alias "/cl") - administrative control.
 */
final class AdminCommand implements CommandExecutor
{
    /**
     * @param Closure(): bool $reloadHandler reloads the plugin configuration
     */
    public function __construct(
        private readonly Closure $reloadHandler,
        private readonly StatusReporter $status,
        private readonly ModeManager $modes,
        private readonly TeleportManager $teleports,
        private readonly ResourceManager $resources,
        private readonly LanguageService $language,
        private readonly Server $server
    ) {
    }

    /**
     * @param string[] $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
    {
        $subCommand = strtolower($args[0] ?? '');

        switch ($subCommand) {
            case 'reload':
                return $this->handleReload($sender);

            case 'info':
                return $this->handleInfo($sender);

            case 'tp':
                return $this->handleTeleport($sender, $args);

            case 'rp':
                return $this->handleResourcePack($sender, $args);

            default:
                $sender->sendMessage($this->language->translate($this->resolvePlayer($sender), 'command.admin.unknown_subcommand'));

                return true;
        }
    }

    private function handleReload(CommandSender $sender) : bool
    {
        $result = ($this->reloadHandler)();

        if ($result) {
            $sender->sendMessage($this->language->translate($this->resolvePlayer($sender), 'command.admin.reloaded'));
        } else {
            $sender->sendMessage($this->language->translate($this->resolvePlayer($sender), 'command.admin.reload_failed'));
        }

        return true;
    }

    private function handleInfo(CommandSender $sender) : bool
    {
        $player = $this->resolvePlayer($sender);

        $sender->sendMessage($this->status->getHeader($player));

        foreach ($this->status->getInfo($player) as $key => $value) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.info_line', [
                'key' => $key,
                'value' => $value,
            ]));
        }

        return true;
    }

    /**
     * @param string[] $args
     */
    private function handleTeleport(CommandSender $sender, array $args) : bool
    {
        $player = $this->resolvePlayer($sender);

        if (!isset($args[1], $args[2])) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.tp_usage'));

            return true;
        }

        $target = $this->server->getPlayerExact($args[1]);
        if (!$target instanceof Player) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.player_not_found', ['player' => $args[1]]));

            return true;
        }

        $mode = $this->modes->getMode($args[2]);
        if ($mode === null) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.tp_unknown_mode', ['mode' => $args[2]]));

            return true;
        }

        $success = $this->teleports->teleportToMode($target, $mode->getId(), true);

        $sender->sendMessage($this->language->translate($player, $success ? 'command.admin.tp_success' : 'command.admin.tp_failed', [
            'player' => $target->getName(),
            'mode' => $mode->getId(),
        ]));

        return true;
    }

    /**
     * @param string[] $args
     */
    private function handleResourcePack(CommandSender $sender, array $args) : bool
    {
        $player = $this->resolvePlayer($sender);

        if (!isset($args[1])) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.rp_usage'));

            return true;
        }

        $target = $this->server->getPlayerExact($args[1]);
        if (!$target instanceof Player) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.player_not_found', ['player' => $args[1]]));

            return true;
        }

        if (!$this->resources->isActive()) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.rp_disabled'));

            return true;
        }

        $reason = null;
        if ($this->resources->sendTo($target, $reason)) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.rp_sent', [
                'player' => $target->getName(),
            ]));

            return true;
        }

        // Modern server versions deliver resource packs during the login
        // handshake only; explain what happens instead of pretending to resend.
        if ($reason !== null && str_contains($reason, 'handshake')) {
            $sender->sendMessage($this->language->translate($player, 'command.admin.rp_handshake', [
                'player' => $target->getName(),
                'pack' => $this->resources->getPackVersionString() !== ''
                    ? $this->resources->getPackVersionString()
                    : (string) $this->resources->getPackUuid(),
            ]));

            return true;
        }

        $sender->sendMessage($this->language->translate($player, 'command.admin.rp_failed', [
            'player' => $target->getName(),
            'reason' => $reason ?? 'unknown',
        ]));

        return true;
    }

    private function resolvePlayer(CommandSender $sender) : ?Player
    {
        return $sender instanceof Player ? $sender : null;
    }
}
