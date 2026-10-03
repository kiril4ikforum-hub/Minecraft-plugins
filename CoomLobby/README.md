# CoomLobby

Advanced lobby system for **PocketMine-MP 5.x** (API 5.0.0+, PHP 8.1+): mode
selection UI, lobby world protection, automatic advertisements, lobby chat
control and resource pack management.

The plugin is written from scratch without virions, Composer packages or any
third party runtime library - it only uses PocketMine core and the PHP standard
library.

## Installation

1. Drop the `CoomLobby` folder (or the built `.phar`) into the server's
   `plugins/` directory.
2. Start the server once. CoomLobby writes its defaults to
   `plugin_data/CoomLobby/`:
   * `config.yml` - global settings,
   * `lang/ru.yml`, `lang/en.yml` - message catalogues,
   * `spawns.yml` - world and lobby spawns (filled by `/setlobby`),
   * `rp/` - resource pack sources extracted from the plugin,
   * `coomlobby.db` (SQLite) or `players.yml` - player statistics.
3. Configure your lobby worlds in `config.yml` (`lobby.worlds`), optionally add
   mode worlds, and run `/coomlobby reload`.

Requires `load: POSTWORLD` (already declared) and works best with the
`CoomLanguage` plugin; without it the language falls back to the first two
characters of the player's client locale, then to `language.default`.

## Commands

| Command | Aliases | Permission | Description |
| --- | --- | --- | --- |
| `/lobby` | `/lob` | `coomlobby.use` | Teleport back to the lobby. |
| `/setlobby [world]` | - | `coomlobby.admin` | Store your current position as a spawn (lobby spawn when standing in a lobby world, mode world spawn otherwise). |
| `/coomlobby reload` | `/cl reload` | `coomlobby.admin` | Reload config, language files, modes, spawns and the resource pack. |
| `/coomlobby info` | `/cl info` | `coomlobby.admin` | Diagnostic panel: versions, storage, spawns, modes, resource pack. |
| `/coomlobby tp <player> <mode>` | `/cl tp ...` | `coomlobby.admin` | Send another player to a game mode. |
| `/coomlobby rp [player]` | `/cl rp` | `coomlobby.admin` | Re-send the resource pack / show why the client has to rejoin. |

## Permissions

| Permission | Default | Description |
| --- | --- | --- |
| `coomlobby.use` | `true` | Use the mode selector and `/lobby`. |
| `coomlobby.admin` | `op` | Admin commands (`reload`, `info`, `tp`, `rp`, `/setlobby`). |
| `coomlobby.bypass` | `op` | Bypass lobby protections (build, damage, flight, item use). |
| `coomlobby.chat` | `false` | Allow chatting inside lobby worlds. |
| `coomlobby.mode.<id>` | `op` | Optional per-mode access restriction (configured with `modes.<id>.permission`) run-time data is not hardcoded). |

## Features

* **Selector item** - a vanilla item (default `minecraft:compass`, configurable)
  marked with the NBT tag `CoomLobby_Item = "selector"`, placed into a
  configurable hotbar slot, with a fallback identification by item type + name
  and lore hash. Given on join/respawn/lobby teleport and `/lobby`, removed when
  the player leaves the lobby, right click opens the mode menu (1 s cooldown).
* **Mode selection form** - a `SimpleForm` (bundled implementation, no external
  dependency) with one button per enabled mode showing the localized name,
  online counter (`Online: 3` / `Online: 3/20`), status
  (`Available` / `Full` / `Unavailable` / `Locked`), the resource pack icon when
  the texture exists and a description line. The last button closes the menu.
* **Game modes** - `grief`, `anarchy`, `bedwars`, `skywars`, `duels`,
  `skyblock`, `mine`, `murder_mystery` (fully configurable keys) with world
  lists, player limits, permissions, icons, localized names and spawn points.
  Teleporting checks online state, permission, world availability and player
  limit, keeps a global ~5 s cooldown and refreshes the cached online counters.
* **Lobby protection** - block break/place limits, item drop/pickup blocking,
  full damage immunity, hunger/exhaustion freeze, death safety (inventory kept,
  respawn at the lobby spawn), projectile blocking, forced adventure mode, flight
  control and lobby chat with anti-spam warnings.
* **Advertisements** - a configurable repeating task (600 s by default) that
  broadcasts rotated or randomised messages with `{online}`, `{server_ip}`,
  `{motd}` and other placeholders.
* **Resource pack** - `resources/rp/CoomLobbyRP` is extracted, packed into
  `.mcpack`, validated (size, `manifest.json`, UUID) and offered to every
  connecting player without duplicates; missing or corrupt packs degrade to
  text-only buttons with a single warning.
* **Public API** - `CoomLobby\api\CoomLobbyAPI`, obtained via
  `$plugin->getAPI()`:

  ```php
  $api->isLobbyWorld($world);
  $api->giveSelector($player);
  $api->removeSelector($player);
  $api->teleportToMode($player, 'bedwars');   // bool
  $api->getModeOnline('bedwars');             // int
  $api->getPlayerLanguage($player);           // "ru" | "en"
  $api->getActiveModes();                     // array<string, array>
  ```

## Configuration highlights

```yaml
lobby:
  worlds: [lobby]
  primary-world: lobby
  gamemode: adventure
  clear-inventory-on-join: true
  give-selector-on-join: true

selector:
  item: minecraft:compass
  slot: 8
  cooldown-ms: 1000

modes:
  bedwars:
    enabled: true
    world: bedwars
    max-players: 40
    permission: coomlobby.mode.bedwars
    icon: textures/ui/coomlobby/bedwars.png
    name:
      ru: "&6BedWars"
      en: "&6BedWars"

teleport:
  mode-cooldown: 5

resource-pack:
  enabled: true
  file: rp/CoomLobbyRP.mcpack
```

Every value is validated on load: invalid entries are logged and replaced with
the built-in default instead of crashing the server.

## Building a `.phar`

[Poggit](https://poggit.pmmp.io) builds the plugin from the `CoomLobby/`
sub-directory (see `.poggit.yml`), or pack the folder manually:

```bash
zip -r CoomLobby.phar CoomLobby/plugin.yml CoomLobby/src CoomLobby/resources
```

## License

Released under the project's repository license.
