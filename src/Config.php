<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Symfony\Component\Yaml\Yaml;

class Config
{
    public string $tableName = '';

    public int $serverId = 0;

    /**
     * @var array<string, string>
     */
    public array $authHeader = [];

    public string $oAuthRedirectUri = '';

    public string $oAuthClientId = '';

    public string $oAuthClientSecret = '';

    /**
     * @var array<int, int[]>
     */
    public array $roleConfig = [];

    /**
     * @var array<int, int[]>
     */
    public array $channelConfig = [];

    /**
     * @var int[]
     */
    public array $doNotKick = [];

    public bool $disableKicks = false;

    public string $nickname = '';

    private Logger $logger;

    public function __construct(Logger $logger, string $configurationData)
    {
        $this->logger = $logger;

        $this->parse($configurationData);
    }

    public function parse(string $configurationData)
    {
        $config = Yaml::parse($configurationData);

        // required
        $this->tableName = (string)preg_replace(
            '/[^a-zA-Z0-9_]/', '',
            $config['TableName'] ?? '__missing_table_name__'
        );
        $this->serverId = (int)($config['ServerId'] ?? 0);
        $botToken = (string)($config['BotToken'] ?? '');
        $this->authHeader = ['Authorization' => "Bot $botToken"];
        $this->oAuthRedirectUri = (string)($config['OAuthRedirectUri'] ?? '');
        $this->oAuthClientId = (string)($config['OAuthClientId'] ?? '');
        $this->oAuthClientSecret = (string)($config['OAuthClientSecret'] ?? '');

        if (
            empty($this->tableName) ||
            empty($this->serverId) ||
            empty($botToken) ||
            empty($this->oAuthRedirectUri) ||
            empty($this->oAuthClientId) ||
            empty($this->oAuthClientSecret)
        ) {
            $this->logger->logError('Configuration is incomplete.');
        }

        // optional
        foreach ($config['Roles'] ?? [] as $roleId => $roleGroupIds) {
            $this->roleConfig[(int)$roleId] = array_map('intval', $roleGroupIds);
        }
        foreach ($config['Channels'] ?? [] as $channelId => $channelGroupIds) {
            $this->channelConfig[(int)$channelId] = array_map('intval', $channelGroupIds);
        }
        $this->doNotKick = array_map('intval', $config['DoNotKick'] ?? []);
        $this->disableKicks = (bool) ($config['DisableKicks'] ?? false);
        $this->nickname = (string)($config['Nickname'] ?? '');
    }
}
