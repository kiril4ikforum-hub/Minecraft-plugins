<?php

declare(strict_types=1);

namespace CoomLobby\listener;

use CoomLobby\form\ModeSelectionForm;
use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\SelectorManager;
use CoomLobby\manager\TeleportManager;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function microtime;

/**
 * Lobby interaction rules: the selector opens the mode menu, everything else
 * that could change the world (blocks, items, projectiles, flight, game mode)
 * is rejected for protected players.
 */
final class InteractionListener implements Listener
{
    private ConfigService $config;
    private ProtectionManager $protection;
    private SelectorManager $selector;
    private LanguageService $language;
    private ModeManager $modes;
    private TeleportManager $teleports;
    private ResourceManager $resources;
    private \Logger $logger;

    /** @var \WeakMap<Player, float> */
    private \WeakMap $formCooldowns;

    public function __construct(
        ConfigService $config,
        ProtectionManager $protection,
        SelectorManager $selector,
        LanguageService $language,
        ModeManager $modes,
        TeleportManager $teleports,
        ResourceManager $resources,
        \Logger $logger
    ) {
        $this->config = $config;
        $this->protection = $protection;
        $this->selector = $selector;
        $this->language = $language;
        $this->modes = $modes;
        $this->teleports = $teleports;
        $this->resources = $resources;
        $this->logger = $logger;
        $this->formCooldowns = new \WeakMap();
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    public function onBlockBreak(BlockBreakEvent $event) : void
    {
        $player = $event->getPlayer();

        if ($this->protection->shouldProtect($player, 'block-break')) {
            $event->cancel();
            $this->protection->warn($player, 'protection.block_break');
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event) : void
    {
        $player = $event->getPlayer();

        if ($this->protection->shouldProtect($player, 'block-place')) {
            $event->cancel();
            $this->protection->warn($player, 'protection.block_place');
        }
    }

    // ------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------

    public function onDropItem(PlayerDropItemEvent $event) : void
    {
        $player = $event->getPlayer();
        $item = $event->getItem();

        // The selector may never be dropped: it is the only way back into the
        // mode menu and duplicating it must be impossible.
        if ($this->selector->isSelector($item)) {
            $event->cancel();

            return;
        }

        if ($this->protection->shouldProtect($player, 'item-drop')) {
            $event->cancel();
            $this->protection->warn($player, 'protection.item_drop');
        }
    }

    public function onPickupItem(EntityItemPickupEvent $event) : void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }

        if (!$this->protection->shouldProtect($entity, 'item-pickup')) {
            return;
        }

        $event->cancel();
        $this->protection->warn($entity, 'protection.item_pickup');
    }

    /**
     * Right click on a block: the selector opens the menu, everything else is
     * blocked inside the lobby.
     */
    public function onInteract(PlayerInteractEvent $event) : void
    {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if ($this->selector->isSelector($item)) {
            $event->cancel();
            $this->openSelector($player);

            return;
        }

        if (!$this->protection->shouldProtect($player, 'interact-blocks')) {
            return;
        }

        // setUseItem/setUseBlock are the PocketMine-MP 5 way of stopping the
        // interaction without hiding the swing animation from other plugins.
        $event->setUseBlock(false);
        $event->setUseItem(false);
        $event->cancel();

        $this->protection->warn($player, 'protection.interact_block');
    }

    /**
     * Right click with an item (air or block): the selector opens the menu and
     * blacklisted items are rejected.
     */
    public function onItemUse(PlayerItemUseEvent $event) : void
    {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if ($this->selector->isSelector($item)) {
            $event->cancel();
            $this->openSelector($player);

            return;
        }

        if ($this->protection->canUseItem($player, $item)) {
            return;
        }

        $event->cancel();
        $this->protection->warn($player, 'protection.use_item', ['item' => $item->getVanillaName()]);
    }

    public function onProjectileLaunch(ProjectileLaunchEvent $event) : void
    {
        $projectile = $event->getEntity();
        if (!$projectile instanceof Projectile) {
            return;
        }

        $shooter = $projectile->getOwningEntity();
        if (!$shooter instanceof Player) {
            return;
        }

        if (!$this->protection->shouldProtect($shooter, 'projectiles')) {
            return;
        }

        $event->cancel();
        $this->protection->warn($shooter, 'protection.projectile');
    }

    // ------------------------------------------------------------------
    // Player state
    // ------------------------------------------------------------------

    public function onToggleFlight(PlayerToggleFlightEvent $event) : void
    {
        $player = $event->getPlayer();

        if (!$event->isFlying() || $this->protection->canFly($player)) {
            return;
        }

        $event->cancel();
        $this->protection->warn($player, 'protection.flight');
    }

    public function onGameModeChange(PlayerGameModeChangeEvent $event) : void
    {
        $player = $event->getPlayer();

        if (!$this->protection->shouldProtect($player, 'force-gamemode')) {
            return;
        }

        // The lobby game mode itself is enforced by the plugin (and therefore
        // allowed); switching to anything else is not.
        if ($event->getNewGamemode() === $this->protection->getLobbyData()->getGamemode()) {
            return;
        }

        $event->cancel();
        $this->protection->warn($player, 'protection.gamemode');
    }

    // ------------------------------------------------------------------
    // Form handling
    // ------------------------------------------------------------------

    /**
     * Opens the mode selection form, respecting the anti-spam cooldown.
     */
    public function openSelector(Player $player) : bool
    {
        if (!$player->hasPermission('coomlobby.use')) {
            return false;
        }

        $now = microtime(true) * 1000;
        $cooldown = $this->config->getSelectorCooldownMs();
        $last = $this->formCooldowns[$player] ?? null;

        if ($last !== null && $cooldown > 0 && ($now - $last) < $cooldown) {
            return false;
        }

        $this->formCooldowns[$player] = $now;

        $form = ModeSelectionForm::createFor($player, $this->language, $this->modes, $this->teleports, $this->resources);

        if (!$form->sendTo($player)) {
            $this->logger->error('Could not send the mode selection form to ' . $player->getName() . '.');

            return false;
        }

        return true;
    }

    /**
     * Describes an item for warning messages.
     */
    private function describeItem(Item $item) : string
    {
        return $item->getVanillaName();
    }
}
