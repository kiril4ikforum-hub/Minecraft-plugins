<?php

declare(strict_types=1);

namespace CoomLobby\form;

use CoomLobby\manager\ModeManager;
use CoomLobby\manager\ResourceManager;
use CoomLobby\manager\TeleportManager;
use CoomLobby\object\GameMode;
use CoomLobby\service\LanguageService;
use pocketmine\player\Player;
use function count;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_starts_with;

/**
 * The main menu of the lobby: one button per configured game mode showing the
 * mode label, its online counter and its current status.
 *
 * Buttons are built in the same order as the "$modeButtons" map, so the index
 * returned by the client can be mapped back to a mode ID without ambiguity.
 */
class ModeSelectionForm extends SimpleForm
{
    /** @var array<int, string> button index => mode ID */
    private array $modeButtons = [];

    /** @var array<int, string> button index => translated button text */
    private array $buttonLabels = [];

    public function __construct(
        private readonly LanguageService $language,
        private readonly ModeManager $modes,
        private readonly TeleportManager $teleports,
        private readonly ResourceManager $resources
    ) {
        parent::__construct('', '');
    }

    /**
     * Builds a fully localized form for the given player.
     */
    public static function createFor(
        Player $player,
        LanguageService $language,
        ModeManager $modes,
        TeleportManager $teleports,
        ResourceManager $resources
    ) : self {
        $server = $player->getServer();

        return self::createForLanguage(
            $language->getPlayerLanguage($player),
            $language,
            $modes,
            $teleports,
            $resources,
            count($server->getOnlinePlayers()),
            $server->getMaxPlayers(),
            $player
        );
    }

    /**
     * Language explicit variant of createFor() - also used by tests and by
     * callers that already know the target language.
     */
    public static function createForLanguage(
        string $languageCode,
        LanguageService $language,
        ModeManager $modes,
        TeleportManager $teleports,
        ResourceManager $resources,
        int $online = 0,
        int $maxOnline = 0,
        ?Player $player = null
    ) : self {
        $form = new self($language, $modes, $teleports, $resources);

        $form->setTitle($language->translateFor($languageCode, 'form.title', [], $player));
        $form->setContent($language->translateFor($languageCode, 'form.content', [
            'online' => $online,
            'max_online' => $maxOnline,
        ], $player));

        foreach ($modes->getActiveModes() as $mode) {
            $form->addModeButton($languageCode, $mode, $player);
        }

        $form->addButton(SimpleFormButton::create($language->translateFor($languageCode, 'form.close', [], $player)));

        return $form;
    }

    private function addModeButton(string $languageCode, GameMode $mode, ?Player $player) : void
    {
        $online = $this->modes->getOnlineForMode($mode);
        $status = $this->modes->getStatus($mode, $player);

        $onlineText = $mode->isUnlimited()
            ? $this->language->translateFor($languageCode, 'mode.online', ['current' => $online], $player)
            : $this->language->translateFor($languageCode, 'mode.online_limited', ['current' => $online, 'max' => $mode->getMaxPlayers()], $player);

        $text = $this->language->translateFor($languageCode, 'mode.button', [
            'mode' => $this->language->getModeLabelFor($mode, $languageCode, $player),
            'online' => $onlineText,
            'status' => $this->language->getModeStatusTextFor($status, $languageCode, $player),
        ], $player);

        $description = $this->language->getModeDescriptionFor($mode, $languageCode, $player);
        if ($description !== null && $description !== '') {
            $text .= "\n" . $description;
        }

        $this->modeButtons[] = $mode->getId();
        $this->buttonLabels[] = $text;

        $this->addButton(new SimpleFormButton($text, $this->resolveIcon($mode)));
    }

    /**
     * Returns the icon of a mode button, or null when no texture is available
     * (resource pack missing/disabled) so that a text-only button is used.
     */
    private function resolveIcon(GameMode $mode) : ?FormIcon
    {
        $icon = $mode->getIcon();
        if ($icon === '') {
            return null;
        }

        $type = $mode->getIconType();

        if ($type === FormIcon::TYPE_URL) {
            return FormIcon::url($icon);
        }

        if ($type === 'auto' && (str_starts_with($icon, 'http://') || str_starts_with($icon, 'https://'))) {
            return FormIcon::url($icon);
        }

        // Without the texture inside the extracted resource pack sources the
        // client would show a broken image - fall back to a text-only button.
        return $this->resources->hasTexture($icon) ? FormIcon::path($icon) : null;
    }

    /**
     * Maps a button index back to a mode ID (null for the close button).
     */
    public function getModeAt(int $index) : ?string
    {
        return $this->modeButtons[$index] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getModeButtons() : array
    {
        return $this->modeButtons;
    }

    /**
     * @return array<int, string>
     */
    public function getButtonLabels() : array
    {
        return $this->buttonLabels;
    }

    public function isCloseButton(int $index) : bool
    {
        return $index >= count($this->modeButtons);
    }

    /**
     * @param mixed $data button index or null when the form was closed
     */
    public function handleResponse(Player $player, $data) : void
    {
        if ($data === null) {
            // The player closed the form - nothing to do.
            return;
        }

        if (is_int($data)) {
            $index = $data;
        } elseif (is_float($data) || is_string($data) && is_numeric($data)) {
            $index = (int) $data;
        } else {
            return;
        }

        $modeId = $this->getModeAt($index);
        if ($modeId === null) {
            // Close button or a stale index (mode list changed meanwhile).
            return;
        }

        $this->teleports->teleportToMode($player, $modeId);
    }
}
