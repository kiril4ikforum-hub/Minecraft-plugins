<?php

declare(strict_types=1);

namespace CoomLobby\form;

use JsonSerializable;

/**
 * One clickable button of a SimpleForm.
 */
final class SimpleFormButton implements JsonSerializable
{
    /**
     * @param string|null $value optional application payload; the client only
     *                           sends back the button index, so this is used
     *                           by the plugin to remember which entry a button
     *                           belongs to (e.g. a mode ID).
     */
    public function __construct(
        private readonly string $text,
        private readonly ?FormIcon $icon = null,
        private readonly ?string $value = null
    ) {
    }

    public static function create(string $text) : self
    {
        return new self($text);
    }

    public function getText() : string
    {
        return $this->text;
    }

    public function getIcon() : ?FormIcon
    {
        return $this->icon;
    }

    public function getValue() : ?string
    {
        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize() : array
    {
        $data = ['text' => $this->text];

        if ($this->icon !== null && $this->icon->isValid()) {
            $data['image'] = $this->icon->jsonSerialize();
        }

        return $data;
    }
}
