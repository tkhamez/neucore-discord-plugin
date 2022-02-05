<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use PDO;
use PDOException;

class CoreAccount
{
    private Logger $logger;

    private Config $config;

    private ?PDO $pdo = null;

    public function __construct(Logger $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * @param int[] $characterIds
     * @throws Exception
     */
    public function fetchPlayerAccount(array $characterIds, int $playerId): ?ServiceAccountData
    {
        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
        // Will return max 1 account because all characters from the parameter are on the same account.
        // Limit by player_id to exclude characters that were moved to another account.
            "SELECT character_id, player_id, username, discriminator, member_status
            FROM {$this->config->tableName}
            WHERE character_id IN ($placeholders) AND player_id = ?"
        );
        try {
            $stmt->execute(array_merge($characterIds, [$playerId]));
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception();
        }
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (isset($accounts[0])) {
            return new ServiceAccountData(
                (int)$accounts[0]['character_id'],
                $accounts[0]['username'] .
                    ($accounts[0]['username'] !== Service::USERNAME_NA ? '#' . $accounts[0]['discriminator'] : ''),
                null,
                null,
                $accounts[0]['member_status']
            );
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function createAccount(int $characterId, int $playerId, string $status, string $username): void
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "INSERT INTO {$this->config->tableName} 
                (character_id, player_id, member_status, username, created, updated)
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
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception();
        }
    }

    /**
     * @return array<string, int>|null
     * @throws Exception
     */
    public function getMemberData(int $playerId): ?array
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "SELECT character_id, discord_id FROM {$this->config->tableName} WHERE player_id = ?"
        );
        try {
            $stmt->execute([$playerId]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception();
        }
        $resultAccount = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return isset($resultAccount[0]) ? [
            'characterId' => (int)$resultAccount[0]['character_id'],
            'discordId' => (int)$resultAccount[0]['discord_id'],
        ] : null;
    }

    /**
     * @throws Exception
     */
    public function deleteAccount(int $playerId): void
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("DELETE FROM {$this->config->tableName} WHERE player_id = ?");
        try {
            $stmt->execute([$playerId]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception('Failed to delete service account.');
        }
    }

    /**
     * @throws Exception
     */
    public function updateCharacterId(CoreCharacter $mainCharacter): void
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "UPDATE {$this->config->tableName} SET character_id = ?, updated = NOW() WHERE player_id = ?"
        );
        try {
            $stmt->execute([$mainCharacter->id, $mainCharacter->playerId]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception('Failed to update service account with new character ID.');
        }
    }

    /**
     * @param int|null $playerId Must be provided if $discordIds is null.
     * @param int[]|null $discordIds Must be provided if $playerId is null, will be ignored if $playerId
     *        is not null.
     * @param string $status
     * @throws Exception
     */
    public function updateAccountStatus(?int $playerId, ?array $discordIds, string $status): void
    {
        $params = [$status];
        if ($playerId) {
            /** @noinspection SqlResolve */
            $sql = "UPDATE {$this->config->tableName} SET member_status = ?, updated = NOW() WHERE player_id = ?";
            $params[] = $playerId;
        } elseif (!empty($discordIds)) {
            $placeholders = implode(',', array_fill(0, count($discordIds), '?'));
            /** @noinspection SqlResolve */
            $sql = "UPDATE {$this->config->tableName}
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
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception();
        }
    }

    /**
     * @throws Exception
     */
    public function updateMemberData(string $memberUsername, string $memberDiscriminator, int $playerId): void
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "UPDATE {$this->config->tableName} 
                SET member_status = ?, username = ?, discriminator = ?, updated = NOW() 
                WHERE player_id = ?"
        );
        try {
            $stmt->execute([
                ServiceAccountData::STATUS_ACTIVE,
                $memberUsername,
                $memberDiscriminator,
                $playerId
            ]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception('Failed to update username/discriminator.');
        }
    }

    /**
     * @param int[] $discordUserIds
     * @return int[]
     * @throws Exception
     */
    public function getDiscordIds(array $discordUserIds): array
    {
        $localDiscordIds = [];
        foreach (array_chunk($discordUserIds, 500) as $chunks) {
            $placeholders = implode(',', array_fill(0, count($chunks), '?'));
            /** @noinspection SqlResolve */
            $stmt = $this->getPDO()->prepare(
                "SELECT discord_id FROM {$this->config->tableName} WHERE discord_id IN ($placeholders)"
            );
            try {
                $stmt->execute($chunks);
            } catch (PDOException $e) {
                $this->logger->logException($e, __FUNCTION__);
                return [];
            }
            $localDiscordIds = array_merge(
                $localDiscordIds,
                array_map(function (array $row) {
                    return (int)$row['discord_id'];
                }, $stmt->fetchAll(PDO::FETCH_ASSOC))
            );
        }
        return $localDiscordIds;
    }

    /**
     * @return int[]
     * @throws Exception
     */
    public function fetchAllAccounts(): array
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "SELECT player_id FROM {$this->config->tableName} WHERE member_status = ? ORDER BY updated"
        );
        try {
            $stmt->execute([ServiceAccountData::STATUS_ACTIVE]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            throw new Exception();
        }
        return array_map(function (array $row) {
            return (int)$row['player_id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @throws Exception
     */
    public function deleteOtherAccounts(int $discordUserId, int $playerId): bool
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "DELETE FROM {$this->config->tableName} WHERE discord_id = ? AND player_id <> ?"
        );
        try {
            $stmt->execute([$discordUserId, $playerId]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     */
    public function accountExists(int $playerId): ?bool
    {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare("SELECT player_id FROM {$this->config->tableName} WHERE player_id = ?");
        try {
            $stmt->execute([$playerId]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            return null;
        }
        if ($stmt->rowCount() > 0) {
            return true;
        }
        return false;
    }

    /**
     * @throws Exception
     */
    public function updateAccount(
        CoreCharacter $coreCharacter,
        int $discordUserId,
        string $username,
        string $discriminator
    ): bool {
        /** @noinspection SqlResolve */
        $stmt = $this->getPDO()->prepare(
            "UPDATE {$this->config->tableName}
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
                ':discord_id' => $discordUserId,
                ':username' => $username,
                ':member_status' => ServiceAccountData::STATUS_ACTIVE,
                ':discriminator' => $discriminator,
                ':player_id' => $coreCharacter->playerId,
            ]);
        } catch (PDOException $e) {
            $this->logger->logException($e, __FUNCTION__);
            return false;
        }
        return true;
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
                $this->logger->logException($e, __FUNCTION__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }
}
