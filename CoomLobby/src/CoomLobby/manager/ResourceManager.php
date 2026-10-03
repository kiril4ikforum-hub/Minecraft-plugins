<?php

declare(strict_types=1);

namespace CoomLobby\manager;

use CoomLobby\service\ConfigService;
use CoomLobby\service\PluginResources;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use pocketmine\player\Player;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use function class_exists;
use function count;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function mkdir;
use function preg_match;
use function rename;
use function str_ends_with;
use function str_starts_with;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function trim;
use function unlink;

/**
 * Builds, validates and delivers the CoomLobby resource pack.
 *
 * Lifecycle:
 *   1. the shipped sources (resources/rp/...) are extracted into
 *      plugin_data/CoomLobby/rp/ whenever the plugin version changes
 *   2. the folder is packed into a .mcpack file (ZipArchive)
 *   3. the archive is validated (file size, manifest.json, UUID) before it is
 *      handed to PocketMine's ZippedResourcePack
 *   4. the pack is offered to every connecting player, and - on server
 *      versions that still expose Player::addResourcePack() - pushed on join
 *
 * Any failure disables the visual extras only: the plugin keeps working with
 * vanilla textures and text-only buttons, and exactly one warning is logged.
 */
class ResourceManager
{
    private const MANIFEST_FILE = 'manifest.json';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const TEXTURE_EXTENSIONS = ['.png', '.tga', '.jpg', '.jpeg'];

    private PluginResources $plugin;
    private ConfigService $config;
    private \Logger $logger;

    private ?ResourcePack $pack = null;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @var \WeakMap<Player, true> */
    private \WeakMap $delivered;

    private ?string $lastError = null;
    private bool $reportedError = false;

    public function __construct(PluginResources $plugin, ConfigService $config, \Logger $logger)
    {
        $this->plugin = $plugin;
        $this->config = $config;
        $this->logger = $logger;
        $this->delivered = new \WeakMap();
    }

    /**
     * Prepares the pack. Safe to call again after a configuration reload.
     */
    public function initialize() : void
    {
        $this->pack = null;
        $this->info = [];
        $this->lastError = null;
        $this->reportedError = false;
        $this->delivered = new \WeakMap();

        if (!$this->config->isResourcePackEnabled()) {
            $this->logger->info('Resource pack delivery is disabled in config.yml.');

            return;
        }

        $sourceFolder = $this->getSourceFolder();
        $this->syncSources($sourceFolder);

        $packPath = $this->getPackPath();
        if ($this->config->isResourcePackBuild() && ($this->needsRebuild($packPath) || $this->config->isResourcePackRebuildAlways())) {
            $this->buildPack($sourceFolder, $packPath);
        }

        if (!is_file($packPath)) {
            $this->fail('Resource pack file "' . $packPath . '" was not found. Build it with "/coomlobby reload" after adding the sources.');

            return;
        }

        $manifest = $this->validatePack($packPath);
        if ($manifest === null) {
            return;
        }

        try {
            $pack = new ZippedResourcePack($packPath);
        } catch (\Throwable $e) {
            $this->fail('PocketMine could not load the resource pack: ' . $e->getMessage());

            return;
        }

        $this->pack = $pack;
        $this->info = [
            'uuid' => $pack->getPackId(),
            'version' => $pack->getPackVersion(),
            'size' => $pack->getPackSize(),
            'sha256' => $pack->getSha256(),
            'path' => $packPath,
            'manifest-version' => implode('.', $manifest['version']),
            'modules' => $manifest['modules'],
        ];

        $this->logger->info(
            'Resource pack ready: ' . $pack->getPackName() . ' v' . $pack->getPackVersion()
            . ' (uuid ' . $pack->getPackId() . ', ' . $pack->getPackSize() . ' bytes)'
        );
    }

    public function isActive() : bool
    {
        return $this->pack !== null;
    }

    public function getResourcePack() : ?ResourcePack
    {
        return $this->pack;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInfo() : array
    {
        return $this->info;
    }

    public function getLastError() : ?string
    {
        return $this->lastError;
    }

    public function getSourceFolder() : string
    {
        return $this->config->getDataFolder() . $this->config->getResourcePackFolder();
    }

    public function getPackPath() : string
    {
        $file = $this->config->getResourcePackFile();
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1) {
            return $file;
        }

        return $this->config->getDataFolder() . $file;
    }

    /**
     * True when the server version delegates resource pack delivery to
     * PlayerResourcePackOfferEvent (PocketMine-MP 5.14 and newer).
     */
    public function hasOfferEventSupport() : bool
    {
        return class_exists(PlayerResourcePackOfferEvent::class);
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /**
     * Adds the pack to the offer sent to a connecting player.
     */
    public function offerTo(PlayerResourcePackOfferEvent $event) : void
    {
        if ($this->pack === null) {
            return;
        }

        foreach ($event->getResourcePacks() as $existing) {
            if (strtolower($existing->getPackId()) === strtolower($this->pack->getPackId())) {
                // The pack is already part of the offer (e.g. added manually to
                // the server resource pack stack) - never send it twice.
                return;
            }
        }

        try {
            $event->addResourcePack($this->pack, null);
        } catch (\Throwable $e) {
            $this->logger->error('Could not add the resource pack to the player offer: ' . $e->getMessage());

            return;
        }

        if ($this->config->isResourcePackForceAccept()) {
            $event->setMustAccept(true);
        }
    }

    /**
     * Pushes the pack to a player that is already online.
     *
     * On PocketMine-MP 5.14+ the client only accepts the pack list during the
     * login handshake, so a live re-send is not possible; the caller is told
     * about it through the return value and the reason string.
     */
    public function sendTo(Player $player, ?string &$reason = null) : bool
    {
        if ($this->pack === null) {
            $reason = $this->lastError ?? 'the resource pack is disabled';

            return false;
        }

        if (isset($this->delivered[$player])) {
            $reason = 'the resource pack was already sent to this player';

            return false;
        }

        if (!method_exists($player, 'addResourcePack')) {
            $reason = 'this server version delivers resource packs during the login handshake only '
                . '(the pack is offered again the next time the player joins)';

            return false;
        }

        try {
            $player->addResourcePack(
                $this->pack->getPackId(),
                $this->pack->getPackVersion(),
                $this->pack->getSha256(),
                '',
                $this->pack->getPackSize(),
                $this->config->isResourcePackForceAccept()
            );
        } catch (\Throwable $e) {
            $reason = $e->getMessage();

            return false;
        }

        $this->delivered[$player] = true;

        return true;
    }

    /**
     * Marks a player as already served (used by the join handler).
     */
    public function markDelivered(Player $player) : void
    {
        $this->delivered[$player] = true;
    }

    /**
     * Rebuilds the .mcpack from the extracted sources.
     */
    public function rebuild() : bool
    {
        $source = $this->getSourceFolder();
        $this->syncSources($source, true);

        $result = $this->buildPack($source, $this->getPackPath());
        if ($result) {
            $this->initialize();
        }

        return $result;
    }

    /**
     * Checks whether one of the shipped textures exists (used by the form to
     * decide between an image button and a text button).
     */
    public function hasTexture(string $texturePath) : bool
    {
        $texturePath = trim($texturePath);
        if ($texturePath === '') {
            return false;
        }

        if (str_contains($texturePath, '://')) {
            // Web images are always considered available.
            return true;
        }

        foreach (self::TEXTURE_EXTENSIONS as $extension) {
            if (str_ends_with(strtolower($texturePath), $extension)) {
                $texturePath = substr($texturePath, 0, -strlen($extension));
                break;
            }
        }

        // The extracted sources are authoritative: when the file is missing
        // there, the client would not find the texture inside the pack either.
        $folder = $this->getSourceFolder();
        foreach (self::TEXTURE_EXTENSIONS as $extension) {
            if (is_file($folder . '/' . $texturePath . $extension)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /**
     * Extracts resources/rp/** into the plugin data folder.
     */
    private function syncSources(string $sourceFolder, bool $force = false) : void
    {
        $marker = $this->config->getDataFolder() . 'rp/.sources';
        $version = $this->plugin->getPluginVersion();

        $needsCopy = $force;
        if (!$needsCopy) {
            $current = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
            $needsCopy = $current !== $version;
        }

        if (!$needsCopy) {
            return;
        }

        $copied = 0;
        $failed = 0;

        try {
            $resources = $this->plugin->getBundledResources();
        } catch (\Throwable $e) {
            $this->fail('Could not read the bundled resource pack files: ' . $e->getMessage());

            return;
        }

        foreach ($resources as $path => $file) {
            if (!is_string($path) || !str_starts_with($path, 'rp/')) {
                continue;
            }

            $destination = $this->config->getDataFolder() . $path;
            if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
                $failed++;
                continue;
            }

            try {
                $stream = $this->plugin->openResource($path);
                if ($stream === null) {
                    $failed++;
                    continue;
                }

                $contents = stream_get_contents($stream);
                fclose($stream);

                if ($contents === false || file_put_contents($destination, $contents) === false) {
                    $failed++;
                    continue;
                }

                $copied++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        if (!is_dir(dirname($marker))) {
            @mkdir(dirname($marker), 0755, true);
        }
        @file_put_contents($marker, $version);

        if ($failed > 0) {
            $this->logger->warning('Resource pack sources: ' . $copied . ' file(s) extracted, ' . $failed . ' failed.');
        }
    }

    private function needsRebuild(string $packPath) : bool
    {
        if (!is_file($packPath)) {
            return true;
        }

        $packTime = filemtime($packPath);
        if ($packTime === false) {
            return true;
        }

        $sourceFolder = $this->getSourceFolder();
        if (!is_dir($sourceFolder)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceFolder, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $time = $file->getMTime();
            if ($time > $packTime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Packs the source folder into a .mcpack archive.
     */
    private function buildPack(string $sourceFolder, string $packPath) : bool
    {
        if (!is_dir($sourceFolder)) {
            $this->fail('Resource pack source folder "' . $sourceFolder . '" does not exist.');

            return false;
        }

        $sourceManifest = $sourceFolder . '/' . self::MANIFEST_FILE;
        if (!is_file($sourceManifest)) {
            $this->fail('Resource pack source folder does not contain a ' . self::MANIFEST_FILE . '.');

            return false;
        }

        if ($this->readManifestFile($sourceManifest) === null) {
            return false;
        }

        if (!is_dir(dirname($packPath)) && !@mkdir(dirname($packPath), 0755, true) && !is_dir(dirname($packPath))) {
            $this->fail('Could not create the directory of "' . $packPath . '".');

            return false;
        }

        $temporary = $packPath . '.tmp';
        if (is_file($temporary)) {
            @unlink($temporary);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            $this->fail('Could not create the resource pack archive (ZipArchive error ' . $result . ').');

            return false;
        }

        $prefixLength = strlen($sourceFolder) + 1;
        $added = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceFolder, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $localName = substr($path, $prefixLength);

            if (!$zip->addFile($path, $localName)) {
                $zip->close();
                @unlink($temporary);
                $this->fail('Could not add "' . $localName . '" to the resource pack archive.');

                return false;
            }

            $added++;
        }

        if ($added === 0) {
            $zip->close();
            @unlink($temporary);
            $this->fail('The resource pack source folder is empty - nothing to pack.');

            return false;
        }

        if (!$zip->close()) {
            @unlink($temporary);
            $this->fail('Could not finalize the resource pack archive.');

            return false;
        }

        if (is_file($packPath) && !@unlink($packPath)) {
            @unlink($temporary);
            $this->fail('Could not replace the existing resource pack "' . $packPath . '".');

            return false;
        }

        if (!@rename($temporary, $packPath)) {
            @unlink($temporary);
            $this->fail('Could not move the resource pack into place.');

            return false;
        }

        $this->logger->info('Resource pack built from ' . $added . ' file(s): ' . $packPath);

        return true;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array{uuid: string, version: int[], modules: int}|null
     */
    private function validatePack(string $packPath) : ?array
    {
        $size = filesize($packPath);
        if ($size === false || $size === 0) {
            $this->fail('The resource pack file is empty or unreadable.');

            return null;
        }

        $manifest = $this->readManifestFile($packPath);
        if ($manifest === null) {
            return null;
        }

        $header = $manifest['header'] ?? null;
        if (!is_array($header)) {
            $this->fail('manifest.json does not contain a "header" section.');

            return null;
        }

        $uuid = strtolower(trim((string) ($header['uuid'] ?? '')));
        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            $this->fail('manifest.json contains an invalid header UUID ("' . $uuid . '").');

            return null;
        }

        $expectedUuid = $this->config->getResourcePackExpectedUuid();
        if ($expectedUuid !== '' && $expectedUuid !== $uuid) {
            $this->fail('Strict UUID check failed: expected ' . $expectedUuid . ', got ' . $uuid . '.');

            return null;
        }

        $version = $header['version'] ?? null;
        if (!is_array($version) || count($version) < 3) {
            $this->fail('manifest.json must contain a three part "version" array.');

            return null;
        }

        $modules = $manifest['modules'] ?? null;
        if (!is_array($modules) || $modules === []) {
            $this->fail('manifest.json does not declare any module.');

            return null;
        }

        $moduleCount = 0;
        foreach ($modules as $module) {
            if (is_array($module)) {
                $moduleCount++;
            }
        }

        return [
            'uuid' => $uuid,
            'version' => [(int) $version[0], (int) ($version[1] ?? 0), (int) ($version[2] ?? 0)],
            'modules' => $moduleCount,
        ];
    }

    /**
     * Reads and decodes manifest.json from a folder or a zip archive.
     *
     * @return array<string, mixed>|null
     */
    private function readManifestFile(string $path) : ?array
    {
        $raw = null;

        if (is_dir($path)) {
            $raw = (string) file_get_contents($path . '/' . self::MANIFEST_FILE);
        } else {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                $this->fail('Could not open "' . $path . '" as a zip archive.');

                return null;
            }

            $raw = $zip->getFromName(self::MANIFEST_FILE);

            if ($raw === false) {
                // Accept a single wrapping folder (some editors produce it).
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with($name, '/' . self::MANIFEST_FILE)) {
                        $raw = $zip->getFromIndex($i);
                        $this->logger->warning('manifest.json is nested inside "' . dirname($name) . '/" - the client expects it in the archive root.');
                        break;
                    }
                }
            }

            $zip->close();

            if ($raw === false || $raw === null) {
                $this->fail('manifest.json was not found in the resource pack archive.');

                return null;
            }
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail('manifest.json is not valid JSON: ' . $e->getMessage());

            return null;
        }

        if (!is_array($data)) {
            $this->fail('manifest.json must contain a JSON object.');

            return null;
        }

        return $data;
    }

    /**
     * Logs the first error once (never spams the console) and disables the
     * visual extras.
     */
    private function fail(string $message) : void
    {
        $this->lastError = $message;
        $this->pack = null;

        if ($this->reportedError) {
            return;
        }

        $this->reportedError = true;
        $this->logger->warning('Resource pack disabled: ' . $message);
        $this->logger->warning('CoomLobby continues to work with vanilla textures and text-only buttons.');
    }

    /**
     * Short status string for /coomlobby info.
     */
    public function getStatusLine() : string
    {
        if (!$this->config->isResourcePackEnabled()) {
            return 'disabled';
        }

        if ($this->pack === null) {
            return 'not loaded (' . ($this->lastError ?? 'unknown reason') . ')';
        }

        return 'v' . $this->pack->getPackVersion() . ' uuid=' . $this->pack->getPackId() . ' size=' . $this->pack->getPackSize();
    }

    public function getDeliveredCount() : int
    {
        return count($this->delivered);
    }

    public function isForceAccept() : bool
    {
        return $this->config->isResourcePackForceAccept();
    }

    public function getPackUuid() : ?string
    {
        $uuid = $this->info['uuid'] ?? null;

        return is_string($uuid) ? $uuid : null;
    }

    public function getPackVersionString() : string
    {
        $version = $this->info['version'] ?? null;

        return is_string($version) ? $version : '';
    }

    public function getPackSize() : int
    {
        $size = $this->info['size'] ?? 0;

        return is_int($size) ? $size : 0;
    }
}
