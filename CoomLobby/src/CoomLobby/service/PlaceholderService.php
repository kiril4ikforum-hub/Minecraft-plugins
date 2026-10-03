<?php

declare(strict_types=1);

namespace CoomLobby\service;

use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Small string replacement engine used for every text sent to players.
 *
 * Supported automatic placeholders:
 *   {online} {max_online} {server_ip} {server_port} {motd} {player} {prefix}
 * Any additional placeholder can be passed as an associative array; unknown
 * placeholders are left untouched so that typos never destroy the message.
 */
class PlaceholderService
{
    private ConfigService $config;
    private Server $server;
    private \Logger $logger;

    public function __construct(ConfigService $config, Server $server, \Logger $logger)
    {
        $this->config = $config;
        $this->server = $server;
        $this->logger = $logger;
    }

    /**
     * @param array<string, string|int|float> $extra
     */
    public function apply(string $text, ?Player $player = null, array $extra = []) : string
    {
        return $this->replaceTokens($text, $this->getAutomaticPlaceholders($player) + $extra);
    }

    /**
     * @return array<string, string>
     */
    public function getAutomaticPlaceholders(?Player $player = null) : array
    {
        $placeholders = [
            'online' => (string) count($this->server->getOnlinePlayers()),
            'max_online' => (string) $this->server->getMaxPlayers(),
            'server_ip' => $this->resolveServerIp(),
            'server_port' => (string) $this->server->getPort(),
            'motd' => $this->resolveMotd(),
            'player' => $player?->getName() ?? '',
        ];

        return $placeholders;
    }

    /**
     * @param array<string, string|int|float> $placeholders
     */
    public function replaceTokens(string $text, array $placeholders) : string
    {
        if ($placeholders === []) {
            return $text;
        }

        $search = [];
        $replace = [];
        foreach ($placeholders as $key => $value) {
            if (is_float($value) || is_int($value) || is_string($value)) {
                $search[] = '{' . $key . '}';
                $replace[] = (string) $value;
            }
        }

        return str_replace($search, $replace, $text);
    }

    /**
     * Converts "&"-style color codes into the section signs used by Bedrock.
     */
    public function colorize(string $text) : string
    {
        return TextFormat::colorize($text);
    }

    /**
     * Resolves the address shown by the {server_ip} placeholder. Wildcard and
     * loopback bind addresses are never leaked to players.
     */
    public function resolveServerIp() : string
    {
        $fallback = $this->config->getAdsIpFallback();
        $ip = $this->server->getIp();

        if ($ip === '' || $ip === '0.0.0.0' || $ip === '::' || $ip === '127.0.0.1' || $ip === 'localhost') {
            return $fallback;
        }

        $port = $this->server->getPort();

        return $port === 19132 ? $ip : $ip . ':' . $port;
    }

    public function resolveMotd() : string
    {
        $motd = (string) $this->server->getMotd();

        return trim($motd) === '' ? 'Minecraft: Bedrock Edition' : trim($motd);
    }

    /**
     * Lower-case helper used by other services.
     */
    public function normalizeKey(string $key) : string
    {
        return strtolower(trim($key));
    }
}
