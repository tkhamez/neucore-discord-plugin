<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\CoreGroup;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use Neucore\Plugin\ServiceConfiguration;
use Neucore\Plugin\ServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use stdClass;

class Service implements ServiceInterface
{
    public const PLUGIN_NAME = 'neucore-discord-plugin';

    public const USERNAME_NA = 'n/a';

    private Logger $logger;

    private ServiceConfiguration $configuration;

    private string $sessionStateKey;

    private Config $config;

    private CoreAccount $coreAccount;

    private DiscordServer $discordServer;

    /**
     * Cache of discord members to reduce API request.
     *
     * @var array<int, stdClass>
     */
    private array $discordMembers = [];

    /**
     * @var array<int, int[]>
     */
    private array $discordMemberRoles = [];

    /**
     * Cache of discord channel permissions to reduce API request.
     *
     * This property is updated when the channel is modified.
     *
     * @var array<int, stdClass>
     */
    private array $channels = [];

    public function __construct(LoggerInterface $logger, ServiceConfiguration $serviceConfiguration)
    {
        $this->logger = new Logger($logger, $serviceConfiguration->id);
        $this->configuration = $serviceConfiguration;

        $this->sessionStateKey = "__plugin_{$this->configuration->id}_state";
        $this->config = new Config($this->logger, $this->configuration->configurationData);
        $this->coreAccount = new CoreAccount($this->logger, $this->config);
        $this->discordServer = new DiscordServer($this->logger, $this->config);
    }

    /**
     * @throws Exception
     */
    public function getAccounts(array $characters): array
    {
        if (empty($characters)) {
            return [];
        }

        $characterIds = array_map(function (CoreCharacter $character) {
            return $character->id;
        }, $characters);

        $account = $this->coreAccount->fetchPlayerAccount($characterIds, $characters[0]->playerId);

        return $account ? [$account] : [];
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
        // actual account registration will be done in via request()

        $this->coreAccount->createAccount(
            $character->id,
            $character->playerId,
            ServiceAccountData::STATUS_NONMEMBER,
            self::USERNAME_NA
        );

        return new ServiceAccountData($character->id, self::USERNAME_NA);
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
     * @throws Exception
     */
    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        // variables
        $accountGroupIds = array_map(function (CoreGroup $group) {
            return $group->identifier;
        }, $groups);

        // Get member data of player from service account
        $resultAccount = $this->coreAccount->getMemberData($mainCharacter->playerId);
        $characterId = (int)$resultAccount['characterId'];
        $discordUserId = (int)$resultAccount['discordId'];
        $additionalLogInfo = '';
        if (php_sapi_name() === 'cli') {
            $additionalLogInfo = " (Discord user ID: $discordUserId, EVE character ID: $characterId)";
        }
        if (empty($discordUserId)) {
            return;
        }

        // Check if the player account still has a main character, kick if not
        if (
            $mainCharacter->id === 0 &&
            !in_array($discordUserId, $this->config->doNotKick) &&
            !$this->config->disableKicks
        ) {
            $result = $this->discordServer->kickMember($discordUserId);
            if ($result === null) { // error
                throw new Exception("Failed to kick$additionalLogInfo.");
            }
            $this->logger->log("Kicked $discordUserId (no main).");
            $this->coreAccount->deleteAccount($mainCharacter->playerId);
            return;
        }

        // Get member data from Discord (roles, nick, username, discriminator)
        $memberObject = $this->discordMembers[$discordUserId] ?? $this->discordServer->getMemberData($discordUserId);
        if (!is_object($memberObject)) {
            if ($this->discordServer->getLastRequestError() === DiscordServer::ERROR_UNKNOWN_MEMBER) {
                $this->coreAccount->updateAccountStatus(
                    $mainCharacter->playerId,
                    null,
                    ServiceAccountData::STATUS_NONMEMBER
                );
                return;
            }
            if (!isset($memberObject->roles)) {
                throw new Exception("Failed to read member roles$additionalLogInfo.");
            }
        }

        // Update status (to active), username and discriminator.
        $memberUsername = $memberObject->user->username ?? '';
        $memberDiscriminator = (string)$memberObject->user->discriminator ?? '';
        if (!empty($memberUsername) && !empty($memberDiscriminator)) {
            $this->coreAccount->updateMemberData($memberUsername, $memberDiscriminator, $mainCharacter->playerId);
        }

        // Check required groups and kick if needed.
        if (
            !empty($this->configuration->requiredGroups) &&
            empty(array_intersect($this->configuration->requiredGroups, $accountGroupIds)) &&
            !in_array($discordUserId, $this->config->doNotKick) &&
            !$this->config->disableKicks
        ) {
            $result = $this->discordServer->kickMember($discordUserId);
            if ($result === null) { // error
                throw new Exception("Failed to kick$additionalLogInfo.");
            }
            $this->logger->log("Kicked $discordUserId (missing required group).");
            $this->coreAccount->updateAccountStatus(
                $mainCharacter->playerId,
                null,
                ServiceAccountData::STATUS_NONMEMBER
            );
            return;
        }

        // Add and remove roles - populates $this->discordMemberRoles arrays
        $roleSuccess = $this->assignRoles($accountGroupIds, $memberObject->roles, $discordUserId);
        if (!$roleSuccess) {
            throw new Exception("Failed add/remove role(s)$additionalLogInfo.");
        }

        // Manage channel memberships
        $channelSuccess = $this->assignChannels($accountGroupIds, $discordUserId);
        if (!$channelSuccess) {
            throw new Exception("Failed add/remove channels(s)$additionalLogInfo.");
        }

        // Update Discord nickname
        if (
            count(array_intersect($this->discordMemberRoles[$discordUserId], $this->config->noNicknameChange)) === 0 &&
            !$this->discordServer->setNickname($discordUserId, $mainCharacter, $memberObject->nick)
        ) {
            throw new Exception("Failed to change nickname$additionalLogInfo.");
        }

        if ($mainCharacter->id === 0) {
            // Remove service account from empty Core account.
            $this->coreAccount->deleteAccount($mainCharacter->playerId);
        } elseif ($characterId !== $mainCharacter->id) {
            // Update character ID of service account if main changed.
            $this->coreAccount->updateCharacterId($mainCharacter);
        }
    }

    public function moveServiceAccount(int $toPlayerId, int $fromPlayerId): bool
    {
        return $this->coreAccount->moveAccount($fromPlayerId, $toPlayerId);
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
     * @throws Exception
     */
    public function getAllPlayerAccounts(): array
    {
        // Get all current server members
        $this->discordMembers = $this->discordServer->getMembers();
        $discordUserIds = array_keys($this->discordMembers);

        // Set service accounts active that exist in the local database from current server members
        // (that were added otherwise to the server)
        foreach (array_chunk($discordUserIds, 500) as $chunks) {
            $this->coreAccount->updateAccountStatus(null, $chunks, ServiceAccountData::STATUS_ACTIVE);
        }

        // Get all Discord member IDs that do not exist in the local database.
        $localDiscordIds = $this->coreAccount->getDiscordIds($discordUserIds);
        $missingDiscordIds = array_diff($discordUserIds, $localDiscordIds);

        if (!$this->config->disableKicks) {
            // Kick server members that do not exist in the local database
            foreach ($missingDiscordIds as $kickDiscordId) {
                if (!in_array($kickDiscordId, $this->config->doNotKick)) {
                    $result = $this->discordServer->kickMember($kickDiscordId);
                    if ($result !== null) {
                        $this->logger->log("Kicked $kickDiscordId (no Neucore service account).");
                    }
                }
            }
        } else {
            // Remove roles from members that do not exist in the local database.
            foreach ($missingDiscordIds as $removeRoleDiscordId) {
                $memberObject = $this->discordMembers[$removeRoleDiscordId] ?? null;
                if (is_object($memberObject)) { // Should always be true at this point
                    $roleSuccess = $this->assignRoles([], $memberObject->roles, $removeRoleDiscordId);
                    if (!$roleSuccess) {
                        $this->logger->log("Failed to update roles of $removeRoleDiscordId.");
                    }
                }
            }
        }

        // Fetch all accounts and return them
        return $this->coreAccount->fetchAllAccounts();
    }

    /**
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
            try {
                $this->updatePlayerAccount($coreCharacter, $groups);
            } catch (Exception $e) {
                // Ignore errors from the update here, just log.
                $this->logger->log(
                    "Request callback: Error from updatePlayerAccount() for Core player $coreCharacter->playerId: " .
                    $e->getMessage()
                );
            }
            return $response;
        }
        $response->getBody()->write('404 Not Found.');
        return $response->withStatus(404);
    }

    public function onConfigurationChange(): void
    {
    }

    /**
     * Adds and removes roles and stores them in self::$discordMemberRoles for each member.
     *
     * @param int[] $accountGroupIds
     * @param string[] $memberRoleIds
     */
    private function assignRoles(array $accountGroupIds, array $memberRoleIds, int $discordUserId): bool
    {
        $this->discordMemberRoles[$discordUserId] = $memberRoleIds;
        $rolesToManage = [];
        $shouldHaveRoles = [];
        foreach ($this->config->roleConfig as $roleId => $groupIds) {
            $rolesToManage[] = $roleId;
            if (!empty(array_intersect($groupIds, $accountGroupIds))) {
                $shouldHaveRoles[] = $roleId;
            }
        }
        $rolesToRemove = [];
        foreach ($memberRoleIds as $memberRoleId) {
            if (!in_array($memberRoleId, $shouldHaveRoles) && in_array($memberRoleId, $rolesToManage)) {
                $rolesToRemove[] = (int)$memberRoleId;
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
            $resultRemove = $this->discordServer->removeRole($discordUserId, $roleToRemove);
            if ($resultRemove !== null) {
                $hasRoleKey = array_search($roleToRemove, $this->discordMemberRoles[$discordUserId]);
                if ($hasRoleKey !== false) {
                    unset($this->discordMemberRoles[$discordUserId][$hasRoleKey]);
                }
                $this->logger->log("Removed role $roleToRemove from $discordUserId.");
            } else {
                $roleSuccess = false;
            }
        }
        foreach ($rolesToAdd as $roleToAdd) {
            $resultAdd = $this->discordServer->addRole($discordUserId, $roleToAdd);
            if ($resultAdd !== null) {
                $this->discordMemberRoles[$discordUserId][] = $roleToAdd;
                $this->logger->log("Added role $roleToAdd to $discordUserId.");
            } else {
                $roleSuccess = false;
            }
        }
        return $roleSuccess;
    }

    private function assignChannels(array $accountGroupIds, int $discordUserId): bool
    {
        $overallSuccess = true;
        foreach ($this->config->channelConfig as $channelId => $groupIds) {

            // Fetch channels
            if (!isset($this->channels[$channelId])) {
                $channel = $this->discordServer->getChannel($channelId);
                if ($channel) {
                    $this->channels[$channelId] = $channel;
                } else {
                    // error is already logged by the HTTP client
                    continue;
                }
            }

            // Add/remove?
            $shouldBeMember = !empty(array_intersect($groupIds, $accountGroupIds));
            $isMember = false;
            $updateChannel = false;
            foreach ($this->channels[$channelId]->permission_overwrites as $key => $permission) {
                if ($permission->type === 'member' && (int)$permission->id === $discordUserId) {
                    $isMember = true;
                    if (!$shouldBeMember) {
                        unset($this->channels[$channelId]->permission_overwrites[$key]);
                        $updateChannel = true;
                    }
                    break;
                }
            }
            if ($shouldBeMember && !$isMember) {
                // "View Channel" + "Connect" for voice channels, otherwise only "View Channel"
                $permissionBit = (int)$this->channels[$channelId]->type === 2 ? 1049600 : 1024;
                // https://discord.com/developers/docs/resources/channel#overwrite-object
                $this->channels[$channelId]->permission_overwrites[] = (object)[
                    'id' => $discordUserId,
                    'type' => 'member', // 1 per documentation, but the API returns "member"
                    'allow' => $permissionBit,
                    'deny' => 0,
                ];
                $updateChannel = true;
            }

            // Update channel
            if ($updateChannel) {
                $success = $this->discordServer->updateChannelPermission(
                    $channelId,
                    array_values($this->channels[$channelId]->permission_overwrites)
                );
                if ($success) {
                    if ($shouldBeMember) {
                        $this->logger->log("Added $discordUserId to channel $channelId.");
                    } else {
                        $this->logger->log("Removed $discordUserId from channel $channelId.");
                    }
                } else {
                    $overallSuccess = false;
                }
            }
        }

        return $overallSuccess;
    }

    private function requestLogin(ResponseInterface $response): ResponseInterface
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $_SESSION[$this->sessionStateKey] = bin2hex(random_bytes(16));

        $url = 'https://discord.com/api/oauth2/authorize' .
            '?client_id=' . $this->config->oAuthClientId .
            '&redirect_uri=' . urlencode($this->config->oAuthRedirectUri) .
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
        $accessToken = $this->discordServer->getAccessToken($code);
        if (!$accessToken) {
            return $this->buildCallbackResponse($response, 'Failed: No access token.');
        }

        // get user data
        $info = $this->discordServer->getUserData($accessToken);
        if (!$info) {
            return $this->buildCallbackResponse($response, 'Failed: Could not retrieve Discord user id.');
        }
        $userId = (int)$info['userId'];
        $username = $info['username'];
        $discriminator = $info['discriminator'];

        // Delete service account for this Discord user from any other Neucore account, should it exist
        if (!$this->coreAccount->deleteOtherAccounts($userId, $coreCharacter->playerId)) {
            return $this->buildCallbackResponse(
                $response,
                'Failed: Could not delete this Discord user from other Neucore accounts.'
            );
        }

        // Check if account exists
        $exists = $this->coreAccount->accountExists($coreCharacter->playerId);
        if ($exists === null) {
            return $this->buildCallbackResponse($response, 'Failed: Could not fetch local service account.');
        }
        if ($exists === false) {
            try {
                $this->coreAccount->createAccount(
                    $coreCharacter->id,
                    $coreCharacter->playerId,
                    ServiceAccountData::STATUS_ACTIVE,
                    $username
                );
            } catch (Exception) {
                // already logged
                return $this->buildCallbackResponse($response, 'Failed: Could not create local service account.');
            }
        }

        // Update record (also update character id because main could have changed).
        if (!$this->coreAccount->updateAccount($coreCharacter, $userId, $username, $discriminator)) {
            return $this->buildCallbackResponse($response, 'Failed: Could not update local service account');
        }

        // Add to server.
        if (!$this->discordServer->addMember($userId, $accessToken)) {
            if ($this->discordServer->getLastRequestError() === DiscordServer::ERROR_BANNED) {
                return $this->buildCallbackResponse($response, 'Failed: You are banned on this Discord server.');
            }
            return $this->buildCallbackResponse($response, 'Failed: Could not add member to Discord server.');
        } else {
            $this->logger->log("Added $userId to server.");
        }

        // Set nickname
        if (!$this->discordServer->setNickname($userId, $coreCharacter)) {
            return $this->buildCallbackResponse($response, 'Invitation successful, but failed to set nickname.');
        }

        return $this->buildCallbackResponse($response, 'Successfully added member to server.');
    }

    private function buildCallbackResponse(ResponseInterface $response, string $message): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/#Service/' . $this->configuration->id . '/?message=' . rawurlencode($message))
            ->withStatus(302);
    }
}
