<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use CoomLobby\service\PlaceholderService;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use function in_array;
use function implode;
use function md5;
use function strtolower;
use function trim;

/**
 * Creates, identifies, gives and removes the mode selector item.
 *
 * Identification uses an NBT tag ("CoomLobby_Item" = "selector") so that a
 * renamed or re-lored item is still recognized. If the NBT tag got lost (for
 * example after an inventory sync problem) the item is matched by the
 * documented fallback: item type + custom name + lore hash.
 */
class SelectorManager
{
    public const NBT_TAG = 'CoomLobby_Item';
    public const NBT_VALUE = 'selector';

    private ConfigService $config;
    private LanguageService $language;
    private PlaceholderService $placeholders;
    private \Logger $logger;

    private ?int $baseItemTypeId = null;

    public function __construct(
        ConfigService $config,
        LanguageService $language,
        PlaceholderService $placeholders,
        \Logger $logger
    ) {
        $this->config = $config;
        $this->language = $language;
        $this->placeholders = $placeholders;
        $this->logger = $logger;
    }

    /**
     * Called after a configuration reload.
     */
    public function reload() : void
    {
        $this->baseItemTypeId = null;
    }

    public function getSlot() : int
    {
        return $this->config->getSelectorSlot();
    }

    /**
     * Resolves the configured base item, falling back to the vanilla compass.
     */
    public function resolveBaseItem() : Item
    {
        $identifier = $this->config->getSelectorItemId();

        try {
            $item = StringToItemParser::getInstance()->parse($identifier);
        } catch (\Throwable $e) {
            $item = null;
            $this->logger->warning('Could not parse the selector item "' . $identifier . '": ' . $e->getMessage());
        }

        if ($item === null) {
            if (strtolower($identifier) !== 'minecraft:compass') {
                $this->logger->warning('Unknown selector item "' . $identifier . '" - using minecraft:compass instead.');
            }

            $item = VanillaItems::COMPASS();
        }

        return $item->setCount(1);
    }

    /**
     * Builds a ready to use selector item localized for the given language.
     */
    public function createItem(string $language) : Item
    {
        return $this->buildItem($this->language->getSelectorNameFor($language), $this->language->getSelectorLoreFor($language), $language);
    }

    public function createItemForPlayer(Player $player) : Item
    {
        return $this->buildItem(
            $this->language->getSelectorName($player),
            $this->language->getSelectorLore($player),
            $this->language->getPlayerLanguage($player)
        );
    }

    /**
     * @param string[] $lore
     */
    private function buildItem(string $name, array $lore, string $language) : Item
    {
        $item = $this->resolveBaseItem();
        $item->setCustomName($name);

        if ($lore !== []) {
            $item->setLore($lore);
        }

        // Custom NBT marker used for fast identification.
        $tag = $item->getNamedTag();
        $tag->setString(self::NBT_TAG, self::NBT_VALUE);
        $tag->setString(self::NBT_TAG . '_lang', strtolower($language));
        $item->setNamedTag($tag);

        return $item;
    }

    /**
     * True when the given item is the CoomLobby mode selector.
     */
    public function isSelector(Item $item) : bool
    {
        if ($item->isNull()) {
            return false;
        }

        try {
            if ($this->hasSelectorTag($item)) {
                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Could not read the selector NBT tag: ' . $e->getMessage());
        }

        return $this->matchesFallbackSignature($item);
    }

    private function hasSelectorTag(Item $item) : bool
    {
        return $item->getNamedTag()->getString(self::NBT_TAG, '') === self::NBT_VALUE;
    }

    /**
     * Fallback identification: same item type plus a matching name/lore hash.
     */
    private function matchesFallbackSignature(Item $item) : bool
    {
        if ($item->getTypeId() !== $this->getBaseItemTypeId()) {
            return false;
        }

        $signature = $this->createSignature($item);

        foreach ($this->getSignatureLanguages() as $language) {
            if ($signature === $this->expectedSignature($language)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hash of the item appearance, used by the NBT-less fallback check.
     */
    public function createSignature(Item $item) : string
    {
        return md5($item->getTypeId() . '|' . $item->getCustomName() . '|' . implode("\n", $item->getLore()));
    }

    /**
     * Expected signature of a selector item in the given language.
     */
    public function expectedSignature(string $language) : string
    {
        return md5(
            $this->getBaseItemTypeId() . '|'
            . $this->language->getSelectorNameFor($language) . '|'
            . implode("\n", $this->language->getSelectorLoreFor($language))
        );
    }

    /**
     * @return string[]
     */
    private function getSignatureLanguages() : array
    {
        $languages = $this->language->getSupportedLanguages();
        $default = $this->language->getDefaultLanguage();
        if (!in_array($default, $languages, true)) {
            $languages[] = $default;
        }

        return $languages;
    }

    private function getBaseItemTypeId() : int
    {
        if ($this->baseItemTypeId === null) {
            try {
                $this->baseItemTypeId = $this->resolveBaseItem()->getTypeId();
            } catch (\Throwable $e) {
                $this->logger->warning('Could not resolve the selector base item: ' . $e->getMessage());
                $this->baseItemTypeId = 0;
            }
        }

        return $this->baseItemTypeId;
    }

    // ------------------------------------------------------------------
    // Inventory helpers
    // ------------------------------------------------------------------

    /**
     * Removes every selector from the player inventory and places a fresh one
     * into the configured slot.
     */
    public function give(Player $player, bool $selectHotbarSlot = false) : bool
    {
        if (!$player->isOnline()) {
            return false;
        }

        try {
            $inventory = $player->getInventory();
            $this->removeFrom($inventory);

            $slot = $this->getSlot();
            $inventory->setItem($slot, $this->createItemForPlayer($player));

            if ($selectHotbarSlot && $inventory->isHotbarSlot($slot)) {
                $inventory->setHeldItemIndex($slot);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Could not give the selector to ' . $player->getName() . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Removes every selector item from the player inventory.
     */
    public function remove(Player $player) : bool
    {
        if (!$player->isOnline()) {
            return false;
        }

        try {
            return $this->removeFrom($player->getInventory()) > 0;
        } catch (\Throwable $e) {
            $this->logger->error('Could not remove the selector from ' . $player->getName() . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return int number of removed items
     */
    public function removeFrom(PlayerInventory $inventory) : int
    {
        $removed = 0;
        $size = $inventory->getSize();

        for ($slot = 0; $slot < $size; $slot++) {
            $item = $inventory->getItem($slot);
            if ($item->isNull()) {
                continue;
            }

            if ($this->isSelector($item)) {
                $inventory->setItem($slot, VanillaItems::AIR());
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Counts the selectors inside the inventory (used by /coomlobby info).
     */
    public function countInInventory(Player $player) : int
    {
        $count = 0;
        $inventory = $player->getInventory();

        for ($slot = 0; $slot < $inventory->getSize(); $slot++) {
            $item = $inventory->getItem($slot);
            if (!$item->isNull() && $this->isSelector($item)) {
                $count++;
            }
        }

        return $count;
    }

    public function getDisplayName() : string
    {
        return trim($this->language->getSelectorName(null));
    }
}
