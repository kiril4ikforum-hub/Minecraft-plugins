<?php

declare(strict_types=1);

namespace CoomLobby;

use CoomLobby\api\CoomLobbyAPI;
use CoomLobby\api\CoomLobbyAPIImpl;
use CoomLobby\command\AdminCommand;
use CoomLobby\command\LobbyCommand;
use CoomLobby\command\SetLobbyCommand;
use CoomLobby\listener\ChatListener;
use CoomLobby\listener\ConnectionListener;
use CoomLobby\listener\DamageListener;
use CoomLobby\listener\InteractionListener;
use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ProtectionManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\SelectorManager;
use CoomLobby\manager\SpawnManager;
use CoomLobby\manager\TeleportManager;
use CoomLobby\object\LobbyData;
use CoomLobby\service\ConfigService;
use CoomLobby\service\LanguageService;
use CoomLobby\service\PlaceholderService;
use CoomLobby\service\PluginResources;
use CoomLobby\service\StatusReporter;
use CoomLobby\storage\StorageFactory;
use CoomLobby\storage\StorageProvider;
use CoomLobby\task\AdvertisementTask;
use CoomLobby\task\OnlineUpdateTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\DisablePluginException;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use function count;
use function strtolower;

/**
 * CoomLobby - lobby hub for PocketMine-MP 5.x.
 *
 * The entry point only wires the services together: configuration, language,
 * storage, managers, listeners, commands and tasks all live in their own
 * classes (see the "manager", "service", "listener", "task" and "form"
 * namespaces).
 */
final class Main extends PluginBase implements PluginResources
{
    public const PLUGIN_NAME = 'CoomLobby';

    private ConfigService $configService;
    private LanguageService $languageService;
    private PlaceholderService $placeholderService;
    private SpawnManager $spawnManager;
    private ModeManager $modeManager;
    private TeleportManager $teleportManager;
    private SelectorManager $selectorManager;
    private ProtectionManager $protectionManager;
    private ResourceManager $resourceManager;
    private StorageProvider $storage;
    private StatusReporter $statusReporter;
    private CoomLobbyAPI $api;

    private LobbyCommand $lobbyCommand;
    private SetLobbyCommand $setLobbyCommand;
    private AdminCommand $adminCommand;

    private ?TaskHandler $onlineTask = null;
    private ?TaskHandler $adsTask = null;

    // ------------------------------------------------------------------
    // Plugin lifecycle
    // ------------------------------------------------------------------

    protected function onLoad() : void
    {
        // Make sure config.yml exists before any service reads it.
        $this->saveDefaultConfig();
    }

    protected function onEnable() : void
    {
        try {
            $this->boot();
        } catch (\Throwable $e) {
            $this->getLogger()->critical('CoomLobby could not be enabled: ' . $e->getMessage());
            $this->getLogger()->logException($e);

            throw new DisablePluginException('CoomLobby failed to initialize', 0, $e);
        }
    }

    protected function onDisable() : void
    {
        $this->cancelTasks();

        if (isset($this->spawnManager)) {
            try {
                $this->spawnManager->save();
            } catch (\Throwable $e) {
                $this->getLogger()->error('Could not save spawns.yml: ' . $e->getMessage());
            }
        }

        if (isset($this->storage)) {
            try {
                $this->storage->close();
            } catch (\Throwable $e) {
                $this->getLogger()->error('Could not close the player storage: ' . $e->getMessage());
            }
        }

        $this->getLogger()->info('CoomLobby disabled.');
    }

    /**
     * Creates every service in dependency order.
     */
    private function boot() : void
    {
        $logger = $this->getLogger();

        $this->configService = new ConfigService($this->getDataFolder(), $logger);
        $this->configService->load();

        // Language files are shipped as resources; saveResource() never
        // overwrites files that already exist in the data folder.
        $this->saveResource('lang/ru.yml');
        $this->saveResource('lang/en.yml');

        $this->storage = StorageFactory::create($this->configService, $this->getDataFolder(), $logger);
        $this->placeholderService = new PlaceholderService($this->configService, $this->getServer(), $logger);
        $this->languageService = new LanguageService(
            $this->getDataFolder(),
            $this->configService,
            $this->placeholderService,
            $this->getServer()->getPluginManager(),
            $logger
        );
        $this->languageService->load();

        $this->spawnManager = new SpawnManager($this->configService, $this->getServer(), $logger);
        $this->modeManager = new ModeManager($this->configService, $this->getServer(), $logger);
        $this->selectorManager = new SelectorManager(
            $this->configService,
            $this->languageService,
            $this->placeholderService,
            $logger
        );

        $lobbyData = LobbyData::fromConfig($this->configService, $this->spawnManager->getLobbySpawn());

        $this->protectionManager = new ProtectionManager(
            $this->configService,
            $lobbyData,
            $this->selectorManager,
            $this->languageService,
            $logger
        );

        $this->teleportManager = new TeleportManager(
            $this->configService,
            $this->getServer(),
            $this->modeManager,
            $this->spawnManager,
            $this->selectorManager,
            $this->languageService,
            $this->storage,
            $logger
        );

        $this->resourceManager = new ResourceManager($this, $this->configService, $logger);
        $this->resourceManager->initialize();

        $this->statusReporter = new StatusReporter(
            $this->configService,
            $this->languageService,
            $this->modeManager,
            $this->resourceManager,
            $this->protectionManager,
            $this->spawnManager,
            $this->storage,
            $this->getServer(),
            $this->getDescription()->getVersion()
        );

        $this->api = new CoomLobbyAPIImpl(
            $this->protectionManager,
            $this->selectorManager,
            $this->teleportManager,
            $this->modeManager,
            $this->languageService
        );

        $this->lobbyCommand = new LobbyCommand($this->teleportManager, $this->languageService);
        $this->setLobbyCommand = new SetLobbyCommand(
            $this->spawnManager,
            $this->protectionManager,
            $this->languageService,
            $this->getServer()
        );
        $this->adminCommand = new AdminCommand(
            fn () : bool => $this->reloadAll(),
            $this->statusReporter,
            $this->modeManager,
            $this->teleportManager,
            $this->resourceManager,
            $this->languageService,
            $this->getServer()
        );

        $this->registerListeners();
        $this->registerTasks();

        $logger->info(
            'Enabled with ' . count($this->modeManager->getActiveModes()) . ' active mode(s), '
            . count($lobbyData->getWorlds()) . ' lobby world(s), storage "' . $this->storage->getType() . '".'
        );
    }

    private function registerListeners() : void
    {
        $manager = $this->getServer()->getPluginManager();
        $logger = $this->getLogger();

        $manager->registerEvents(new ConnectionListener(
            $this->configService,
            $this->languageService,
            $this->protectionManager,
            $this->selectorManager,
            $this->modeManager,
            $this->spawnManager,
            $this->resourceManager,
            $this->storage,
            $this->getScheduler(),
            $logger
        ), $this);

        $manager->registerEvents(new InteractionListener(
            $this->configService,
            $this->protectionManager,
            $this->selectorManager,
            $this->languageService,
            $this->modeManager,
            $this->teleportManager,
            $this->resourceManager,
            $logger
        ), $this);

        $manager->registerEvents(new DamageListener($this->protectionManager), $this);
        $manager->registerEvents(new ChatListener($this->protectionManager), $this);
    }

    private function registerTasks() : void
    {
        $this->onlineTask = $this->getScheduler()->scheduleRepeatingTask(
            new OnlineUpdateTask($this->modeManager),
            $this->configService->getOnlineRefreshPeriodTicks()
        );

        if ($this->configService->isAdsEnabled()) {
            $this->adsTask = $this->getScheduler()->scheduleRepeatingTask(
                new AdvertisementTask(
                    $this->configService,
                    $this->languageService,
                    $this->placeholderService,
                    $this->protectionManager,
                    $this->getServer()
                ),
                $this->configService->getAdsIntervalSeconds() * 20
            );
        }
    }

    private function cancelTasks() : void
    {
        if ($this->onlineTask !== null) {
            $this->onlineTask->cancel();
            $this->onlineTask = null;
        }

        if ($this->adsTask !== null) {
            $this->adsTask->cancel();
            $this->adsTask = null;
        }
    }

    /**
     * Reloads configuration, language files, modes, manager state, the
     * resource pack and every task. Returns false when something went wrong
     * (the previous state keeps working in that case).
     */
    public function reloadAll() : bool
    {
        try {
            $this->cancelTasks();

            $this->configService->reload();
            $this->languageService->reload();
            $this->spawnManager->read();
            $this->modeManager->loadModes();
            $this->selectorManager->reload();

            $lobbyData = LobbyData::fromConfig($this->configService, $this->spawnManager->getLobbySpawn());
            $this->protectionManager->reload($lobbyData);
            $this->resourceManager->initialize();

            $this->registerTasks();

            return true;
        } catch (\Throwable $e) {
            $this->getLogger()->error('Reload failed: ' . $e->getMessage());
            $this->getLogger()->logException($e);

            // Never leave the plugin without its maintenance tasks.
            try {
                $this->registerTasks();
            } catch (\Throwable $inner) {
                $this->getLogger()->error('Could not restart the tasks: ' . $inner->getMessage());
            }

            return false;
        }
    }

    // ------------------------------------------------------------------
    // Commands
    // ------------------------------------------------------------------

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
    {
        return match (strtolower($command->getName())) {
            'lobby' => $this->lobbyCommand->onCommand($sender, $command, $label, $args),
            'setlobby' => $this->setLobbyCommand->onCommand($sender, $command, $label, $args),
            'coomlobby' => $this->adminCommand->onCommand($sender, $command, $label, $args),
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // Bundled resources (PluginResources, see ResourceManager)
    // ------------------------------------------------------------------

    public function getPluginVersion() : string
    {
        return $this->getDescription()->getVersion();
    }

    /**
     * @return array<string, \SplFileInfo>
     */
    public function getBundledResources() : array
    {
        return $this->getResources();
    }

    /**
     * @return resource|null
     */
    public function openResource(string $relativePath)
    {
        return $this->getResource($relativePath);
    }

    // ------------------------------------------------------------------
    // Accessors (also used by the API implementation)
    // ------------------------------------------------------------------

    public function getAPI() : CoomLobbyAPI
    {
        return $this->api;
    }

    public function getConfigService() : ConfigService
    {
        return $this->configService;
    }

    public function getLanguageService() : LanguageService
    {
        return $this->languageService;
    }

    public function getPlaceholderService() : PlaceholderService
    {
        return $this->placeholderService;
    }

    public function getModeManager() : ModeManager
    {
        return $this->modeManager;
    }

    public function getProtectionManager() : ProtectionManager
    {
        return $this->protectionManager;
    }

    public function getResourceManager() : ResourceManager
    {
        return $this->resourceManager;
    }

    public function getSelectorManager() : SelectorManager
    {
        return $this->selectorManager;
    }

    public function getSpawnManager() : SpawnManager
    {
        return $this->spawnManager;
    }

    public function getTeleportManager() : TeleportManager
    {
        return $this->teleportManager;
    }

    public function getStorage() : StorageProvider
    {
        return $this->storage;
    }

    public function getStatusReporter() : StatusReporter
    {
        return $this->statusReporter;
    }
}
