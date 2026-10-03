<?php

declare(strict_types=1);

namespace CoomLobby\task;

use CoomLobby\manager\ModeManager;
use pocketmine\scheduler\Task;

/**
 * Keeps the per-mode online counters warm so that opening the mode selector
 * never has to walk the worlds of every mode.
 */
final class OnlineUpdateTask extends Task
{
    public function __construct(
        private readonly ModeManager $modes
    ) {
    }

    public function onRun() : void
    {
        $this->modes->refreshOnlineCache();
    }
}
