<?php

declare(strict_types=1);

namespace CoomLobby\task;

use CoomLobby\manager\ProtectionManager;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use CoomLobby\service\PlaceholderService;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use function array_rand;
use function count;

/**
 * Periodically broadcasts a localized advertisement line.
 *
 * Messages are taken from "ads.messages.<language>" in config.yml (falling back
 * to the language files) and support the placeholders {online}, {max_online},
 * {server_ip}, {server_port} and {motd}.
 */
final class AdvertisementTask extends Task
{
    private int $rotationIndex = 0;
    private int $broadcasts = 0;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LanguageService $language,
        private readonly PlaceholderService $placeholders,
        private readonly ProtectionManager $protection,
        private readonly Server $server
    ) {
    }

    public function onRun() : void
    {
        if (!$this->config->isAdsEnabled()) {
            return;
        }

        $recipients = $this->getRecipients();
        if ($recipients === []) {
            return;
        }

        foreach ($recipients as $player) {
            $message = $this->pickMessage($this->language->getPlayerLanguage($player));
            if ($message === null) {
                continue;
            }

            $player->sendMessage(
                $this->placeholders->colorize($this->placeholders->apply($message, $player))
            );
        }

        $this->rotationIndex++;
        $this->broadcasts++;
    }

    /**
     * @return Player[]
     */
    private function getRecipients() : array
    {
        $players = $this->server->getOnlinePlayers();

        if (!$this->config->isAdsLobbyOnly()) {
            return $players;
        }

        $lobbyPlayers = [];
        foreach ($players as $player) {
            if ($this->protection->isInLobby($player)) {
                $lobbyPlayers[] = $player;
            }
        }

        return $lobbyPlayers;
    }

    private function pickMessage(string $language) : ?string
    {
        $messages = $this->language->getAdsMessages($language);
        if ($messages === []) {
            return null;
        }

        if ($this->config->getAdsOrder() === 'random') {
            return $messages[array_rand($messages)];
        }

        return $messages[$this->rotationIndex % count($messages)];
    }

    public function getBroadcastCount() : int
    {
        return $this->broadcasts;
    }

    public function getRotationIndex() : int
    {
        return $this->rotationIndex;
    }
}
