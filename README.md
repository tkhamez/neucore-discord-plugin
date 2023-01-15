# Neucore Discord Plugin

A Discord bot that can add and remove members from a server, manage member roles, channel membership and set nicknames.

This is a service plugin for [Neucore](https://github.com/tkhamez/neucore). It can also be used as a 
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

- Create the database table from `db-schema.sql`. For each additional service configuration in Neucore for this
  plugin add another table (change the name).
  The plugin needs the following environment variables on the Neucore server:
  ```
  NEUCORE_DISCORD_PLUGIN_DB_DSN=mysql:dbname=neucore_discord;host=127.0.0.1
  NEUCORE_DISCORD_PLUGIN_DB_USERNAME=username
  NEUCORE_DISCORD_PLUGIN_DB_PASSWORD=password
  ```
- Add a new Neucore service.   
  The ID of it (found in the URL when the plugin is loaded) is needed for the 
  redirect URL for the Discord app and for the `OAuthRedirectUri` value in the "Configuration Data" (see below).
- Choose the "Discord auth" plugin from the configuration.
- Adjust all values in the "Configuration Data" field at the bottom. The format is [YAML](https://yaml.org/).
  - The following are required:
    - TableName
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

## Development:

```shell
composer install
```

Run tests:
```shell
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

## Changelog:

Next

- Groups used when inviting to a server now respect the "group deactivation" feature. (needs Neucore > 1.42.0)

Version 3.2.0, 2023-01-06

- Updated to plugin version 0.10.0.

Version 3.1.0, 2022-12-28

- Added plugin.yml file (this adds compatibility with Neucore version >= 1.41.0) and updated documentation. 
- Updated to plugin version 0.9.2.

Version 3.0.0, 2022-12-26

- Raised minimum PHP version to 8.0 (from 7.4).
- Updated to plugin version 0.9.0.

Version 2.1.0, 2022-11-05

- Change: Roles from the configuration are now removed from server members who do not have a service account if
  server kicks are disabled (otherwise they would be kicked).

Version 2.0.0, 2022-06-28

- Updated to plugin version 0.8.0.
- Changed DB schema: character_id is now "not null" (`alter table table_name modify character_id int not null`).
- Exceptions from the player update during the callback request are now caught.

Version 1.0.1, 2022-06-16

- Fix: The nickname is now removed if there is no longer a main character on Core.
- Fix: Service accounts are now removed from Core accounts that no longer have a character. This also fixes an 
  integrity constraint violation for `character_id` if it's 0.
- Added Discord user ID and EVE character ID to some error messages, e.g. if a kick failed.
- Added log entry when a user was added to the server.

Version 1.0.0, 2022-04-23

- Added to Packagist.

2022-02-05

- Added option to disable nickname changes for specific roles.

2022-01-22

- Added channel membership management.
- Added Nickname configuration option.

2022-01-16

- Roles are now assigned directly after a user was added to the server.
- Raised minimum required PHP version to 7.4.
