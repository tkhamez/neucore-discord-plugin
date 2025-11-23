# Neucore Discord Plugin

_Needs [Neucore](https://github.com/tkhamez/neucore) version 2.2.0 or higher._

A Discord bot for [EVE Online](https://www.eveonline.com/) that can add and remove members from a 
server, manage member roles, channel membership and set nicknames.

This is a [service plugin](https://github.com/tkhamez/neucore-plugin) for Neucore. It can also be used as a 
library in other projects: `composer require tkhamez/neucore-discord-plugin`.

Features in detail:
- Adding new members to a Discord server via OAuth.
- Optionally configurable Neucore groups that are necessary to be added to the server. If a member looses those
  groups they will be kicked (if kicks are enabled).
- Configurable Discord roles that are added or removed based on Neucore groups. Any role that is not in this
  configuration is never added or removed from any member.
- Configurable channels to which members can be added and removed directly without using a role, based on
  Neucore groups. This only works for less than 100 members per channel!
- The bot changes the nickname of members to `EVE Character Name [Corporation Ticker]` by default. If a pattern is 
  specified for the "Nickname" configuration, it must contain `{characterName}`. Other available placeholders are 
  `{corporationTicker}` and `{allianceTicker}`. The maximum length for nicknames is 32 characters.
- Option to disable nickname changes for specific roles.
- The plugin also updates the Discord username and number that is shown in Neucore.
- Configurable list of Discord users that will never be kicked, even if they do not have a Neucore account.
- Several service configuration can be added to Neucore for different Discord servers.

Notes:
- The user token that is created during the invitation process is not store, it's safe to revoke it, or
  "deauthorize" the app in Discord.
- The Discord user is bound to a Neucore player account. The nickname is set to the name of the main character.
- If the main character is changed, the nickname is updated.
- The bot cannot change the nickname of the server owner.
- If the main character is removed from the account, the nickname is changed to the name of the new main.
- If all characters were removed from the Neucore player account, the member is kicked from the Discord server.
- If a member was added in another way to the Discord server, and they are already associated with a Neucore service
  account, that account status is changed to active and then updated normally (which could mean that the member is 
  kicked again). If there is no associated Neucore service account, the user is kicked if kicks are enabled.
- If kicks are disabled, the bot will remove roles from the configuration from members who have not registered via 
  Neucore.
- The bot cannot kick members with a role that is above the bot's role in the Discord configuration.
- The server owner and bots are never kicked.
- Members added to a channel are granted the "View Channel" permission for that channel and additionally "Connect"
  for voice channels, other permissions must be granted by roles or manually.

## Requirements

- A [Neucore](https://github.com/tkhamez/neucore) installation or your own project.
- A MySQL or Maria DB database.

## Setup Discord Server and App

- Create a Discord server
- Create roles
- Disable "Change nickname" for each role
- Create new application at https://discord.com/developers/applications.
- Add OAuth2 redirect URL, replace `{id}` with your Neucore service ID (see below):  
  `https://your.neucore.tld/plugin/{id}/callback`.
- Add bot.
- Enable `Server Members Intent`.
- Go back to OAuth2 -> URL Generator, at "Scopes" check `bot` and then the following bot permissions:  
  `Manage Roles`, `Manage Channels`, `Kick Members`, `Create Instant Invite`, `Manage Nicknames`.  
  Or use the following link, replace `[your-client-ID]` with your client ID (found on the OAuth2 page):  
  https://discord.com/api/oauth2/authorize?client_id=[your-client-ID]&permissions=402653203&scope=bot
- Copy the URL and open it in the browser to add the bot to your server.
- On your server go to Settings -> Roles and drag the role of the bot above the roles it will be managing. Also, 
  drag the bot role over any role that has a member you want the bot to set the nickname for.
- If you are using the bot to manage channel membership, add the bot or its role to the channels from the "Channel" 
  configuration and grant it the "View Channel" permission for each of those channels. For voice channels, also 
  grant the "Manage permissions" and "Connect" permissions.

## Install

- Follow the plugin installation instructions here:
  [Neucore Plugins.md](https://github.com/tkhamez/neucore/blob/main/doc/Plugins.md#install-a-plugin).
- Create a new database for the plugin (e.g. neucore_discord). The plugin will create the tables automatically.
- The plugin needs the following environment variables on the Neucore server:
  - `NEUCORE_DISCORD_PLUGIN_DB_DSN=mysql:dbname=neucore_discord;host=127.0.0.1;user=discord;password=pass`
  - `NEUCORE_DISCORD_PLUGIN_DB_USERNAME=username` Only required if DSN above does not include "user".
  - `NEUCORE_DISCORD_PLUGIN_DB_PASSWORD=password` Only required if DSN above does not include "password".
- Optional environment variables:
  - `NEUCORE_DISCORD_PLUGIN_DB_SSL_CA="/path/to/ca-cert.pem"`  
    This enables encryption for the connection, even if it is set to an empty value.
  - `NEUCORE_DISCORD_PLUGIN_DB_SSL_VERIFY=1`
- Add a new Neucore service.  
  The ID of it (found in the URL when the plugin configuration is shown) is needed for the 
  redirect URL for the Discord app and for the `OAuthRedirectUri` value in the "Configuration Data" (see below).
- Choose the "Discord auth" plugin from the configuration.
- Adjust all values in the "Configuration Data" field at the bottom. The format is [YAML](https://yaml.org/).
  - The following are required:
    - TableName - Allowed character: `a-z A-Z 0-9 _` (no spaces). The table is created automatically when the plugin 
      configuration is saved.
    - ServerId
    - BotToken
    - OAuthRedirectUri (also replace `{id}` in the URL with the service ID)
    - OAuthClientId
    - OAuthClientSecret
  - The following are optional and may be removed:
    - Roles
    - Channels
    - Nickname
    - NoNicknameChange
    - DoNotKick
    - DisableKicks
- Optionally add required groups.
- Optionally adjust any texts.
- Activate the plugin when done.

This plugin uses the Neucore command "update-service-accounts" to update Discord roles etc., so make sure that
the [Neucore cronjob](https://github.com/tkhamez/neucore/blob/main/doc/Install.md#cronjob) is running.

## Development:

```shell
docker build --tag neucore-plugin-discord .
docker run -it --mount type=bind,source="$(pwd)",target=/app --workdir /app neucore-plugin-discord /bin/sh
```

```shell
composer install
```

Run tests:
```shell
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```
