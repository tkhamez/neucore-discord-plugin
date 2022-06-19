# Neucore Discord Plugin

A Discord bot that can add and remove members from a server, manage member roles, channel membership and set nicknames.

This is a service plugin for [Neucore](https://github.com/tkhamez/neucore). It can also be used as a 
library in other projects: `composer require tkhamez/neucore-discord-plugin`.

Features in detail:
- Adding new members to a Discord server via OAuth.
- Optionally configurable Neucore groups that are necessary to be added to the server. If a member looses those
  groups they will be kicked.
- Configurable Discord roles that are added or removed based on Neucore groups. Any role that is not in this
  configuration is never added or removed from any member.
- Configurable channels to which members can be added and removed directly without using a role, based on
  Neucore groups. This only works for less than 100 members!
- The bot changes the nickname of members to `EVE Character Name [Corporation Ticker]` by default. If a pattern is 
  specified for the "Nickname" configuration, it must contain `{characterName}`. Other available placeholders are 
  `{corporationTicker}` and `{allianceTicker}`. The maximum length for nicknames is 32 characters.
- Option to disable nickname changes for specific roles.
- The plugin also updates the Discord username and number that is shown in Neucore.
- Optional: Any server member that does not have an account on Neucore can be kicked, except for the server 
  owner and bots.
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
  kicked again). If there is no associated Neucore service account, the user is also kicked.
- Members added to a channel are granted the "View Channel" permission for that channel and additionally "Connect"
  for voice channels, other permissions must be granted by roles or manually.
- The bot only manages roles and channels for members who have signed up via Neucore. Members added in other ways 
  are not changed, but can be kicked depending on the configuration.

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
- Set PHP class (`Neucore\Plugin\Discord\Service`), PSR-4 prefix (`Neucore\Plugin\Discord`) and path 
  (`/your/path/to/neucore-discord-plugin/src`).
- Check "Limit to one service account". *This is **important**!*
- Optionally add required groups.
- Set Account Properties to: username,status.
- Set Account Actions to: update-account
- Add two link buttons:
  - URL (replace `{id}` with your service ID): `/plugin/{id}/login`, Title: `Request Invitation`
  - URL: `https://discord.com/login`, Title: `Discord Login`, Target: `_blank`
- Text Register: Click "Register" to initialize your account.
- Text Account:  
  Status Nonmember: Click "Request Invitation" to be added to the server. To do this,  you will need to log 
  into your Discord account, allow access to your username, and allow the app to join servers for you. After 
  you have been successfully invited, you can deauthorize this app in your user settings if you wish.    
  Status Active: Click the "Update Account" button to sync your Core groups with Discord roles. This also 
  happens automatically several times a day.
- Add the following in the "Configuration Data" text area (YAML format), replace with your values:
  ```yaml
  TableName: database_table_name # Only allows the following chars: a-zA-Z0-9_
  ServerId: 123 # Discord server ID
  BotToken: abc # Discord application's bot token
  OAuthRedirectUri: http://your.neucore.tld/plugin/{id}/callback
  OAuthClientId: 456
  OAuthClientSecret: def
  Roles: # The Neucore player account needs one of these groups to get the Discord role
    987: # Discord role ID
    - 1 # Neucore group ID
  Channels:
    654: [9] # Discord channel ID: Neucore group ID
  Nickname: '{characterName} [{corporationTicker}]'
  NoNicknameChange: [654, 456] # Discord role IDs
  DoNotKick: # These members will never be kicked
  - 543 # Discord user ID
  DisableKicks: false
  ```

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

- Update to plugin version 0.8.0.
- Changed DB schema: character_id is now "not null" (`alter table table_name modify character_id int not null`).

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
