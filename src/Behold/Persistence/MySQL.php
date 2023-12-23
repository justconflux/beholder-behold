<?php

namespace Beholder\Modules\Behold\Persistence;

use Beholder\Common\Irc\Channel;
use Beholder\Common\Irc\Nick;
use Beholder\Common\Persistence\Exceptions\PdoPersistenceException;
use Beholder\Common\Persistence\Exceptions\PersistenceException;
use Beholder\Common\Persistence\Pdo;
use Beholder\Modules\Behold\Stats\ActiveTimeTotals;
use Beholder\Modules\Behold\Stats\QuoteBuffer;
use Beholder\Modules\Behold\Stats\StatTotals;
use Beholder\Modules\Behold\Stats\TextStatsBuffer;
use PDOStatement;

class MySQL extends Pdo implements PersistenceInterface
{
    protected ?array $channelsCache = null;

    public function install(): void
    {
        $this->withDatabaseConnection(function () {
            $this->checkSchema('beholder_schema_version');
        });
    }

    protected function getSchema() : array
    {
        return [
            1 => [
                <<< EOD
                CREATE TABLE `behold_canonical_nicks` (
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  `regular_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_line_counts` (
                  `type` int(11) NOT NULL DEFAULT '0',
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `total` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`type`, `channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_active_times` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `hour` tinyint(2) NOT NULL DEFAULT '0',
                  `total` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`channel_id`,`nick`,`hour`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_channels` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `channel` varchar(255) UNIQUE NOT NULL DEFAULT '',
                  `created_at` int NOT NULL,
                  `updated_at` int NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_ignored_nicks` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`,`channel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_ignored_nicks_global` (
                  `normalized_nick` varchar(255) NOT NULL DEFAULT '',
                  PRIMARY KEY (`normalized_nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_latest_quote` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `quote` varchar(512) NOT NULL DEFAULT '',
                  PRIMARY KEY (`channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
                <<< EOD
                CREATE TABLE `behold_text_stats` (
                  `channel_id` int(11) NOT NULL DEFAULT '0',
                  `nick` varchar(255) NOT NULL DEFAULT '',
                  `messages` int(11) NOT NULL DEFAULT '0',
                  `words` int(11) NOT NULL DEFAULT '0',
                  `chars` int(11) NOT NULL DEFAULT '0',
                  `avg_words` decimal(5,2) NOT NULL DEFAULT '0.00',
                  `avg_chars` decimal(5,2) NOT NULL DEFAULT '0.00',
                  PRIMARY KEY (`channel_id`,`nick`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD,
            ],
            2 => [
                <<< EOD
                ALTER TABLE `behold_canonical_nicks`
                  RENAME COLUMN `regular_nick` TO `canonical_nick`;
                EOD,
                <<< EOD
                ALTER TABLE `behold_ignored_nicks`
                  ADD COLUMN `canonical_nick` varchar(255) NOT NULL DEFAULT ''
                  AFTER `normalized_nick`;
                EOD,
                <<< EOD
                UPDATE `behold_ignored_nicks`
                SET `canonical_nick`=`normalized_nick`;
                EOD,
                <<< EOD
                ALTER TABLE `behold_ignored_nicks_global`
                  ADD COLUMN `canonical_nick` varchar(255) NOT NULL DEFAULT ''
                  AFTER `normalized_nick`;
                EOD,
                <<< EOD
                UPDATE `behold_ignored_nicks_global`
                SET `canonical_nick`=`normalized_nick`;
                EOD,
                <<< EOD
                ALTER TABLE `behold_channels`
                  RENAME COLUMN `channel` TO `normalized_channel`;
                EOD,
                <<< EOD
                ALTER TABLE `behold_channels`
                  ADD COLUMN `canonical_channel` varchar(255) NOT NULL DEFAULT ''
                  AFTER `normalized_channel`;
                EOD,
                <<< EOD
                UPDATE `behold_channels`
                SET `canonical_channel`=`normalized_channel`;
                EOD,
            ],
        ];
    }

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList,
        array $ignoreList
    ) : bool
    {
        $statements = [];

        $recordedCanonicalNicks = [];

        foreach ($lineStatsBuffer->getData() as $type => $channels) {
            foreach ($channels as $chan => $nicks) {
                foreach ($nicks as $nick => $quantity) {
                    if (! in_array($nick, $recordedCanonicalNicks, true)) {
                        $statements[] = $this->buildNickNormalizationInsertQuery($nick);
                        $recordedCanonicalNicks[] = $nick;
                    }

                    $statements[] = [
                        <<< EOD
                        INSERT INTO `behold_line_counts`
                        SET `type` = :type,
                            `channel_id` = :channel_id,
                            `nick` = :nickname,
                            `total` = :quantity
                        ON DUPLICATE KEY UPDATE
                            `total` = `total` + :quantity;
                        EOD,
                        [
                            'type' => $type,
                            'channel_id' => fn () => $this->getChannelId($chan),
                            'nickname' => (new Nick($nick))->normalize(),
                            'quantity' => $quantity,
                        ]
                    ];
                }
            }
        }

        foreach ($textStatsBuffer->data() as $nick => $channels) {
            foreach ($channels as $chan => $totals) {
                if (! in_array($nick, $recordedCanonicalNicks, true)) {
                    $statements[] = $this->buildNickNormalizationInsertQuery($nick);
                    $recordedCanonicalNicks[] = $nick;
                }

                $statements[] = [
                    <<< EOD
                    INSERT INTO `behold_text_stats`
                    SET `channel_id` = :channel_id,
                        `nick` = :nickname,
                        `messages` = :quantity_messages,
                        `words` = :quantity_words,
                        `chars` = :quantity_chars,
                        `avg_words` = :quantity_words / :quantity_messages,
                        `avg_chars` = :quantity_chars / :quantity_messages
                    ON DUPLICATE KEY UPDATE
                        `messages` = `messages` + :quantity_messages,
                        `words` = `words` + :quantity_words,
                        `chars` = `chars` + :quantity_chars,
                        `avg_words` = `words` / `messages`,
                        `avg_chars` = `chars` / `messages`;
                    EOD,
                    [
                        'channel_id' => fn () => $this->getChannelId($chan),
                        'nickname' => (new Nick($nick))->normalize(),
                        'quantity_messages' => $totals['messages'],
                        'quantity_words' => $totals['words'],
                        'quantity_chars' => $totals['chars'],
                    ],
                ];
            }
        }

        foreach ($activeTimesBuffer->data() as $nick => $channels) {
            foreach ($channels as $chan => $hours) {
                foreach ($hours as $hour => $quantity) {
                    if (! in_array($nick, $recordedCanonicalNicks, true)) {
                        $statements[] = $this->buildNickNormalizationInsertQuery($nick);
                        $recordedCanonicalNicks[] = $nick;
                    }

                    $statements[] = [
                        <<< EOD
                        INSERT INTO `behold_active_times`
                        SET `channel_id` = :channel_id,
                            `nick` = :nickname,
                            `hour` = :hour,
                            `total` = :quantity
                        ON DUPLICATE KEY UPDATE
                            `total` = `total` + :quantity;
                        EOD,
                        [
                            'channel_id' => fn () => $this->getChannelId($chan),
                            'nickname' => (new Nick($nick))->normalize(),
                            'hour' => $hour,
                            'quantity' => $quantity,
                        ],
                    ];
                }
            }
        }

        foreach ($latestQuotesBuffer->data() as $nick => $channels) {
            foreach ($channels as $chan => $quote) {
                if (! in_array($nick, $recordedCanonicalNicks, true)) {
                    $statements[] = $this->buildNickNormalizationInsertQuery($nick);
                    $recordedCanonicalNicks[] = $nick;
                }

                $statements[] = [
                    <<< EOD
                    INSERT INTO `behold_latest_quote`
                        SET `channel_id` = :channel_id,
                            `nick` = :nickname,
                            `quote` = :quote
                        ON DUPLICATE KEY UPDATE
                            `quote` = :quote;
                    EOD,
                    [
                        'channel_id' => fn () => $this->getChannelId($chan),
                        'nickname' => (new Nick($nick))->normalize(),
                        'quote' => $quote,
                    ],
                ];
            }
        }

        $this->withDatabaseConnection(
            function (\PDO $connectionResource)
            use (
                $statements,
                $channelList,
                $ignoreList
            ) {
                $this->synchronizeChannelList($connectionResource, $channelList);
                $this->synchronizeIgnoreList($connectionResource, $ignoreList);

                $pdoStatements = [];

                foreach ($statements as [$sql, $params]) {
                    $pdoStatements[] = array_reduce(
                        array_keys($params),
                        function (PDOStatement $statement, $key) use ($params) {
                            $value = is_callable($params[$key])
                                ? $params[$key]()
                                : $params[$key];
                            $statement->bindValue($key, $value);
                            return $statement;
                        },
                        $connectionResource->prepare($sql),
                    );
                }

                if (count($pdoStatements) === 0) {
                    $this->logger->debug('Nothing to write.');
                    return;
                } else {
                    $this->logger->debug(
                        sprintf(
                            'Writing to database (%s update%s)...',
                            count($pdoStatements),
                            count($pdoStatements) === 1 ? '' : 's',
                        ),
                    );
                }

                $this->withTransaction(
                    function (\PDO $resourceConnection) use ($pdoStatements) {
                        foreach ($pdoStatements as $pdoStatement) {
                            if (! $pdoStatement->execute()) {
                                throw new PdoPersistenceException($resourceConnection);
                            }
                        }
                    }
                );

                $this->logger->debug('Database write completed.');
            }
        );

        return true;
    }

    /**
     * @param \PDO $connectionResource
     * @param array<Channel> $channelList
     * @return void
     */
    protected function synchronizeChannelList(
        \PDO $connectionResource,
        array $channelList,
    ): void {
        $statements = [];

        foreach ($this->getChannels() as $cachedChannelId => $cachedChannel) {
            if ($cachedChannel->isNotIn($channelList)) {
                // This channel has been removed since we last persisted
                foreach (
                    [
                        'DELETE FROM `behold_line_counts` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_active_times` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_latest_quote` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_text_stats` WHERE `channel_id` = :channel_id',
                        'DELETE FROM `behold_channels` WHERE `id` = :channel_id',
                    ] as $sql
                ) {
                    $statements[] = $connectionResource
                        ->prepare($sql)
                        ->bindValue('channel_id', $cachedChannelId);
                }
            }
        }

        $now = time();

        foreach ($channelList as $channel) {
            $statement = $connectionResource
                ->prepare(
                    <<< EOD
                    INSERT INTO `behold_channels`
                    SET
                        `normalized_channel` = :normalized_channel,
                        `canonical_channel` = :canonical_channel,
                        `created_at` = :now,
                        `updated_at` = :now
                    ON DUPLICATE KEY UPDATE
                        `updated_at` = :now;
                    EOD
                );

            $statement->bindValue('normalized_channel', $channel->normalize());
            $statement->bindValue('canonical_channel', (string) $channel);
            $statement->bindValue('now', $now);

            $statements[] = $statement;
        }

        foreach ($statements as $statement) {
            if (! $statement->execute()) {
                throw new PdoPersistenceException($connectionResource);
            }
        }

        if (count($statements) > 0) {
            $this->channelsCache = null;
        }
    }

    /**
     * @param \PDO $connectionResource
     * @param array<array<Nick>> $actualList
     * @return void
     */
    protected function synchronizeIgnoreList(\PDO $connectionResource, array $actualList): void
    {
        $dbList = $this->fetchIgnoredNicks($connectionResource);

        $statements = [];

        foreach ($actualList['global'] as $actualListNick) {
            if ($actualListNick->isNotIn($dbList['global'])) {
                $statement = $connectionResource->prepare(
                    <<< EOD
                    INSERT INTO `behold_ignored_nicks_global`
                    SET `normalized_nick` = :nickname
                    EOD,
                );
                $statement->bindValue('nickname', $actualListNick);
                $statements[] = $statement;
            }
        }

        foreach ($dbList['global'] as $dbListNick) {
            if ($dbListNick->isNotIn($actualList['global'])) {
                $statement = $connectionResource->prepare(
                    <<< EOD
                    DELETE FROM `behold_ignored_nicks_global`
                    WHERE `normalized_nick` = :nickname
                    EOD,
                );
                $statement->bindValue('nickname', $dbListNick);
                $statements[] = $statement;
            }
        }

        unset($dbList['global']);
        unset($actualList['global']);

        foreach ($actualList as $channel => $actualListNicks) {
            foreach ($actualListNicks as $actualListNick) {
                if ($actualListNick->isNotIn($dbList[$channel] ?? [])) {
                    $statement = $connectionResource->prepare(
                        <<< EOD
                        INSERT INTO `behold_ignored_nicks`
                        SET `normalized_nick` = :nickname,
                        `channel_id` = :channel_id
                        EOD,
                    );

                    $statement->bindValue('nickname', $actualListNick);
                    $statement->bindValue('channel_id', $this->getChannelId($channel));

                    $statements[] = $statement;
                }
            }
        }

        foreach ($dbList as $channel => $dbListNicks) {
            foreach ($dbListNicks as $dbListNick) {
                if ($dbListNick->isNotIn($actualList[$channel] ?? [])) {
                    $statement = $connectionResource->prepare(
                        <<< EOD
                        DELETE FROM `behold_ignored_nicks`
                        WHERE `normalized_nick` = :nickname
                        AND `channel_id` = :channel_id
                        EOD,
                    );

                    $statement->bindValue('nickname', $dbListNick);
                    $statement->bindValue('channel_id', $this->getChannelId($channel));

                    $statements[] = $statement;
                }
            }
        }

        foreach ($statements as $statement) {
            if (! $statement->execute()) {
                throw new PdoPersistenceException($connectionResource);
            }
        }
    }

    public function getChannelId($channel) : int
    {
        $channel = strtolower($channel);

        $result = array_search($channel, $this->getChannels(), true);

        if ($result === false) {
            throw new PersistenceException('No such channel');
        }

        return (int) $result;
    }

    protected function simpleQuery(\PDO $connectionResource, string $query): PDOStatement
    {
        $result = $connectionResource->query($query);

        if (false === $result) {
            throw new PdoPersistenceException($connectionResource);
        }

        return $result;
    }

    /**
     * @param \PDO $connectionResource
     * @return array<array<Nick>>
     */
    protected function fetchIgnoredNicks(\PDO $connectionResource): array
    {
        $ignoredNicks = [
            'global' => [],
        ];

        // Channel level ignores...
        $result = $this->simpleQuery(
            $connectionResource,
            <<< EOD
            SELECT ig.`canonical_nick` AS `nick`, c.`canonical_channel`
            FROM `behold_ignored_nicks` ig
            INNER JOIN `behold_channels` c ON c.`id` = ig.`channel_id`
            EOD,
        );

        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            $ignoredNicks[(new Channel($row['canonical_channel']))->normalize()][] = new Nick($row['nick']);
        }

        // Global ignores...
        $result = $this->simpleQuery(
            $connectionResource,
            <<< EOD
            SELECT `canonical_nick` AS `nick`
            FROM `behold_ignored_nicks_global`
            EOD,
        );

        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            $ignoredNicks['global'][] = new Nick($row['nick']);
        }

        return $ignoredNicks;
    }

    /**
     * @return array<Channel>
     */
    public function getChannels() : array
    {
        if (is_null($this->channelsCache)) {
            $this->channelsCache = $this->fetchChannelsFromDatabase();
        }

        return $this->channelsCache;
    }

    public function getIgnoredNicks(): array
    {
        return $this->withDatabaseConnection(function (\PDO $connectionResource) {
            return $this->fetchIgnoredNicks($connectionResource);
        });
    }

    protected function fetchChannelsFromDatabase()
    {
        return $this
            ->withDatabaseConnection(function (\PDO $connectionResource) {
                $channels = [];

                $result = $connectionResource
                    ->query('SELECT `id`, `canonical_channel` FROM `behold_channels`');

                if (false === $result) {
                    throw new PdoPersistenceException($connectionResource);
                }

                while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
                    $channels[(int) $row['id']] = new Channel($row['canonical_channel']);
                }

                return $channels;
            });
    }

    protected function buildNickNormalizationInsertQuery(string $nick): array
    {
        return [
            <<< EOD
            INSERT INTO `behold_canonical_nicks`
            SET `normalized_nick` = :normalized_nick,
                `canonical_nick` = :canonical_nick
            ON DUPLICATE KEY UPDATE
                `canonical_nick` = :canonical_nick;
            EOD,
            [
                'normalized_nick' => (new Nick($nick))->normalize(),
                'canonical_nick' => $nick,
            ],
        ];
    }
}
