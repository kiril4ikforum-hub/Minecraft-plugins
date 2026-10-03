<?php

declare(strict_types=1);

namespace CoomLobby\form;

use JsonSerializable;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * Icon attached to a form button.
 *
 * Bedrock supports two image sources: a texture path that is resolved inside
 * the applied resource packs ("path") and a remote image ("url").
 */
final class FormIcon implements JsonSerializable
{
    public const TYPE_PATH = 'path';
    public const TYPE_URL = 'url';

    public function __construct(
        private readonly string $type,
        private readonly string $data
    ) {
    }

    public static function path(string $texturePath) : self
    {
        return new self(self::TYPE_PATH, $texturePath);
    }

    public static function url(string $url) : self
    {
        return new self(self::TYPE_URL, $url);
    }

    public static function fromConfigValue(string $value, string $type = 'auto') : ?self
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($type === self::TYPE_URL || $type === 'auto' && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))) {
            return self::url($value);
        }

        return self::path($value);
    }

    public function getType() : string
    {
        return $this->type;
    }

    public function getData() : string
    {
        return $this->data;
    }

    public function isValid() : bool
    {
        if ($this->type === self::TYPE_URL) {
            return str_starts_with($this->data, 'http://') || str_starts_with($this->data, 'https://');
        }

        return $this->data !== '' && strlen($this->data) <= 256;
    }

    /**
     * @return array{type: string, data: string}
     */
    public function jsonSerialize() : array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
        ];
    }
}
