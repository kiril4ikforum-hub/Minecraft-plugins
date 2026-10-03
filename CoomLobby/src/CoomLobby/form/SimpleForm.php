<?php

declare(strict_types=1);

namespace CoomLobby\form;

use JsonSerializable;
use pocketmine\form\Form;
use pocketmine\player\Player;

/**
 * Minimal, dependency free SimpleForm implementation.
 *
 * PocketMine-MP 5.x ships the pocketmine\form\Form interface but no concrete
 * form classes, so the plugin provides its own implementation instead of
 * pulling in a virion or composer package. The JSON layout follows the
 * Bedrock "form" specification:
 *
 *   {"type":"form","title":"...","content":"...","buttons":[{"text":"..."}]}
 *
 * The client answers with the index of the clicked button, or null when the
 * form was closed.
 */
class SimpleForm implements Form
{
    private string $title = '';
    private string $content = '';

    /** @var SimpleFormButton[] */
    private array $buttons = [];

    public function __construct(string $title = '', string $content = '')
    {
        $this->title = $title;
        $this->content = $content;
    }

    public function setTitle(string $title) : self
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle() : string
    {
        return $this->title;
    }

    public function setContent(string $content) : self
    {
        $this->content = $content;

        return $this;
    }

    public function getContent() : string
    {
        return $this->content;
    }

    public function addButton(SimpleFormButton $button) : self
    {
        $this->buttons[] = $button;

        return $this;
    }

    /**
     * @return SimpleFormButton[]
     */
    public function getButtons() : array
    {
        return $this->buttons;
    }

    public function getButtonAt(int $index) : ?SimpleFormButton
    {
        return $this->buttons[$index] ?? null;
    }

    public function getButtonCount() : int
    {
        return count($this->buttons);
    }

    /**
     * @return array{type: string, title: string, content: string, buttons: array<int, array<string, mixed>>}
     */
    public function jsonSerialize() : array
    {
        $buttons = [];
        foreach ($this->buttons as $button) {
            $buttons[] = $button->jsonSerialize();
        }

        return [
            'type' => 'form',
            'title' => $this->title === '' ? 'CoomLobby' : $this->title,
            'content' => $this->content,
            'buttons' => $buttons,
        ];
    }

    /**
     * Sends the form to a player, swallowing (and reporting) any protocol
     * level failure so that a broken form can never crash the lobby.
     */
    public function sendTo(Player $player) : bool
    {
        try {
            $player->sendForm($this);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Called with the button index, or null when the player closed the form.
     *
     * @param mixed $data
     */
    public function handleResponse(Player $player, $data) : void
    {
        // The base form does not react to anything; subclasses implement the
        // behaviour they need.
    }
}
