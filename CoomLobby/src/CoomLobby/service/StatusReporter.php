<?php

declare(strict_types=1);

namespace CoomLobby\service;

use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\SpawnManager;
use CoomLobby\storage\StorageProvider;
use pocketmine\player\Player;
use pocketmine\Server;
use function count;
use function implode;
use function round;

/**
 * Collects the diagnostic data shown by "/coomlobby info".
 *
 * Keeping this in a service (instead of inside the command) makes the command
 * class small and lets other plugins reuse the same numbers through
 * CoomLobbyAPI friendly managers.
 */
final class StatusReporter
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly LanguageService $language,
        private readonly ModeManager $modes,
        private readonly ResourceManager $resources,
        private readonly ProtectionManager $protection,
        private readonly SpawnManager $spawns,
        private readonly StorageProvider $storage,
        private readonly Server $server,
        private readonly string $pluginVersion
    ) {
    }

    public function getPluginVersion() : string
    {
        return $this->pluginVersion;
    }

    /**
     * @return array<string, string> translated label => value
     */
    public function getInfo(?Player $player = null) : array
    {
        $lobby = $this->protection->getLobbyData();
        $spawn = $this->spawns->getLobbySpawn();

        $modeParts = [];
        foreach ($this->modes->getStatistics() as $modeId => $online) {
            $modeParts[] = $this->language->translate($player, 'status.mode_online', [
                'mode' => $modeId,
                'online' => $online,
            ]);
        }

        $spawnText = 'not set';
        if ($spawn !== null) {
            $spawnText = $spawn->getWorld()->getFolderName() . ' '
                . round($spawn->getX(), 1) . ' ' . round($spawn->getY(), 1) . ' ' . round($spawn->getZ(), 1);
        }

        return [
            $this->label($player, 'version') => $this->pluginVersion,
            $this->label($player, 'server_version') => $this->server->getVersion() . ' (API ' . $this->server->getApiVersion() . ')',
            $this->label($player, 'storage') => $this->storage->getStatus(),
            $this->label($player, 'profiles') => (string) $this->storage->count(),
            $this->label($player, 'resource_pack') => $this->resources->getStatusLine(),
            $this->label($player, 'default_language') => $this->language->getDefaultLanguage()
                . ' (' . implode(', ', $this->language->getSupportedLanguages()) . ')',
            $this->label($player, 'lobby_worlds') => $lobby->getWorlds() === [] ? 'none' : implode(', ', $lobby->getWorlds()),
            $this->label($player, 'primary_world') => $lobby->getPrimaryWorld() ?? 'not set',
            $this->label($player, 'lobby_spawn') => $spawnText,
            $this->label($player, 'lobby_gamemode') => $lobby->getGamemode()->name,
            $this->label($player, 'modes') => count($this->modes->getActiveModes()) . '/' . $this->modes->countModes() . ' active',
            $this->label($player, 'modes_online') => $modeParts === [] ? '-' : implode(' ', $modeParts),
            $this->label($player, 'players_online') => (string) count($this->server->getOnlinePlayers()),
            $this->label($player, 'loaded_worlds') => (string) count($this->server->getWorldManager()->getWorlds()),
            $this->label($player, 'config_entries') => (string) $this->config->countTopLevelEntries(),
        ];
    }

    private function label(?Player $player, string $key) : string
    {
        return $this->language->translate($player, 'status.' . $key);
    }

    public function getHeader(?Player $player = null) : string
    {
        return $this->language->translate($player, 'command.admin.info_header', [
            'version' => $this->pluginVersion,
        ]);
    }
}
