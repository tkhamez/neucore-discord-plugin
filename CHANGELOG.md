# Changelog:

## next

- Update to neucore-plugin 2.0.0

## 3.4.0, 2023-02-11

- NEUCORE_DISCORD_PLUGIN_DB_USERNAME and NEUCORE_DISCORD_PLUGIN_DB_PASSWORD are now optional: user and password
  can be added to NEUCORE_DISCORD_PLUGIN_DB_DSN.
- Update to neucore-plugin 1.0.0

## 3.3.0, 2023-01-22

- The table from the configuration is now automatically created when the plugin configuration is saved.
- Groups used when inviting to a server now respect the "group deactivation" feature. (Update to plugin
  version 0.11.0, needs Neucore > 1.42.0)
- Added support for encrypted database connection.

## 3.2.0, 2023-01-06

- Updated to plugin version 0.10.0.

## 3.1.0, 2022-12-28

- Added plugin.yml file (this adds compatibility with Neucore version >= 1.41.0) and updated documentation.
- Updated to plugin version 0.9.2.

## 3.0.0, 2022-12-26

- Raised minimum PHP version to 8.0 (from 7.4).
- Updated to plugin version 0.9.0.

## 2.1.0, 2022-11-05

- Change: Roles from the configuration are now removed from server members who do not have a service account if
  server kicks are disabled (otherwise they would be kicked).

## 2.0.0, 2022-06-28

- Updated to plugin version 0.8.0.
- Changed DB schema: character_id is now "not null" (`alter table table_name modify character_id int not null`).
- Exceptions from the player update during the callback request are now caught.

## 1.0.1, 2022-06-16

- Fix: The nickname is now removed if there is no longer a main character on Core.
- Fix: Service accounts are now removed from Core accounts that no longer have a character. This also fixes an
  integrity constraint violation for `character_id` if it's 0.
- Added Discord user ID and EVE character ID to some error messages, e.g. if a kick failed.
- Added log entry when a user was added to the server.

## 1.0.0, 2022-04-23

- Added to Packagist.

## 2022-02-05

- Added option to disable nickname changes for specific roles.

## 2022-01-22

- Added channel membership management.
- Added Nickname configuration option.

## 2022-01-16

- Roles are now assigned directly after a user was added to the server.
- Raised minimum required PHP version to 7.4.

## 2021-11-15

- Added server member cache.

## 2021-09-21

- Added rate limit handling.

## 2021-09-17

Initial version  

- that can add and remove members from a server, 
- manage roles and nicknames, 
- with support for required Neucore groups, 
- exceptions for server members that will never be kicked, 
- option to disable kicks completely.
