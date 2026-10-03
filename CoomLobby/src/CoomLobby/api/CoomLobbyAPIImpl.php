<?php

declare(strict_types=1);

namespace CoomLobby\api;

use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\SelectorManager;
use CoomLobby\manager\TeleportManager;
use CoomLobby\service\LanguageService;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Default implementation of the CoomLobby API. It is a thin facade over the
 * managers so that external plugins never have to know the internal wiring.
 */
final class CoomLobbyAPIImpl implements CoomLobbyAPI
{
    public function __construct(
        private readonly ProtectionManager $protection,
        private readonly SelectorManager $selector,
        private readonly TeleportManager $teleports,
        private readonly ModeManager $modes,
        private readonly LanguageService $language
    ) {
    }

    public function isLobbyWorld(World $world) : bool
    {
        return $this->protection->isLobbyWorld($world);
    }

    public function giveSelector(Player $player) : void
    {
        if (!$player->isOnline()) {
            return;
        }

        $this->selector->give($player);
    }

    public function removeSelector(Player $player) : void
    {
        if (!$player->isOnline()) {
            return;
        }

        $this->selector->remove($player);
    }

    public function teleportToMode(Player $player, string $modeId) : bool
    {
        return $this->teleports->teleportToMode($player, $modeId);
    }

    public function getModeOnline(string $modeId) : int
    {
        return $this->modes->getOnline($modeId);
    }

    public function getPlayerLanguage(Player $player) : string
    {
        return $this->language->getPlayerLanguage($player);
    }

    public function getActiveModes() : array
    {
        $result = [];

        foreach ($this->modes->getActiveModes() as $id => $mode) {
            $status = $this->modes->getStatus($mode);

            $result[$id] = [
                'id' => $id,
                'name' => $mode->getName($this->language->getDefaultLanguage()),
                'worlds' => $mode->getWorlds(),
                'online' => $this->modes->getOnlineForMode($mode),
                'max_players' => $mode->getMaxPlayers(),
                'available' => $status === \CoomLobby\object\GameMode::STATUS_AVAILABLE,
                'status' => $status,
            ];
        }

        return $result;
    }
}
