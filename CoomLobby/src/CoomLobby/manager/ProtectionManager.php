<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\object\LobbyData;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_key_exists;
use function microtime;
use function strtolower;

/**
 * Single point of truth for the question "may this player do X here?".
 *
 * Every lookup is O(1): the lobby world names live in a hash map inside
 * LobbyData, item lists are resolved once and cached as type ID maps, and the
 * warning anti-spam uses a WeakMap keyed by the player object (no manual
 * cleanup on quit required).
 */
class ProtectionManager
{
    private const PERMISSION_BYPASS = 'coomlobby.bypass';
    private const PERMISSION_CHAT = 'coomlobby.chat';

    private ConfigService $config;
    private LobbyData $lobby;
    private SelectorManager $selector;
    private LanguageService $language;
    private \Logger $logger;

    /** @var \WeakMap<Player, float> */
    private \WeakMap $warningCooldowns;

    /** @var array<int, true>|null */
    private ?array $allowedItemIds = null;

    /** @var array<int, true>|null */
    private ?array $blockedItemIds = null;

    public function __construct(
        ConfigService $config,
        LobbyData $lobby,
        SelectorManager $selector,
        LanguageService $language,
        \Logger $logger
    ) {
        $this->config = $config;
        $this->lobby = $lobby;
        $this->selector = $selector;
        $this->language = $language;
        $this->logger = $logger;
        $this->warningCooldowns = new \WeakMap();
    }

    /**
     * Applies a new lobby snapshot after /coomlobby reload.
     */
    public function reload(LobbyData $lobby) : void
    {
        $this->lobby = $lobby;
        $this->allowedItemIds = null;
        $this->blockedItemIds = null;
    }

    public function getLobbyData() : LobbyData
    {
        return $this->lobby;
    }

    // ------------------------------------------------------------------
    // Lobby detection
    // ------------------------------------------------------------------

    public function isLobbyWorld(World $world) : bool
    {
        return $this->lobby->isLobbyWorld($world);
    }

    public function isLobbyWorldName(string $folderName) : bool
    {
        return $this->lobby->isLobbyWorldName($folderName);
    }

    public function isInLobby(Player $player) : bool
    {
        $world = $player->getWorld();

        return $this->lobby->isLobbyWorld($world);
    }

    // ------------------------------------------------------------------
    // Permission helpers
    // ------------------------------------------------------------------

    public function canBypass(Player $player) : bool
    {
        if (!$this->lobby->isHonorBypass()) {
            return false;
        }

        return $player->hasPermission(self::PERMISSION_BYPASS);
    }

    /**
     * True when the lobby rules apply to this player right now.
     */
    public function isProtected(Player $player) : bool
    {
        return $this->isInLobby($player) && !$this->canBypass($player);
    }

    /**
     * Combines the lobby check with the per-feature toggle from config.yml.
     */
    public function shouldProtect(Player $player, string $flag) : bool
    {
        return $this->isProtected($player) && $this->config->isProtectionEnabled($flag);
    }

    public function canChat(Player $player) : bool
    {
        if (!$this->shouldProtect($player, 'chat')) {
            return true;
        }

        return $player->hasPermission(self::PERMISSION_CHAT);
    }

    public function canFly(Player $player) : bool
    {
        return !$this->shouldProtect($player, 'flight');
    }

    // ------------------------------------------------------------------
    // Item rules
    // ------------------------------------------------------------------

    public function canUseItem(Player $player, Item $item) : bool
    {
        if ($item->isNull()) {
            return true;
        }

        if (!$this->shouldProtect($player, 'use-items')) {
            return true;
        }

        if ($this->selector->isSelector($item)) {
            return true;
        }

        $this->ensureItemLists();
        $typeId = $item->getTypeId();

        if ($this->blockedItemIds !== null && array_key_exists($typeId, $this->blockedItemIds)) {
            return false;
        }

        // A non empty whitelist switches the lobby into "selector only" mode:
        // any item that is not whitelisted is rejected.
        if ($this->allowedItemIds !== null && $this->allowedItemIds !== [] && !array_key_exists($typeId, $this->allowedItemIds)) {
            return false;
        }

        return true;
    }

    public function isBlockedItem(Item $item) : bool
    {
        $this->ensureItemLists();

        return $this->blockedItemIds !== null && array_key_exists($item->getTypeId(), $this->blockedItemIds);
    }

    private function ensureItemLists() : void
    {
        if ($this->allowedItemIds !== null && $this->blockedItemIds !== null) {
            return;
        }

        $this->allowedItemIds = $this->parseItemIds($this->config->getAllowedItems());
        $this->blockedItemIds = $this->parseItemIds($this->config->getBlockedItems());
    }

    /**
     * @param string[] $identifiers
     * @return array<int, true>
     */
    private function parseItemIds(array $identifiers) : array
    {
        $result = [];

        foreach ($identifiers as $identifier) {
            try {
                $item = StringToItemParser::getInstance()->parse($identifier);
            } catch (\Throwable $e) {
                $item = null;
            }

            if ($item === null) {
                $this->logger->warning('Unknown item identifier "' . $identifier . '" in the protection lists - ignored.');

                continue;
            }

            $result[$item->getTypeId()] = true;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Warning anti-spam
    // ------------------------------------------------------------------

    /**
     * Sends a localized warning, at most once every
     * "protection.warning-cooldown" seconds per player.
     *
     * @param array<string, string|int|float> $placeholders
     * @return bool whether the message was actually sent
     */
    public function warn(Player $player, string $messageKey, array $placeholders = []) : bool
    {
        if ($this->getRemainingWarningCooldown($player) > 0) {
            return false;
        }

        $this->warningCooldowns[$player] = microtime(true);
        $player->sendMessage($this->language->translate($player, $messageKey, $placeholders));

        return true;
    }

    public function getRemainingWarningCooldown(Player $player) : int
    {
        $cooldown = $this->config->getWarningCooldown();
        if ($cooldown <= 0) {
            return 0;
        }

        $last = $this->warningCooldowns[$player] ?? null;
        if ($last === null) {
            return 0;
        }

        $remaining = (int) ceil($cooldown - (microtime(true) - $last));

        return $remaining > 0 ? $remaining : 0;
    }

    public function clearWarningCooldown(Player $player) : void
    {
        unset($this->warningCooldowns[$player]);
    }

    /**
     * Normalizes a world name for comparisons (lower-case, trimmed).
     */
    public function normalizeWorldName(string $worldName) : string
    {
        return strtolower($worldName);
    }
}
