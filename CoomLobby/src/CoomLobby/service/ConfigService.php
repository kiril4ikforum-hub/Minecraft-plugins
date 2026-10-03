<?php

declare(strict_types=1);

namespace CoomLobby\service;

use pocketmine\utils\Config;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function gettype;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ksort;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Typed, defensive access layer for config.yml.
 *
 * Every value read from the file passes through a validation helper: when a
 * setting has an unexpected type or is out of range the documented default is
 * used instead and a warning is written to the console exactly once per key.
 * The plugin therefore never aborts the server startup because of a broken
 * configuration.
 */
class ConfigService
{
    public const DEFAULT_LANGUAGE = 'ru';

    /** Keys may be written in kebab-case (preferred) or snake_case. */
    private const KEY_SEPARATOR_CANDIDATES = ['-', '_', ''];

    private const PROTECTION_FLAGS = [
        'block-break',
        'block-place',
        'item-drop',
        'item-pickup',
        'damage',
        'hunger',
        'chat',
        'interact-blocks',
        'use-items',
        'projectiles',
        'flight',
        'force-gamemode',
        'inventory-transaction',
    ];

    private string $dataFolder;
    private \Logger $logger;
    private Config $config;

    /** @var array<string, true> */
    private array $reportedKeys = [];

    public function __construct(string $dataFolder, \Logger $logger)
    {
        $this->dataFolder = $dataFolder;
        $this->logger = $logger;
        $this->config = new Config($this->getConfigPath(), Config::YAML, []);
    }

    public function getConfigPath() : string
    {
        return $this->dataFolder . 'config.yml';
    }

    public function getDataFolder() : string
    {
        return $this->dataFolder;
    }

    public function load() : void
    {
        $this->config = new Config($this->getConfigPath(), Config::YAML, []);
        $this->reportedKeys = [];
    }

    public function reload() : void
    {
        $this->config->reload();
        $this->reportedKeys = [];
    }

    public function getConfig() : Config
    {
        return $this->config;
    }

    /**
     * Raw access to a dotted config path (for advanced/integration use).
     */
    public function raw(string $key, mixed $default = null) : mixed
    {
        $value = $this->config->getNested($key, $default);

        return $value === null ? $default : $value;
    }

    // ------------------------------------------------------------------
    // Value helpers
    // ------------------------------------------------------------------

    /**
     * Returns the first key that exists in $data, trying "-", "_" and plain
     * spelling variants (i.e. "max-players", "max_players", "maxplayers").
     *
     * @param string[] $keys
     */
    public function pick(array $data, array $keys, mixed $default = null) : mixed
    {
        foreach ($keys as $key) {
            foreach (self::KEY_SEPARATOR_CANDIDATES as $separator) {
                $candidate = str_replace('-', $separator, strtolower($key));
                if (array_key_exists($candidate, $data)) {
                    return $data[$candidate];
                }
            }
        }

        return $default;
    }

    public function toBool(mixed $value, bool $default, string $context = '') : bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0.0 && $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', 'yes', 'on', 'enable', 'enabled', '1'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', 'no', 'off', 'disable', 'disabled', '0', ''], true)) {
                return false;
            }
        }

        if ($context !== '') {
            $this->reportInvalidValue($context, $value, 'boolean (true/false)');
        }

        return $default;
    }

    public function toInt(mixed $value, int $default, ?int $min = null, ?int $max = null, string $context = '') : int
    {
        if (is_bool($value) || !is_numeric($value)) {
            if ($context !== '') {
                $this->reportInvalidValue($context, $value, 'integer');
            }

            return $default;
        }

        $result = (int) $value;

        if ($min !== null && $result < $min) {
            if ($context !== '') {
                $this->reportInvalidValue($context, $value, 'integer >= ' . $min);
            }

            return $min;
        }

        if ($max !== null && $result > $max) {
            if ($context !== '') {
                $this->reportInvalidValue($context, $value, 'integer <= ' . $max);
            }

            return $max;
        }

        return $result;
    }

    public function toFloat(mixed $value, float $default, string $context = '') : float
    {
        if (is_bool($value) || !is_numeric($value)) {
            if ($context !== '') {
                $this->reportInvalidValue($context, $value, 'number');
            }

            return $default;
        }

        return (float) $value;
    }

    public function toString(mixed $value, string $default, string $context = '') : string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value !== null && $context !== '') {
            $this->reportInvalidValue($context, $value, 'string');
        }

        return $default;
    }

    /**
     * @return string[]
     */
    public function toStringList(mixed $value, string $context = '') : array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (!is_array($value)) {
            if ($value !== null && $value !== [] && $context !== '') {
                $this->reportInvalidValue($context, $value, 'list of strings');
            }

            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $result[] = trim($entry);
            } elseif (is_int($entry) || is_float($entry)) {
                $result[] = (string) $entry;
            } elseif ($context !== '') {
                $this->reportInvalidValue($context, $entry, 'string list entry');
            }
        }

        return array_values($result);
    }

    /**
     * Resolves a localized value that is stored either as
     *   name: {ru: "..", en: ".."}
     *   name: ".."                     (same text for every language)
     * or as flat keys (name_ru / name-ru / name_en / name-en).
     *
     * @return array<string, string>
     */
    public function pickLocalized(array $data, array $keys) : array
    {
        $result = [];

        foreach ($keys as $key) {
            foreach (self::KEY_SEPARATOR_CANDIDATES as $separator) {
                $candidate = str_replace('-', $separator, strtolower($key));
                if (!array_key_exists($candidate, $data)) {
                    continue;
                }

                $value = $data[$candidate];
                if (is_array($value)) {
                    foreach ($value as $language => $text) {
                        if ((is_string($text) || is_int($text) || is_float($text)) && !isset($result[strtolower((string) $language)])) {
                            $result[strtolower((string) $language)] = (string) $text;
                        }
                    }
                } elseif (is_string($value) && trim($value) !== '' && $result === []) {
                    $result['*'] = trim($value);
                }
            }
        }

        // Flat spellings: name_ru / name-en / label_ru ...
        foreach ($keys as $key) {
            foreach (['_', '-', ''] as $separator) {
                foreach ($this->getKnownLanguages() as $language) {
                    $candidate = str_replace('-', $separator, strtolower($key)) . $separator . $language;
                    if (array_key_exists($candidate, $data) && is_string($data[$candidate]) && trim($data[$candidate]) !== '') {
                        $result[$language] ??= trim($data[$candidate]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Resolves a localized list (ads, selector lore, ...).
     *
     * @return array<string, string[]>
     */
    public function pickLocalizedList(array $data, array $keys) : array
    {
        $result = [];

        foreach ($keys as $key) {
            foreach (self::KEY_SEPARATOR_CANDIDATES as $separator) {
                $candidate = str_replace('-', $separator, strtolower($key));
                if (!array_key_exists($candidate, $data)) {
                    continue;
                }

                $value = $data[$candidate];
                if (is_array($value)) {
                    foreach ($value as $language => $lines) {
                        $list = $this->toStringList($lines, $key . '.' . (string) $language);
                        if ($list !== [] && !isset($result[strtolower((string) $language)])) {
                            $result[strtolower((string) $language)] = $list;
                        }
                    }
                } elseif (is_string($value) && trim($value) !== '' && !isset($result['*'])) {
                    $result['*'] = [trim($value)];
                }
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    public function getKnownLanguages() : array
    {
        return ['ru', 'en'];
    }

    private function reportInvalidValue(string $context, mixed $value, string $expected) : void
    {
        if (isset($this->reportedKeys[$context])) {
            return;
        }

        $this->reportedKeys[$context] = true;
        $this->logger->warning(
            'Invalid value for config key "' . $context . '": expected ' . $expected
            . ', got ' . gettype($value) . ' - the default value is used instead.'
        );
    }

    // ------------------------------------------------------------------
    // Language
    // ------------------------------------------------------------------

    public function getLanguageDefault() : string
    {
        $configured = strtolower($this->toString($this->raw('language.default', self::DEFAULT_LANGUAGE), self::DEFAULT_LANGUAGE, 'language.default'));

        return $configured === '' ? self::DEFAULT_LANGUAGE : $configured;
    }

    public function isCoomLanguageEnabled() : bool
    {
        $value = $this->raw('language.use-coomlanguage', $this->raw('language.use_coomlanguage', true));

        return $this->toBool($value, true, 'language.use-coomlanguage');
    }

    /**
     * @return string[]
     */
    public function getCoomLanguageMethods() : array
    {
        $methods = $this->toStringList($this->raw('language.coomlanguage-methods', null), 'language.coomlanguage-methods');
        if ($methods === []) {
            $methods = ['getPlayerLanguage', 'getLanguage', 'getLanguageCode'];
        }

        return $methods;
    }

    public function getLanguageCacheTtl() : int
    {
        return $this->toInt($this->raw('language.cache-ttl', 60), 60, 0, 3600, 'language.cache-ttl');
    }

    // ------------------------------------------------------------------
    // Lobby
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    public function getLobbyWorlds() : array
    {
        $worlds = $this->toStringList($this->raw('lobby.worlds', []), 'lobby.worlds');

        return array_values($worlds);
    }

    public function getPrimaryLobbyWorld() : ?string
    {
        $configured = $this->toString($this->raw('lobby.primary-world', ''), '', 'lobby.primary-world');
        if ($configured !== '') {
            return $configured;
        }

        $worlds = $this->getLobbyWorlds();

        return $worlds[0] ?? null;
    }

    public function isClearInventoryOnJoin() : bool
    {
        return $this->toBool($this->pick($this->getLobbySection(), ['clear-inventory-on-join'], true), true, 'lobby.clear-inventory-on-join');
    }

    public function getLobbyGamemodeName() : string
    {
        return strtolower($this->toString($this->raw('lobby.gamemode', 'adventure'), 'adventure', 'lobby.gamemode'));
    }

    public function isEnforceLobbySpawn() : bool
    {
        return $this->toBool($this->pick($this->getLobbySection(), ['enforce-spawn'], true), true, 'lobby.enforce-spawn');
    }

    public function isGiveSelectorOnJoin() : bool
    {
        return $this->toBool($this->pick($this->getLobbySection(), ['give-selector-on-join'], true), true, 'lobby.give-selector-on-join');
    }

    public function isWelcomeMessageEnabled() : bool
    {
        return $this->toBool($this->pick($this->getLobbySection(), ['welcome-message'], true), true, 'lobby.welcome-message');
    }

    public function isHonorBypassPermission() : bool
    {
        return $this->toBool($this->pick($this->getLobbySection(), ['honor-bypass-permission'], true), true, 'lobby.honor-bypass-permission');
    }

    /**
     * @return array<string, mixed>
     */
    public function getLobbySection() : array
    {
        $section = $this->raw('lobby', []);

        return is_array($section) ? $section : [];
    }

    // ------------------------------------------------------------------
    // Selector
    // ------------------------------------------------------------------

    public function getSelectorItemId() : string
    {
        $identifier = $this->toString($this->pick($this->getSelectorSection(), ['item-id'], 'minecraft:compass'), 'minecraft:compass', 'selector.item-id');

        return $identifier === '' ? 'minecraft:compass' : $identifier;
    }

    public function getSelectorSlot() : int
    {
        return $this->toInt($this->pick($this->getSelectorSection(), ['slot'], 8), 8, 0, 35, 'selector.slot');
    }

    public function isSelectorSelectOnGive() : bool
    {
        return $this->toBool($this->pick($this->getSelectorSection(), ['select-on-give'], false), false, 'selector.select-on-give');
    }

    public function getSelectorCooldownMs() : int
    {
        return $this->toInt($this->pick($this->getSelectorSection(), ['cooldown-ms'], 1000), 1000, 0, 60000, 'selector.cooldown-ms');
    }

    /**
     * @return array<string, string>
     */
    public function getSelectorNames() : array
    {
        return $this->pickLocalized($this->getSelectorSection(), ['name', 'title']);
    }

    /**
     * @return array<string, string[]>
     */
    public function getSelectorLore() : array
    {
        return $this->pickLocalizedList($this->getSelectorSection(), ['lore', 'description']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSelectorSection() : array
    {
        $section = $this->raw('selector', []);

        return is_array($section) ? $section : [];
    }

    // ------------------------------------------------------------------
    // Modes
    // ------------------------------------------------------------------

    /**
     * Returns the raw mode definitions, supporting both layouts:
     *   modes: { list: { bedwars: {...} } }
     *   modes: { bedwars: {...} }
     *
     * @return array<string, array<string, mixed>>
     */
    public function getModeDefinitions() : array
    {
        $section = $this->raw('modes', []);
        if (!is_array($section)) {
            $this->reportInvalidValue('modes', $section, 'map of mode definitions');

            return [];
        }

        $list = $this->pick($section, ['list'], null);
        if (is_array($list)) {
            /** @var array<string, array<string, mixed>> $normalized */
            $normalized = [];
            foreach ($list as $id => $definition) {
                if (is_array($definition)) {
                    $normalized[(string) $id] = $definition;
                }
            }

            return $normalized;
        }

        $definitions = [];
        foreach ($section as $id => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            // Skip global option keys that live next to the mode list.
            if (in_array(strtolower((string) $id), $this->getModeOptionKeys(), true)) {
                continue;
            }

            $definitions[(string) $id] = $definition;
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @return string[]
     */
    private function getModeOptionKeys() : array
    {
        return [
            'cache-ttl', 'cache_ttl', 'cacettl',
            'auto-load-worlds', 'auto_load_worlds', 'autoloadworlds',
            'world-balancing', 'world_balancing', 'worldbalancing',
            'teleport-cooldown', 'teleport_cooldown', 'teleportcooldown',
            'lobby-teleport-cooldown', 'lobby_teleport_cooldown', 'lobbyteleportcooldown',
            'list',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getModesSection() : array
    {
        $section = $this->raw('modes', []);

        return is_array($section) ? $section : [];
    }

    public function getModeCacheTtl() : int
    {
        return $this->toInt($this->pick($this->getModesSection(), ['cache-ttl'], 3), 3, 0, 300, 'modes.cache-ttl');
    }

    public function isAutoLoadWorlds() : bool
    {
        return $this->toBool($this->pick($this->getModesSection(), ['auto-load-worlds'], true), true, 'modes.auto-load-worlds');
    }

    public function isWorldBalancing() : bool
    {
        return $this->toBool($this->pick($this->getModesSection(), ['world-balancing'], true), true, 'modes.world-balancing');
    }

    public function getModeTeleportCooldown() : int
    {
        return $this->toInt($this->pick($this->getModesSection(), ['teleport-cooldown'], 5), 5, 0, 3600, 'modes.teleport-cooldown');
    }

    public function getLobbyTeleportCooldown() : int
    {
        return $this->toInt($this->pick($this->getModesSection(), ['lobby-teleport-cooldown'], 3), 3, 0, 3600, 'modes.lobby-teleport-cooldown');
    }

    // ------------------------------------------------------------------
    // Protection
    // ------------------------------------------------------------------

    public function isProtectionEnabled(string $flag) : bool
    {
        if (!in_array($flag, self::PROTECTION_FLAGS, true)) {
            return true;
        }

        $section = $this->raw('protection', []);

        return $this->toBool(
            is_array($section) ? $this->pick($section, [$flag], true) : true,
            true,
            'protection.' . $flag
        );
    }

    /**
     * @return string[]
     */
    public function getAllowedItems() : array
    {
        return $this->toStringList($this->raw('protection.allowed-items', []), 'protection.allowed-items');
    }

    /**
     * @return string[]
     */
    public function getBlockedItems() : array
    {
        $configured = $this->raw('protection.blocked-items', null);
        if ($configured === null) {
            return [
                'minecraft:ender_pearl', 'minecraft:snowball', 'minecraft:egg', 'minecraft:bucket',
                'minecraft:water_bucket', 'minecraft:lava_bucket', 'minecraft:potion', 'minecraft:splash_potion',
                'minecraft:lingering_potion', 'minecraft:fishing_rod', 'minecraft:flint_and_steel',
                'minecraft:fire_charge', 'minecraft:bow', 'minecraft:crossbow', 'minecraft:arrow',
                'minecraft:firework_rocket', 'minecraft:end_crystal', 'minecraft:tnt',
            ];
        }

        return $this->toStringList($configured, 'protection.blocked-items');
    }

    public function getWarningCooldown() : int
    {
        return $this->toInt($this->pick($this->raw('protection', []) ?: [], ['warning-cooldown'], 5), 5, 0, 300, 'protection.warning-cooldown');
    }

    // ------------------------------------------------------------------
    // Advertisements
    // ------------------------------------------------------------------

    public function isAdsEnabled() : bool
    {
        return $this->toBool($this->raw('ads.enabled', true), true, 'ads.enabled');
    }

    public function getAdsIntervalSeconds() : int
    {
        return $this->toInt($this->raw('ads.interval-seconds', 600), 600, 10, 86400, 'ads.interval-seconds');
    }

    public function getAdsOrder() : string
    {
        $order = strtolower($this->toString($this->raw('ads.order', 'rotate'), 'rotate', 'ads.order'));

        return in_array($order, ['rotate', 'random'], true) ? $order : 'rotate';
    }

    public function isAdsLobbyOnly() : bool
    {
        return $this->toBool($this->raw('ads.lobby-only', false), false, 'ads.lobby-only');
    }

    public function getAdsIpFallback() : string
    {
        return $this->toString($this->raw('ads.ip-fallback', 'play.example.com'), 'play.example.com', 'ads.ip-fallback');
    }

    /**
     * @return array<string, string[]>
     */
    public function getAdsMessages() : array
    {
        $section = $this->raw('ads', []);

        return is_array($section) ? $this->pickLocalizedList($section, ['messages']) : [];
    }

    // ------------------------------------------------------------------
    // Resource pack
    // ------------------------------------------------------------------

    public function isResourcePackEnabled() : bool
    {
        return $this->toBool($this->pick($this->getResourcePackSection(), ['enabled'], true), true, 'resource-pack.enabled');
    }

    public function isResourcePackBuild() : bool
    {
        return $this->toBool($this->pick($this->getResourcePackSection(), ['build'], true), true, 'resource-pack.build');
    }

    public function isResourcePackRebuildAlways() : bool
    {
        return $this->toBool($this->pick($this->getResourcePackSection(), ['rebuild-always'], false), false, 'resource-pack.rebuild-always');
    }

    public function getResourcePackFile() : string
    {
        return $this->toString($this->pick($this->getResourcePackSection(), ['file'], 'CoomLobbyRP.mcpack'), 'CoomLobbyRP.mcpack', 'resource-pack.file');
    }

    public function getResourcePackFolder() : string
    {
        return $this->toString($this->pick($this->getResourcePackSection(), ['pack-folder'], 'rp/CoomLobbyRP'), 'rp/CoomLobbyRP', 'resource-pack.pack-folder');
    }

    public function isResourcePackForceAccept() : bool
    {
        return $this->toBool($this->pick($this->getResourcePackSection(), ['force-accept'], false), false, 'resource-pack.force-accept');
    }

    public function getResourcePackExpectedUuid() : string
    {
        return strtolower($this->toString($this->pick($this->getResourcePackSection(), ['expected-uuid'], ''), '', 'resource-pack.expected-uuid'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getResourcePackSection() : array
    {
        $section = $this->raw('resource-pack', []);

        return is_array($section) ? $section : [];
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    public function getStorageType() : string
    {
        $type = strtolower($this->toString($this->raw('storage.type', 'auto'), 'auto', 'storage.type'));

        return in_array($type, ['auto', 'sqlite', 'yaml'], true) ? $type : 'auto';
    }

    public function getStorageSqliteFile() : string
    {
        $file = $this->toString($this->raw('storage.sqlite.file', 'coomlobby.db'), 'coomlobby.db', 'storage.sqlite.file');

        return $file === '' ? 'coomlobby.db' : $file;
    }

    public function getStorageYamlFile() : string
    {
        $file = $this->toString($this->raw('storage.yaml.file', 'players.yml'), 'players.yml', 'storage.yaml.file');

        return $file === '' ? 'players.yml' : $file;
    }

    /**
     * Number of ticks between two online-counter refreshes (never below 1s).
     */
    public function getOnlineRefreshPeriodTicks() : int
    {
        $ttl = $this->getModeCacheTtl();

        return ($ttl > 0 ? $ttl : 3) * 20;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllConfigData() : array
    {
        $data = $this->config->getAll();
        if (!is_array($data)) {
            return [];
        }

        return array_map(static fn (mixed $entry) : mixed => $entry, $data);
    }

    /**
     * Number of entries in the raw configuration (used by /coomlobby info).
     */
    public function countTopLevelEntries() : int
    {
        return count($this->getAllConfigData());
    }
}
