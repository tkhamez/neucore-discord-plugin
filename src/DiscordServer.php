<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Neucore\Plugin\CoreCharacter;
use stdClass;

class DiscordServer
{
    public const ERROR_UNKNOWN_MEMBER = 10007;

    public const ERROR_BANNED = 40007;

    private Config $config;

    private HttpClient $httpClient;

    private ?int $lastRequestError = null;

    private string $baseUrl = 'https://discord.com/api';

    public function __construct(Logger $logger, Config $config)
    {
        $this->config = $config;
        $this->httpClient = new HttpClient($logger);
    }

    public function getLastRequestError(): ?int
    {
        return $this->lastRequestError;
    }

    /**
     * https://discord.com/developers/docs/resources/guild#remove-guild-member - KICK_MEMBERS
     */
    public function kickMember(string $discordUserId): ?string
    {
        $this->lastRequestError = null;

        return $this->httpClient->apiRequest(
            'DELETE',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$discordUserId",
            $this->config->authHeader
        );
    }

    /**
     * https://discord.com/developers/docs/resources/guild#get-guild-member - no permission
     */
    public function getMemberData(string $discordUserId): ?stdClass
    {
        $this->lastRequestError = null;

        $member = $this->httpClient->apiRequest(
            'GET',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$discordUserId",
            $this->config->authHeader
        );
        $memberObject = json_decode((string) $member);

        if (!is_object($memberObject)) {
            $lastErrorBody = json_decode($this->httpClient->getLastResponseErrorBody());
            if (
                $this->httpClient->getLastResponseErrorCode() === 404 &&
                isset($lastErrorBody->code) &&
                $lastErrorBody->code === self::ERROR_UNKNOWN_MEMBER
            ) {
                $this->lastRequestError = self::ERROR_UNKNOWN_MEMBER;
            }
            return null;
        }

        return $memberObject;
    }

    /**
     * https://discord.com/developers/docs/resources/guild#remove-guild-member-role - MANAGE_ROLES
     */
    public function removeRole(string $discordUserId, string $roleToRemove): ?string
    {
        $this->lastRequestError = null;

        return $this->httpClient->apiRequest(
            'DELETE',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$discordUserId/roles/$roleToRemove",
            $this->config->authHeader
        );
    }

    /**
     * https://discord.com/developers/docs/resources/guild#add-guild-member-role - MANAGE_ROLES
     */
    public function addRole(string $discordUserId, string $roleToAdd): ?string
    {
        $this->lastRequestError = null;

        return $this->httpClient->apiRequest(
            'PUT',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$discordUserId/roles/$roleToAdd",
            $this->config->authHeader
        );
    }

    /**
     * https://discord.com/developers/docs/resources/channel#get-channel - MANAGE_CHANNELS + channel member
     */
    public function getChannel(string $channelId): ?stdClass
    {
        $result = $this->httpClient->apiRequest(
            'GET',
            "$this->baseUrl/channels/$channelId",
            $this->config->authHeader
        );
        $object = json_decode((string)$result);
        return is_object($object) ? $object : null;
    }

    /**
     * https://discord.com/developers/docs/resources/channel#modify-channel - MANAGE_CHANNELS + channel member
     *
     * @param array<stdClass> $permissions
     */
    public function updateChannelPermission(string $channelId, array $permissions): bool
    {
        $result = $this->httpClient->apiRequest(
            'PATCH',
            "$this->baseUrl/channels/$channelId",
            $this->config->authHeader + ['Content-Type' => 'application/json'],
            json_encode(['permission_overwrites' => $permissions])
        );
        return $result !== null;
    }

    /**
     * https://discord.com/developers/docs/resources/guild#modify-guild-member - MANAGE_NICKNAMES
     */
    public function setNickname(string $userId, CoreCharacter $character, string $currentNickname = null): bool
    {
        $this->lastRequestError = null;

        $newNickname = "$character->name [$character->corporationTicker]";
        if ($currentNickname === $newNickname) {
            return true;
        }
        $result = $this->httpClient->apiRequest(
            'PATCH',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$userId",
            $this->config->authHeader + ['Content-Type' => 'application/json'],
            json_encode(['nick' => $newNickname])
        );

        return $result !== null;
    }

    /**
     * https://discord.com/developers/docs/resources/guild#list-guild-members - Server Members Intent
     *
     * @return array<string, stdClass>
     */
    public function getMembers(): array
    {
        $this->lastRequestError = null;

        $discordMembers = [];
        $limit = 500;
        $after = 0;
        while (true) {
            $membersResult = $this->httpClient->apiRequest(
                'GET',
                "$this->baseUrl/guilds/{$this->config->serverId}/members?limit=$limit&after=$after",
                $this->config->authHeader
            );
            $members = json_decode((string) $membersResult);
            if (!is_array($members)) { // request error
                break;
            }
            foreach ($members as $member) {
                if (!isset($member->user->bot) || !$member->user->bot) {
                    $discordMembers[$member->user->id] = $member; // cache for later use in updatePlayerAccount()
                }
                $after = max($after, $member->user->id);
            }
            if (count($members) < $limit) { // no more results
                break;
            }
        }
        return $discordMembers;
    }

    public function getAccessToken(string $code): ?string
    {
        $this->lastRequestError = null;

        $body = $this->httpClient->sendRequest(
            'POST',
            "$this->baseUrl/oauth2/token",
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $this->config->oAuthClientId,
                'client_secret' => $this->config->oAuthClientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config->oAuthRedirectUri,
            ])
        );
        $token = json_decode((string)$body, true);
        return $token['access_token'] ?? null;
    }

    public function getUserData(string $accessToken): ?array
    {
        $body = $this->httpClient->sendRequest(
            'GET',
            "$this->baseUrl/oauth2/@me",
            ['Authorization' => "Bearer $accessToken"]
        );
        $info = json_decode((string)$body, true);
        $userId = $info['user']['id'] ?? null;
        $username = $info['user']['username'] ?? null;
        $discriminator = $info['user']['discriminator'] ?? null;
        if (!$userId || !$username || !$discriminator) {
            return null;
        } else {
            return [
                'userId' => $userId,
                'username' => $username,
                'discriminator' => $discriminator,
            ];
        }
    }

    /**
     * https://discord.com/developers/docs/resources/guild#add-guild-member -
     *     CREATE_INSTANT_INVITE + user token with "guilds.join" scope
     */
    public function addMember(string $userId, string $accessToken): bool
    {
        $this->lastRequestError = null;

        $body = $this->httpClient->apiRequest(
            'PUT',
            "$this->baseUrl/guilds/{$this->config->serverId}/members/$userId",
            $this->config->authHeader + ['Content-Type' => 'application/json'],
            json_encode(['access_token' => $accessToken])
        );

        if ($body === null) {
            $lastErrorBody = json_decode($this->httpClient->getLastResponseErrorBody());
            if (
                $this->httpClient->getLastResponseErrorCode() === 403 &&
                isset($lastErrorBody->code) &&
                $lastErrorBody->code === 40007 // banned
            ) {
                $this->lastRequestError = self::ERROR_BANNED;
            }
            return false;
        }

        return true;
    }
}
