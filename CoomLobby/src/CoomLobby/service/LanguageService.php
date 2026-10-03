<?php

declare(strict_types=1);

namespace CoomLobby\service;

use CoomLobby\object\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\utils\Config;
use function array_key_exists;
use function array_keys;
use function explode;
use function file_exists;
use function is_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function is_string;
use function method_exists;
use function microtime;
use function str_contains;
use function strtolower;
use function substr;
use function trim;

/**
 * Localization layer.
 *
 * Resolution order for every message key:
 *   1. the language file of the player (plugin_data/CoomLobby/lang/<code>.yml)
 *   2. the configured default language file
 *   3. the hardcoded fallback below (the plugin keeps working even when all
 *      language files have been deleted)
 *
 * The player language is taken from the CoomLanguage plugin when it is
 * installed and enabled, otherwise the client locale (Player::getLocale()) is
 * used, with the configured default as the last resort.
 *
 * Values defined in config.yml (selector name/lore, mode labels, ads) take
 * precedence over the language files, so server owners can override any of
 * those strings without touching the lang folder.
 */
class LanguageService
{
    /** Ultimate fallback used when a language file is missing. */
    private const FALLBACK_MESSAGES = [
        'prefix' => '&8[&6CoomLobby&8] &r',
        'form.title' => '&l&6CoomLobby &8- &fMode Selection',
        'form.content' => '&7Choose a game mode below.',
        'form.close' => '&cClose',
        'mode.status.available' => '&aAvailable',
        'mode.status.full' => '&cFull',
        'mode.status.unavailable' => '&7Unavailable',
        'mode.status.locked' => '&cLocked',
        'mode.online' => '&7Online: &a{current}',
        'mode.online_limited' => '&7Online: &a{current}&7/&a{max}',
        'mode.button' => '&6{mode}\n{online}\n{status}',
        'command.lobby.teleported' => '{prefix}&aYou have been teleported to the lobby.',
        'command.lobby.cooldown' => '{prefix}&cPlease wait &e{time}s&c before teleporting again.',
        'command.lobby.unavailable' => '{prefix}&cThe lobby is not available right now.',
        'command.player_only' => '{prefix}&cThis command can only be used in game.',
        'command.setlobby.saved' => '{prefix}&aLobby spawn for world &e{world}&a saved.',
        'command.setlobby.saved_mode' => '{prefix}&aSpawn point for world &e{world}&a saved.',
        'command.setlobby.world_not_lobby' => '{prefix}&eWorld &e{world}&e is not listed in lobby.worlds.',
        'command.setlobby.world_not_found' => '{prefix}&cWorld &e{world}&c was not found or is not loaded.',
        'command.setlobby.not_in_world' => '{prefix}&cYou have to stand in world &e{world}&c to set its spawn.',
        'command.admin.reloaded' => '{prefix}&aConfiguration, language files, modes and tasks reloaded.',
        'command.admin.reload_failed' => '{prefix}&cThe reload failed, see the server console for details.',
        'command.admin.info_header' => '{prefix}&6CoomLobby &7v{version}',
        'command.admin.info_line' => '&7 - &f{key}&7: &a{value}',
        'command.admin.tp_usage' => '{prefix}&cUsage: /coomlobby tp <player> <mode>',
        'command.admin.tp_success' => '{prefix}&aPlayer &e{player}&a teleported to mode &e{mode}&a.',
        'command.admin.tp_failed' => '{prefix}&cCould not teleport &e{player}&c to mode &e{mode}&c.',
        'command.admin.tp_unknown_mode' => '{prefix}&cMode &e{mode}&c was not found in the configuration.',
        'command.admin.rp_usage' => '{prefix}&cUsage: /coomlobby rp <player>',
        'command.admin.rp_sent' => '{prefix}&aResource pack sent to &e{player}&a.',
        'command.admin.rp_failed' => '{prefix}&cCould not send the resource pack to &e{player}&c: &f{reason}',
        'command.admin.rp_handshake' => '{prefix}&eThis server sends resource packs during login: &f{player}&e will receive the pack (&f{pack}&e) on the next join.',
        'command.admin.rp_disabled' => '{prefix}&cThe resource pack is disabled or was not loaded.',
        'command.admin.player_not_found' => '{prefix}&cPlayer &e{player}&c is not online.',
        'command.admin.unknown_subcommand' => '{prefix}&cUnknown subcommand. Available: &freload&c, &finfo&c, &ftp&c, &frp&c.',
        'status.version' => 'Version',
        'status.server_version' => 'Server',
        'status.storage' => 'Storage',
        'status.profiles' => 'Player profiles',
        'status.resource_pack' => 'Resource pack',
        'status.default_language' => 'Default language',
        'status.lobby_worlds' => 'Lobby worlds',
        'status.primary_world' => 'Primary world',
        'status.lobby_spawn' => 'Lobby spawn',
        'status.lobby_gamemode' => 'Lobby game mode',
        'status.modes' => 'Active modes',
        'status.modes_online' => 'Online per mode',
        'status.mode_online' => '&f{mode}&7=&a{online}',
        'status.players_online' => 'Players online',
        'status.loaded_worlds' => 'Loaded worlds',
        'status.config_entries' => 'config.yml sections',
        'selector.name' => '&6&lMode Selector',
        'selector.lore' => ['&7Right-click to open the menu'],
        'teleport.success' => '{prefix}&aWelcome to &e{mode}&a!',
        'teleport.cooldown' => '{prefix}&cPlease wait &e{time}s&c before changing modes again.',
        'teleport.mode_disabled' => '{prefix}&cThis mode is currently disabled.',
        'teleport.mode_full' => '{prefix}&cThis mode is full (&e{current}&c/&e{max}&c).',
        'teleport.no_permission' => '{prefix}&cYou do not have permission to join &e{mode}&c.',
        'teleport.world_missing' => '{prefix}&cThe world for mode &e{mode}&c is not available.',
        'protection.block_break' => '{prefix}&cYou cannot break blocks in the lobby.',
        'protection.block_place' => '{prefix}&cYou cannot place blocks in the lobby.',
        'protection.item_drop' => '{prefix}&cYou cannot drop items in the lobby.',
        'protection.item_pickup' => '{prefix}&cYou cannot pick up items in the lobby.',
        'protection.interact_block' => '{prefix}&cYou cannot use that here.',
        'protection.use_item' => '{prefix}&cYou cannot use &e{item}&c in the lobby.',
        'protection.chat' => '{prefix}&cChat is disabled in the lobby.',
        'protection.flight' => '{prefix}&cFlight is disabled in the lobby.',
        'protection.gamemode' => '{prefix}&cYour game mode cannot be changed in the lobby.',
        'protection.projectile' => '{prefix}&cYou cannot use projectiles in the lobby.',
        'lobby.welcome' => '{prefix}&aWelcome to the lobby, &e{player}&a!',
        'lobby.selector_given' => '{prefix}&7Use the &e{mode_selector}&7 to choose a game mode.',
    ];

    private const MODE_STATUS_KEYS = [
        'available' => 'mode.status.available',
        'full' => 'mode.status.full',
        'unavailable' => 'mode.status.unavailable',
        'locked' => 'mode.status.locked',
    ];

    private string $dataFolder;
    private ConfigService $config;
    private PlaceholderService $placeholders;
    private PluginManager $pluginManager;
    private \Logger $logger;

    /** @var array<string, array<string, mixed>> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $selectorNames = [];

    /** @var array<string, string[]> */
    private array $selectorLore = [];

    /** @var \WeakMap<Player, array{code: string, time: float}> */
    private \WeakMap $languageCache;

    /** @var array<string, string> language code => label */
    private array $supportedLanguages = [];

    private bool $coomLanguageProbed = false;
    private ?string $coomLanguageMethod = null;
    private ?bool $coomLanguageAvailable = null;
    private string $defaultLanguage = 'ru';

    public function __construct(
        string $dataFolder,
        ConfigService $config,
        PlaceholderService $placeholders,
        PluginManager $pluginManager,
        \Logger $logger
    ) {
        $this->dataFolder = $dataFolder;
        $this->config = $config;
        $this->placeholders = $placeholders;
        $this->pluginManager = $pluginManager;
        $this->logger = $logger;
        $this->languageCache = new \WeakMap();
    }

    /**
     * Loads the language files from the plugin data folder.
     */
    public function load() : void
    {
        $this->defaultLanguage = $this->normalizeCode($this->config->getLanguageDefault()) ?? ConfigService::DEFAULT_LANGUAGE;
        $this->selectorNames = $this->config->getSelectorNames();
        $this->selectorLore = $this->config->getSelectorLore();
        $this->messages = [];
        $this->supportedLanguages = [];

        foreach ($this->getAvailableLanguageFiles() as $code) {
            $this->messages[$code] = $this->readLanguageFile($code);
            $this->supportedLanguages[$code] = $code;
        }

        if (!isset($this->messages[$this->defaultLanguage])) {
            $this->logger->warning(
                'Language file "' . $this->defaultLanguage . '.yml" was not found in ' . $this->getLanguageFolder()
                . ' - built-in fallback texts are used. Run /coomlobby reload after adding the file.'
            );
        }

        // The * language is used by values that are identical for every language.
        $this->messages['*'] = self::FALLBACK_MESSAGES;
    }

    public function reload() : void
    {
        $this->coomLanguageProbed = false;
        $this->coomLanguageMethod = null;
        $this->coomLanguageAvailable = null;
        $this->languageCache = new \WeakMap();
        $this->load();
    }

    public function getLanguageFolder() : string
    {
        return $this->dataFolder . 'lang/';
    }

    /**
     * @return string[]
     */
    public function getAvailableLanguageFiles() : array
    {
        $folder = $this->getLanguageFolder();
        $languages = [];

        if (is_dir($folder)) {
            foreach (scandir($folder) ?: [] as $entry) {
                if (!str_ends_with(strtolower($entry), '.yml')) {
                    continue;
                }

                $code = $this->normalizeCode(substr($entry, 0, -4));
                if ($code !== null) {
                    $languages[$code] = $code;
                }
            }
        }

        if (!isset($languages[$this->defaultLanguage])) {
            $languages[$this->defaultLanguage] = $this->defaultLanguage;
        }

        return array_values($languages);
    }

    /**
     * @return string[]
     */
    public function getSupportedLanguages() : array
    {
        return array_keys($this->supportedLanguages);
    }

    public function getDefaultLanguage() : string
    {
        return $this->defaultLanguage;
    }

    /**
     * @return array<string, mixed>
     */
    private function readLanguageFile(string $code) : array
    {
        $file = $this->getLanguageFolder() . $code . '.yml';
        if (!file_exists($file)) {
            return [];
        }

        try {
            $config = new Config($file, Config::YAML, []);
            $data = $config->getAll();
        } catch (\Throwable $e) {
            $this->logger->error('Could not read language file ' . $file . ': ' . $e->getMessage());

            return [];
        }

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------
    // Language detection
    // ------------------------------------------------------------------

    /**
     * Returns the two letter language code of a player (never null).
     */
    public function getPlayerLanguage(Player $player) : string
    {
        $now = microtime(true);
        if (isset($this->languageCache[$player])) {
            $cached = $this->languageCache[$player];
            $ttl = $this->config->getLanguageCacheTtl();
            if ($ttl > 0 && ($now - $cached['time']) < $ttl) {
                return $cached['code'];
            }
        }

        $code = $this->resolvePlayerLanguage($player);
        $this->languageCache[$player] = ['code' => $code, 'time' => $now];

        return $code;
    }

    public function getLanguageCode(?Player $player) : string
    {
        return $player === null ? $this->defaultLanguage : $this->getPlayerLanguage($player);
    }

    private function resolvePlayerLanguage(Player $player) : string
    {
        $fromPlugin = $this->resolveCoomLanguageCode($player);
        if ($fromPlugin !== null) {
            return $fromPlugin;
        }

        $locale = '';
        try {
            $locale = (string) $player->getLocale();
        } catch (\Throwable $e) {
            $this->logger->debug('Could not read the client locale: ' . $e->getMessage());
        }

        $code = $this->normalizeCode($locale);
        if ($code !== null && isset($this->supportedLanguages[$code])) {
            return $code;
        }

        return $this->defaultLanguage;
    }

    /**
     * Soft-dependency bridge for the CoomLanguage plugin.
     */
    private function resolveCoomLanguageCode(Player $player) : ?string
    {
        if (!$this->config->isCoomLanguageEnabled()) {
            return null;
        }

        $code = $this->callCoomLanguage($player);
        if ($code === null || !isset($this->supportedLanguages[$code])) {
            return null;
        }

        return $code;
    }

    private function callCoomLanguage(Player $player) : ?string
    {
        if ($this->coomLanguageAvailable === false) {
            return null;
        }

        $plugin = $this->pluginManager->getPlugin('CoomLanguage');
        if (!$plugin instanceof Plugin || !$plugin->isEnabled()) {
            if ($this->coomLanguageAvailable === null) {
                $this->coomLanguageAvailable = false;
                $this->logger->debug('CoomLanguage is not installed - using the client locale for localization.');
            }

            return null;
        }

        $this->coomLanguageAvailable = true;

        if (!$this->coomLanguageProbed) {
            $this->coomLanguageProbed = true;
            foreach ($this->config->getCoomLanguageMethods() as $method) {
                if (method_exists($plugin, $method)) {
                    $this->coomLanguageMethod = $method;
                    break;
                }
            }

            if ($this->coomLanguageMethod === null) {
                $this->logger->warning(
                    'CoomLanguage is installed but none of the configured methods ('
                    . implode(', ', $this->config->getCoomLanguageMethods())
                    . ') exist - falling back to the client locale.'
                );
            }
        }

        if ($this->coomLanguageMethod === null) {
            return null;
        }

        try {
            $value = $plugin->{$this->coomLanguageMethod}($player);
        } catch (\Throwable $e) {
            $this->logger->warning('CoomLanguage::' . $this->coomLanguageMethod . '() failed: ' . $e->getMessage());

            return null;
        }

        return $this->normalizeCode($this->extractLanguageValue($value));
    }

    /**
     * CoomLanguage implementations may return strings, arrays or DTO objects.
     */
    private function extractLanguageValue(mixed $value) : string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value)) {
            foreach (['getCode', 'getLanguageCode', 'getLanguage', 'getLocale', 'getName'] as $method) {
                if (method_exists($value, $method)) {
                    try {
                        $inner = $value->{$method}();
                        if (is_string($inner)) {
                            return $inner;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return '';
        }

        if (is_array($value)) {
            foreach (['code', 'lang', 'language', 'locale', 'name'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return (string) $value[$key];
                }
            }
        }

        return '';
    }

    /**
     * Normalizes "ru_RU", "RU", "ru-RU", 3 => "3", null => null to "ru".
     */
    public function normalizeCode(mixed $value) : ?string
    {
        if (is_bool($value) || $value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['_', '-'], ' ', $raw);
        $parts = explode(' ', $raw);
        $code = $parts[0] ?? '';

        if ($code === '' || strlen($code) > 5) {
            return null;
        }

        for ($i = 0; $i < strlen($code); $i++) {
            if ($code[$i] < 'a' || $code[$i] > 'z') {
                return null;
            }
        }

        return $code;
    }

    // ------------------------------------------------------------------
    // Translation
    // ------------------------------------------------------------------

    /**
     * @param array<string, string|int|float> $placeholders
     */
    public function translate(?Player $player, string $key, array $placeholders = []) : string
    {
        return $this->translateFor($this->getLanguageCode($player), $key, $placeholders, $player);
    }

    /**
     * @param array<string, string|int|float> $placeholders
     */
    public function translateFor(string $language, string $key, array $placeholders = [], ?Player $player = null) : string
    {
        $text = $this->getMessage($language, $key);
        if ($text === null) {
            $text = $this->getMessage($this->defaultLanguage, $key);
        }

        if ($text === null) {
            if (!isset($this->reportedKeys[$key])) {
                $this->reportedKeys[$key] = true;
                $this->logger->debug('Missing language key "' . $key . '".');
            }

            $text = $key;
        }

        $placeholders += ['prefix' => $this->getMessage($language, 'prefix') ?? self::FALLBACK_MESSAGES['prefix']];

        return $this->placeholders->colorize($this->placeholders->apply($text, $player, $placeholders));
    }

    /** @var array<string, true> */
    private array $reportedKeys = [];

    private function getMessage(string $language, string $key) : ?string
    {
        foreach ([$language, $this->defaultLanguage, '*'] as $candidate) {
            if (!isset($this->messages[$candidate])) {
                continue;
            }

            $value = $this->lookupNested($this->messages[$candidate], $key);
            if (is_string($value)) {
                return $value;
            }
        }

        $value = $this->lookupNested(self::FALLBACK_MESSAGES, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * @return string[]
     */
    public function getMessageList(string $language, string $key) : array
    {
        foreach ([$language, $this->defaultLanguage, '*'] as $candidate) {
            if (!isset($this->messages[$candidate])) {
                continue;
            }

            $value = $this->lookupNested($this->messages[$candidate], $key);
            if (is_array($value)) {
                $result = [];
                foreach ($value as $entry) {
                    if (is_string($entry)) {
                        $result[] = $entry;
                    }
                }

                if ($result !== []) {
                    return $result;
                }
            }
        }

        return [];
    }

    public function hasMessage(string $language, string $key) : bool
    {
        return $this->getMessage($language, $key) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function lookupNested(array $data, string $key) : mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    // ------------------------------------------------------------------
    // Item / mode helpers
    // ------------------------------------------------------------------

    public function getSelectorName(?Player $player) : string
    {
        return $this->getSelectorNameFor($this->getLanguageCode($player), $player);
    }

    public function getSelectorNameFor(string $language, ?Player $player = null) : string
    {
        $language = strtolower($language);
        $name = $this->selectorNames[$language] ?? $this->selectorNames['*'] ?? null;
        if ($name === null) {
            $name = $this->getMessage($language, 'selector.name') ?? '&6&lMode Selector';
        }

        return $this->placeholders->colorize($this->placeholders->apply($name, $player));
    }

    /**
     * @return string[]
     */
    public function getSelectorLore(?Player $player) : array
    {
        return $this->getSelectorLoreFor($this->getLanguageCode($player), $player);
    }

    /**
     * @return string[]
     */
    public function getSelectorLoreFor(string $language, ?Player $player = null) : array
    {
        $language = strtolower($language);
        $lore = $this->selectorLore[$language] ?? $this->selectorLore['*'] ?? null;
        if ($lore === null) {
            $lore = $this->getMessageList($language, 'selector.lore');
        }

        if ($lore === []) {
            $lore = ['&7Right-click to choose a game mode'];
        }

        $result = [];
        foreach ($lore as $line) {
            $result[] = $this->placeholders->colorize($this->placeholders->apply($line, $player));
        }

        return $result;
    }

    public function getModeLabel(GameMode $mode, ?Player $player) : string
    {
        return $this->getModeLabelFor($mode, $this->getLanguageCode($player), $player);
    }

    public function getModeLabelFor(GameMode $mode, string $language, ?Player $player = null) : string
    {
        $label = $mode->getName($language);
        if ($label === '') {
            $label = $this->getMessage($language, 'mode.names.' . $mode->getId()) ?? $mode->getId();
        }

        return $this->placeholders->colorize($this->placeholders->apply($label, $player));
    }

    public function getModeDescription(GameMode $mode, ?Player $player) : ?string
    {
        return $this->getModeDescriptionFor($mode, $this->getLanguageCode($player), $player);
    }

    public function getModeDescriptionFor(GameMode $mode, string $language, ?Player $player = null) : ?string
    {
        $description = $mode->getDescription($language);
        if ($description === null || $description === '') {
            $fallback = $this->getMessage($language, 'mode.descriptions.' . $mode->getId());

            return $fallback === null ? null : $this->placeholders->colorize($this->placeholders->apply($fallback, $player));
        }

        return $this->placeholders->colorize($this->placeholders->apply($description, $player));
    }

    public function getModeStatusText(string $status, ?Player $player) : string
    {
        return $this->getModeStatusTextFor($status, $this->getLanguageCode($player), $player);
    }

    public function getModeStatusTextFor(string $status, string $language, ?Player $player = null) : string
    {
        $key = self::MODE_STATUS_KEYS[$status] ?? self::MODE_STATUS_KEYS['unavailable'];

        return $this->translateFor($language, $key, [], $player);
    }

    /**
     * @return string[]
     */
    public function getAdsMessages(string $language) : array
    {
        $configured = $this->config->getAdsMessages();
        $messages = [];

        foreach ([$language, '*', $this->defaultLanguage] as $candidate) {
            if (!isset($configured[$candidate])) {
                continue;
            }

            foreach ($configured[$candidate] as $line) {
                $messages[] = $line;
            }

            break;
        }

        if ($messages === []) {
            $messages = $this->getMessageList($language, 'ads.messages');
        }

        return $messages;
    }

    /**
     * Returns a human readable label for a language code ("ru" => "Русский").
     */
    public function getLanguageLabel(string $code) : string
    {
        $labels = ['ru' => 'Русский', 'en' => 'English'];

        return $labels[$code] ?? ucfirst($code);
    }
}
