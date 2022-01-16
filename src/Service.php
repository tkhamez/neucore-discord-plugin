<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\CoreGroup;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use Neucore\Plugin\ServiceConfiguration;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use function json_decode;

class Service implements ServiceInterface
{
    private const PLUGIN_NAME = 'neucore-discord-plugin';

    private const USERNAME_NA = 'n/a';

    private const RATE_LIMIT_REMAIN = 'X-RateLimit-Remaining';

    private const RATE_LIMIT_RESET_AFTER = 'X-RateLimit-Reset-After';

    private const RATE_LIMIT_BUCKET = 'X-RateLimit-Bucket';

    private LoggerInterface $logger;

    private ServiceConfiguration $configuration;

    private string $sessionStateKey;

    private string $tableName = '';

    private string $serverId = '';

    /**
     * @var array<string, string>
     */
    private array $authHeader = [];

    private string $oAuthRedirectUri = '';

    private string $oAuthClientId = '';

    private string $oAuthClientSecret = '';

    /**
     * @var array<string, int[]>
     */
    private array $roleConfig = [];

    /**
     * @var string[]
     */
    private array $doNotKick = [];

    private bool $disableKicks = false;

    private ?PDO $pdo = null;

    private ?ClientInterface $httpClient = null;

    private int $lastRequestErrorCode = 0;

    private string $lastRequestErrorBody = '';

    private ?ResponseInterface $lastRequestResult = null;

    /**
     * @var array<string, array>
     */
    private array $rateLimits = [];

    /**
     * Cache of discord members to reduce API request.
     *
     * @var array<string, stdClass>
     */
    private array $discordMembers = [];

    public function __construct(LoggerInterface $logger, ServiceConfiguration $serviceConfiguration)
    {
        $this->logger = $logger;
        $this->configuration = $serviceConfiguration;
        $this->sessionStateKey = "__plugin_{$this->configuration->id}_state";

        $this->parseConfig($serviceConfiguration->configurationData);
    }

    private function parseConfig(string $configurationData)
    {
        $config = Yaml::parse($configurationData);

        // required
        $this->tableName = (string) preg_replace(
            '/[^a-zA-Z0-9_]/', '',
            $config['TableName'] ?? '__missing_table_name__'
        );
        $this->serverId = (string) ($config['ServerId'] ?? '');
        $botToken = (string) ($config['BotToken'] ?? '');
        $this->authHeader = ['Authorization' => "Bot $botToken"];
        $this->oAuthRedirectUri = (string) ($config['OAuthRedirectUri'] ?? '');
        $this->oAuthClientId = (string) ($config['OAuthClientId'] ?? '');
        $this->oAuthClientSecret = (string) ($config['OAuthClientSecret'] ?? '');

        if (
            empty($this->tableName) ||
            empty($this->serverId) ||
            empty($botToken) ||
            empty($this->oAuthRedirectUri) ||
            empty($this->oAuthClientId) ||
            empty($this->oAuthClientSecret)
        ) {
            $this->logError('Configuration is incomplete.');
        }

        // optional
        $roles = $config['Roles'] ?? [];
        foreach ($roles as $roleId => $groupIds) {
            $this->roleConfig[(string)$roleId] = array_map('intval', $groupIds);
        }
        $this->doNotKick = array_map('intval', $config['DoNotKick'] ?? []);
        $this->disableKicks = (bool) ($config['DisableKicks'] ?? false);
    }

    /**
     * @throws Exception
     */
    public function getAccounts(array $characters): array
    {
        if (empty($characters)) {
            return [];
        }

        // fetch accounts
        $characterIds = array_map(function (CoreCharacter $character) {
            return $character->id;
        }, $characters);
        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            // Will return max 1 account because all characters from the parameter are on the same account.
            // Limit by player_id to exclude characters that were moved to another account.
            "SELECT character_id, player_id, username, discriminator, member_status
            FROM $this->tableName 
            WHERE character_id IN ($placeholders) AND player_id = ?"
        );
        try {
            $stmt->execute(array_merge($characterIds, [$characters[0]->playerId]));
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        if (isset($rows[0])) {
            $result[] = new ServiceAccountData(
                (int) $rows[0]['character_id'],
                $rows[0]['username'] .
                    ($rows[0]['username'] !== self::USERNAME_NA ? '#' . $rows[0]['discriminator'] : ''),
                null,
                null,
                $rows[0]['member_status']
            );
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function register(
        CoreCharacter $character,
        array $groups,
        string $emailAddress,
        array $allCharacterIds
    ): ServiceAccountData {
        $username = self::USERNAME_NA; // actual account registration will be done in via request()

        $this->createAccount($character->id, $character->playerId, ServiceAccountData::STATUS_NONMEMBER, $username);

        return new ServiceAccountData($character->id, $username);
    }

    public function updateAccount(CoreCharacter $character, array $groups, ?CoreCharacter $mainCharacter): void
    {
        if (!$mainCharacter) {
            // should not happen because there is always a main on a player account with at least on character
            $mainCharacter = new CoreCharacter(0, $character->playerId);
        }
        $this->updatePlayerAccount($mainCharacter, $groups);
    }

    /**
     * https://discord.com/developers/docs/resources/guild#get-guild-member - no permission
     * https://discord.com/developers/docs/resources/guild#add-guild-member-role - MANAGE_ROLES
     * https://discord.com/developers/docs/resources/guild#remove-guild-member-role - MANAGE_ROLES
     *
     * @throws Exception
     */
    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        // variables
        $accountGroupIds = array_map(function (CoreGroup $group) {
            return $group->identifier;
        }, $groups);

        // Get member data of player from service account
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("SELECT character_id, discord_id FROM $this->tableName WHERE player_id = ?");
        try {
            $stmt->execute([$mainCharacter->playerId]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception();
        }
        $resultAccount = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $characterId = $resultAccount[0]['character_id'] ?? '';
        $discordUserId = $resultAccount[0]['discord_id'] ?? '';
        if (empty($discordUserId)) {
            return;
        }

        // Check if the player account still has a main character, kick if not
        if ($mainCharacter->id === 0 && !in_array($discordUserId, $this->doNotKick) && !$this->disableKicks) {
            $result = $this->kickMember($discordUserId);
            if ($result === null) { // error
                throw new Exception('Failed to kick.');
            }
            $this->log("Kicked $discordUserId (no main).");
            $this->deleteAccount($mainCharacter->playerId);
            return;
        }

        // Update character ID of service account if main changed.
        if ($characterId !== $mainCharacter->id) {
            /** @noinspection SqlResolve */
            $stmt = $this->getPDO()->prepare(
                "UPDATE $this->tableName SET character_id = ?, updated = NOW() WHERE player_id = ?"
            );
            try {
                $stmt->execute([$mainCharacter->id, $mainCharacter->playerId]);
            } catch (PDOException $e) {
                $this->logException($e, __FUNCTION__);
                throw new Exception('Failed to update service account with new character ID.');
            }
        }

        // Get member data from Discord (roles, nick, username, discriminator)
        if (isset($this->discordMembers[$discordUserId])) {
            $memberObject = $this->discordMembers[$discordUserId];
        } else {
            $member = $this->apiRequest(
                'GET',
                "https://discord.com/api/guilds/$this->serverId/members/$discordUserId",
                $this->authHeader
            );
            $memberObject = json_decode((string) $member);
        }
        if (!is_object($memberObject)) {
            $lastErrorBody = json_decode($this->lastRequestErrorBody);
            if (
                $this->lastRequestErrorCode === 404 &&
                isset($lastErrorBody->code) &&
                $lastErrorBody->code === 10007 // Unknown Member
            ) {
                $this->updateAccountStatus($mainCharacter->playerId, null, ServiceAccountData::STATUS_NONMEMBER);
                return;
            }
            if (!isset($memberObject->roles)) {
                throw new Exception('Failed to read member roles.');
            }
        }
        $memberRoleIds = $memberObject->roles; /* @var string[] $memberRoleIds */
        $memberNickname = $memberObject->nick ?? '';
        $memberUsername = $memberObject->user->username ?? '';
        $memberDiscriminator = $memberObject->user->discriminator ?? '';

        // Update status, username and discriminator
        if (!empty($memberUsername) && !empty($memberDiscriminator)) {
            /** @noinspection SqlResolve */
            $stmt = $this->getPDO()->prepare(
                "UPDATE $this->tableName 
                SET member_status = ?, username = ?, discriminator = ?, updated = NOW() 
                WHERE player_id = ?"
            );
            try {
                $stmt->execute([
                    ServiceAccountData::STATUS_ACTIVE,
                    $memberUsername,
                    $memberDiscriminator,
                    $mainCharacter->playerId
                ]);
            } catch (PDOException $e) {
                $this->logException($e, __FUNCTION__);
                throw new Exception('Failed to update username/discriminator.');
            }
        }

        // Check required groups and kick if needed.
        if (
            !empty($this->configuration->requiredGroups) &&
            empty(array_intersect($this->configuration->requiredGroups, $accountGroupIds)) &&
            !in_array($discordUserId, $this->doNotKick) &&
            !$this->disableKicks
        ) {
            $result = $this->kickMember($discordUserId);
            if ($result === null) { // error
                throw new Exception('Failed to kick.');
            }
            $this->log("Kicked $discordUserId (missing required group).");
            $this->updateAccountStatus($mainCharacter->playerId, null, ServiceAccountData::STATUS_NONMEMBER);
            return;
        }

        // Add and remove roles
        $rolesToManage = [];
        $shouldHaveRoles = [];
        foreach ($this->roleConfig as $roleId => $groupIds) {
            $rolesToManage[] = $roleId;
            if (!empty(array_intersect($groupIds, $accountGroupIds))) {
                $shouldHaveRoles[] = $roleId;
            }
        }
        $rolesToRemove = [];
        foreach ($memberRoleIds as $memberRoleId) {
            if (!in_array($memberRoleId, $shouldHaveRoles) && in_array($memberRoleId, $rolesToManage)) {
                $rolesToRemove[] = $memberRoleId;
            }
        }
        $rolesToAdd = [];
        foreach ($shouldHaveRoles as $shouldHaveRoleId) {
            if (!in_array($shouldHaveRoleId, $memberRoleIds) && in_array($shouldHaveRoleId, $rolesToManage)) {
                $rolesToAdd[] = $shouldHaveRoleId;
            }
        }
        $roleSuccess = true;
        foreach ($rolesToRemove as $roleToRemove) {
            $resultRemove = $this->apiRequest(
                'DELETE',
                "https://discord.com/api/guilds/$this->serverId/members/$discordUserId/roles/$roleToRemove",
                $this->authHeader
            );
            if ($resultRemove !== null) {
                $this->log("Removed role $roleToRemove from $discordUserId.");
            } else {
                $roleSuccess = false;
            }
        }
        foreach ($rolesToAdd as $roleToAdd) {
            $resultAdd = $this->apiRequest(
                'PUT',
                "https://discord.com/api/guilds/$this->serverId/members/$discordUserId/roles/$roleToAdd",
                $this->authHeader
            );
            if ($resultAdd !== null) {
                $this->log("Added role $roleToAdd to $discordUserId.");
            } else {
                $roleSuccess = false;
            }
        }
        if (!$roleSuccess) {
            throw new Exception('Failed add/remove role(s).');
        }

        // Update Discord nickname
        if (!$this->setNickname($discordUserId, $mainCharacter, $memberNickname)) {
            throw new Exception('Failed to change nickname.');
        }
    }

    public function resetPassword(int $characterId): string
    {
        throw new Exception();
    }

    public function getAllAccounts(): array
    {
        return [];
    }

    /**
     * https://discord.com/developers/docs/resources/guild#list-guild-members - Server Members Intent
     * @throws Exception
     */
    public function getAllPlayerAccounts(): array
    {
        // Get all current server members
        $discordUserIds = []; /* @var string[] $discordUserIds */
        $limit = 500;
        $after = 0;
        $this->discordMembers = [];
        while (true) {
            $membersResult = $this->apiRequest(
                'GET',
                "https://discord.com/api/guilds/$this->serverId/members?limit=$limit&after=$after",
                $this->authHeader
            );
            $members = json_decode((string) $membersResult);
            if (!is_array($members)) { // request error
                break;
            }
            foreach ($members as $member) {
                if (!isset($member->user->bot) || !$member->user->bot) {
                    $discordUserIds[] = $member->user->id;
                    $this->discordMembers[$member->user->id] = $member; // cache for later use in updatePlayerAccount()
                }
                $after = max($after, $member->user->id);
            }
            if (count($members) < $limit) { // no more results
                break;
            }
        }

        // Set service accounts active that exist in the local database from current server members
        // (that were added otherwise to the server)
        foreach (array_chunk($discordUserIds, 500) as $chunks) {
            $this->updateAccountStatus(null, $chunks, ServiceAccountData::STATUS_ACTIVE);
        }

        // Kick server members (that were added otherwise to the server) that do not exist in the local database
        $localDiscordIds = [];
        foreach (array_chunk($discordUserIds, 500) as $chunks) {
            $placeholders = implode(',', array_fill(0, count($chunks), '?'));
            /** @noinspection SqlResolve */
            $stmt = $this->getPDO()->prepare(
                "SELECT discord_id FROM $this->tableName WHERE discord_id IN ($placeholders)"
            );
            try {
                $stmt->execute($chunks);
            } catch (PDOException $e) {
                $this->logException($e, __FUNCTION__);
                continue;
            }
            $localDiscordIds = array_merge(
                $localDiscordIds,
                array_map(function (array $row) {
                    return $row['discord_id'];
                }, $stmt->fetchAll(PDO::FETCH_ASSOC))
            );
        }
        $kickDiscordIds = array_diff($discordUserIds, $localDiscordIds);
        foreach ($kickDiscordIds as $kickDiscordId) {
            if (!in_array($kickDiscordId, $this->doNotKick) && !$this->disableKicks) {
                $result = $this->kickMember($kickDiscordId);
                if ($result !== null) {
                    $this->log("Kicked $kickDiscordId (no Neucore service account).");
                }
            }
        }

        // Fetch all accounts and return them
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "SELECT player_id FROM $this->tableName WHERE member_status = ? ORDER BY updated"
        );
        try {
            $stmt->execute([ServiceAccountData::STATUS_ACTIVE]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception();
        }
        return array_map(function (array $row) {
            return (int)$row['player_id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * https://discord.com/developers/docs/resources/guild#add-guild-member -
     *     CREATE_INSTANT_INVITE + user token with "guilds.join" scope
     * @throws Exception
     */
    public function request(
        CoreCharacter $coreCharacter,
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $groups
    ): ResponseInterface {
        if ($name === 'login') {
            return $this->requestLogin($response);
        } elseif ($name === 'callback') {
            $response = $this->requestCallback($coreCharacter, $request, $response);
            $this->updatePlayerAccount($coreCharacter, $groups);
            return $response;
        }
        $response->getBody()->write('404 Not Found.');
        return $response->withStatus(404);
    }

    private function requestLogin(ResponseInterface $response): ResponseInterface
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $_SESSION[$this->sessionStateKey] = bin2hex(random_bytes(16));

        $url = 'https://discord.com/api/oauth2/authorize' .
            '?client_id=' . $this->oAuthClientId .
            '&redirect_uri=' . urlencode($this->oAuthRedirectUri) .
            '&response_type=code' .
            '&scope=' . rawurlencode('identify guilds.join') .
            '&state=' . $_SESSION[$this->sessionStateKey];

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    /**
     * @throws Exception
     */
    private function requestCallback(
        CoreCharacter $coreCharacter,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // check state
        $state = $request->getQueryParams()['state'] ?? null;
        if ($state === null || $state !== ($_SESSION[$this->sessionStateKey] ?? null)) {
            return $this->buildCallbackResponse($response, 'Failed: OAuth state mismatch.');
        }
        unset($_SESSION[$this->sessionStateKey]);

        // get code
        $code = $request->getQueryParams()['code'] ?? null;
        if (!$code) {
            return $this->buildCallbackResponse($response, 'Failed: Missing OAuth code.');
        }

        // get access token
        $body1 = $this->sendRequest(
            'POST',
            'https://discord.com/api/oauth2/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $this->oAuthClientId,
                'client_secret' => $this->oAuthClientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->oAuthRedirectUri,
            ])
        );
        $token = json_decode((string)$body1, true);
        $accessToken = $token['access_token'] ?? null;
        if (!$accessToken) {
            return $this->buildCallbackResponse($response, 'Failed: No access token.');
        }

        // get user data
        $body2 = $this->sendRequest(
            'GET',
            "https://discord.com/api/oauth2/@me",
            ['Authorization' => "Bearer $accessToken"]
        );
        $info = json_decode((string)$body2, true);
        $userId = $info['user']['id'] ?? null;
        $username = $info['user']['username'] ?? null;
        $discriminator = $info['user']['discriminator'] ?? null;
        if (!$userId || !$username || !$discriminator) {
            return $this->buildCallbackResponse($response, 'Failed: Could not retrieve Discord user id.');
        }

        // Delete service account for this Discord user from any other Neucore account, should it exist
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("DELETE FROM $this->tableName WHERE discord_id = ? AND player_id <> ?");
        try {
            $stmt->execute([$userId, $coreCharacter->playerId]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            return $this->buildCallbackResponse(
                $response,
                'Failed: Could not delete this Discord user from other Neucore accounts.'
            );
        }

        // Check if account exists
        $exists = false;
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("SELECT player_id FROM $this->tableName WHERE player_id = ?");
        try {
            $stmt->execute([$coreCharacter->playerId]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            return $this->buildCallbackResponse($response, 'Failed: Could not fetch local service account.');
        }
        if ($stmt->rowCount() > 0) {
            $exists = true;
        }
        if (!$exists) {
            try {
                $this->createAccount(
                    $coreCharacter->id,
                    $coreCharacter->playerId,
                    ServiceAccountData::STATUS_ACTIVE,
                    $username
                );
            } catch (Exception $e) {
                // already logged
                return $this->buildCallbackResponse($response, 'Failed: Could not create local service account.');
            }
        }

        // Update record (also update character id because main could have changed).
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "UPDATE $this->tableName 
            SET
                character_id = :character_id,
                discord_id = :discord_id,
                username = :username,
                member_status = :member_status,
                discriminator = :discriminator,
                updated = NOW()
            WHERE player_id = :player_id"
        );
        try {
            $stmt->execute([
                ':character_id' => $coreCharacter->id,
                ':discord_id' => $userId,
                ':username' => $username,
                ':member_status' => ServiceAccountData::STATUS_ACTIVE,
                ':discriminator' => $discriminator,
                ':player_id' => $coreCharacter->playerId,
            ]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            return $this->buildCallbackResponse($response, 'Failed: Could not update local service account');
        }

        // Add to server.
        $body3 = $this->apiRequest(
            'PUT',
            "https://discord.com/api/guilds/$this->serverId/members/$userId",
            $this->authHeader + ['Content-Type' => 'application/json'],
            json_encode(['access_token' => $accessToken])
        );
        if ($body3 === null) {
            $lastErrorBody = json_decode($this->lastRequestErrorBody);
            if (
                $this->lastRequestErrorCode === 403 &&
                isset($lastErrorBody->code) &&
                $lastErrorBody->code === 40007 // banned
            ) {
                return $this->buildCallbackResponse($response, 'Failed: You are banned on this Discord server.');
            }
            return $this->buildCallbackResponse($response, 'Failed: Could not add member to Discord server.');
        }

        // Set nickname
        if (!$this->setNickname($userId, $coreCharacter)) {
            return $this->buildCallbackResponse($response, 'Invitation successful, but failed to set nickname.');
        }

        return $this->buildCallbackResponse($response, 'Successfully added member to server.');
    }

    /**
     * @throws Exception
     */
    private function createAccount(int $characterId, int $playerId, string $status, string $username): void
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "INSERT INTO $this->tableName (character_id, player_id, member_status, username, created, updated)
            VALUES (:character_id, :player_id, :member_status, :username, NOW(), NOW())"
        );
        try {
            $stmt->execute([
                ':character_id' => $characterId,
                ':player_id' => $playerId,
                ':member_status' => $status,
                ':username' => $username,
            ]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception();
        }
    }

    /**
     * @throws Exception
     */
    private function deleteAccount(int $playerId)
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("DELETE FROM $this->tableName WHERE player_id = ?");
        try {
            $stmt->execute([$playerId]);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception('Failed to delete service account.');
        }
    }

    /**
     * @param int|null $playerId Must be provided if $discordIds is null.
     * @param string[]|null $discordIds Must be provided if $playerId is null, will be ignored if $playerId is not null.
     * @param string $status
     * @throws Exception
     */
    private function updateAccountStatus(?int $playerId, ?array $discordIds, string $status): void
    {
        $params = [$status];
        if ($playerId) {
            /** @noinspection SqlResolve */
            $sql = "UPDATE $this->tableName SET member_status = ?, updated = NOW() WHERE player_id = ?";
            $params[] = $playerId;
        } elseif (!empty($discordIds)) {
            $placeholders = implode(',', array_fill(0, count($discordIds), '?'));
            /** @noinspection SqlResolve */
            $sql = "UPDATE $this->tableName 
                    SET member_status = ?, updated = NOW() 
                    WHERE discord_id IN ($placeholders)";
            $params = array_merge($params, $discordIds);
        } else {
            return;
        }
        $stmt = $this->getPDO()->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            $this->logException($e, __FUNCTION__);
            throw new Exception();
        }
    }

    private function buildCallbackResponse(ResponseInterface $response, string $message): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/#Service/' . $this->configuration->id . '/?message=' . rawurlencode($message))
            ->withStatus(302);
    }

    /**
     * https://discord.com/developers/docs/resources/guild#modify-guild-member - MANAGE_NICKNAMES
     */
    private function setNickname(string $userId, CoreCharacter $character, string $currentNickname = null): bool
    {
        $newNickname = "$character->name [$character->corporationTicker]";
        if ($currentNickname === $newNickname) {
             return true;
        }
        $result = $this->apiRequest(
            'PATCH',
            "https://discord.com/api/guilds/$this->serverId/members/$userId",
            $this->authHeader + ['Content-Type' => 'application/json'],
            json_encode(['nick' => $newNickname])
        );

        return $result !== null;
    }

    /**
     * https://discord.com/developers/docs/resources/guild#remove-guild-member - KICK_MEMBERS
     */
    private function kickMember(string $discordUserId): ?string
    {
        return $this->apiRequest(
            'DELETE',
            "https://discord.com/api/guilds/$this->serverId/members/$discordUserId",
            $this->authHeader
        );
    }

    private function apiRequest(string $method, string $url, array $headers = [], ?string $body = null): ?string
    {
        // https://discord.com/developers/docs/topics/rate-limits

        // Simple rate limit implementation, sleep if any bucket is too low
        $resetAfter = 0;
        $remaining = PHP_INT_MAX;
        foreach ($this->rateLimits as /*$bucket =>*/ $limit) {
            $resetAfter = ceil(max($resetAfter, $limit['time'] + ($limit['resetAfter'] + 0.01) - microtime(true)));
            $remaining = min($remaining, $limit['remaining']);
        }
        if ($remaining < 1 && $resetAfter > 0) {
            $this->log("Rate limit: remaining < 1, sleeping $resetAfter second(s)");
            sleep($resetAfter);
        }

        $result = $this->sendRequest($method, $url, $headers, $body);

        // Store rate limit info, do not use X-RateLimit-Reset in case the local time is wrong.
        $rateLimitRemaining = $this->lastRequestResult->getHeader(self::RATE_LIMIT_REMAIN)[0] ?? '';
        $rateLimitResetAfter = $this->lastRequestResult->getHeader(self::RATE_LIMIT_RESET_AFTER)[0] ?? '';
        $rateLimitBucket = $this->lastRequestResult->getHeader(self::RATE_LIMIT_BUCKET)[0] ?? '';
        if (!empty($rateLimitRemaining) && !empty($rateLimitResetAfter) && !empty($rateLimitBucket)) {
            $this->rateLimits[$rateLimitBucket] = [
                'remaining' => (int)$rateLimitRemaining,
                'resetAfter' => (float)$rateLimitResetAfter,
                'time' => microtime(true),
            ];
        }
        if ($this->lastRequestErrorCode === 429) {
            $parsedBody = json_decode($this->lastRequestErrorBody, true);
            if (isset($parsedBody['retry_after'])) {
                $this->rateLimits['http429'] = [
                    'remaining' => 0,
                    'resetAfter' => (float)$parsedBody['retry_after'],
                    'time' => microtime(true),
                ];
            }
        }

        return $result;
    }

    private function sendRequest(string $method, string $url, array $headers = [], ?string $body = null): ?string
    {
        #$this->log([$method, $url, $headers, $body], LogLevel::DEBUG);

        $this->lastRequestErrorCode = 0;
        $this->lastRequestErrorBody = '';

        $request = new Request($method, $url, $headers, $body);
        try {
            $this->lastRequestResult = $this->getHttpClient()->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logException($e, __FUNCTION__);
            return null;
        }

        try {
            $body = $this->lastRequestResult->getBody()->getContents();
        } catch (RuntimeException $e) {
            $this->logException($e, __FUNCTION__);
            return null;
        }

        $code = $this->lastRequestResult->getStatusCode();
        if ($code < 200 || $code > 299) {
            $requestHeaders = $headers;
            if (isset($requestHeaders['Authorization'])) {
                $authValues = explode(' ', $requestHeaders['Authorization']); // value is e.g. "Bot abc123"
                $requestHeaders['Authorization'] = $authValues[0] . ' ****';
            }
            $responseHeaders = $this->getDebugHeaders($this->lastRequestResult->getHeaders());
            $this->logError(
                "Request: $method $url " . json_encode($requestHeaders) . ', ' .
                "Response: $code $body " . json_encode($responseHeaders)
            );
            $this->lastRequestErrorCode = $code;
            $this->lastRequestErrorBody = $body;
            return null;
        }

        return $body;
    }

    /**
     * @throws Exception
     */
    private function getPDO(): PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    $_ENV['NEUCORE_DISCORD_PLUGIN_DB_DSN'],
                    $_ENV['NEUCORE_DISCORD_PLUGIN_DB_USERNAME'],
                    $_ENV['NEUCORE_DISCORD_PLUGIN_DB_PASSWORD']
                );
            } catch (PDOException $e) {
                $this->logException($e, __FUNCTION__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    private function getHttpClient(): ClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'headers' => [
                    'User-Agent' => 'Neucore Discord Plugin (https://github.com/tkhamez/'.self::PLUGIN_NAME.')',
                ],
            ]);
        }
        return $this->httpClient;
    }

    private function getDebugHeaders(array $headers): array
    {
        $result = [];
        $keep = [
            strtolower('Retry-After'),
            strtolower('X-RateLimit-Global'),
            strtolower('X-RateLimit-Limit'),
            strtolower(self::RATE_LIMIT_REMAIN),
            strtolower('X-RateLimit-Reset'),
            strtolower(self::RATE_LIMIT_RESET_AFTER),
            strtolower(self::RATE_LIMIT_BUCKET),
        ];
        foreach ($headers as $name => $header) {
            if (in_array(strtolower($name), $keep)) {
                $result[$name] = $header[0] ?? '';
            }
        }
        return $result;
    }

    private function logException(Throwable $e, string $function): void
    {
        $this->logError($e->getMessage() . " in $function() at " . __FILE__);
    }

    private function logError(string $message): void
    {
        $this->log($message, LogLevel::ERROR);
    }

    /**
     * @param string|array|object $message
     */
    private function log($message, string $logLevel = LogLevel::INFO): void
    {
        if (!is_scalar($message)) {
            $message = print_r($message, true);
        }
        $this->logger->log($logLevel, self::PLUGIN_NAME." ($this->serverId): $message");
    }
}
