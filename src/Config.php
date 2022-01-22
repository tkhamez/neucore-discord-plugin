<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Symfony\Component\Yaml\Yaml;

class Config
{
    public string $tableName = '';

    public string $serverId = '';

    /**
     * @var array<string, string>
     */
    public array $authHeader = [];

    public string $oAuthRedirectUri = '';

    public string $oAuthClientId = '';

    public string $oAuthClientSecret = '';

    /**
     * @var array<string, int[]>
     */
    public array $roleConfig = [];

    /**
     * @var array<string, int[]>
     */
    public array $channelConfig = [];

    /**
     * @var string[]
     */
    public array $doNotKick = [];

    public bool $disableKicks = false;

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
            $this->logger->logError('Configuration is incomplete.');
        }

        // optional
        foreach ($config['Roles'] ?? [] as $roleId => $roleGroupIds) {
            $this->roleConfig[(string)$roleId] = array_map('intval', $roleGroupIds);
        }
        foreach ($config['Channels'] ?? [] as $channelId => $channelGroupIds) {
            $this->channelConfig[(string)$channelId] = array_map('intval', $channelGroupIds);
        }
        $this->doNotKick = array_map('intval', $config['DoNotKick'] ?? []);
        $this->disableKicks = (bool) ($config['DisableKicks'] ?? false);
    }
}
