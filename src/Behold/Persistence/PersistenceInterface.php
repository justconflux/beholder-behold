<?php

namespace Beholder\Modules\Behold\Persistence;

use Beholder\Common\Irc\Channel;
use Beholder\Common\Irc\Nick;
use Beholder\Modules\Behold\Stats\ActiveTimeTotals;
use Beholder\Modules\Behold\Stats\QuoteBuffer;
use Beholder\Modules\Behold\Stats\StatTotals;
use Beholder\Modules\Behold\Stats\TextStatsBuffer;

interface PersistenceInterface
{
    public function install(): void;

    /**
     * @param StatTotals $lineStatsBuffer
     * @param TextStatsBuffer $textStatsBuffer
     * @param ActiveTimeTotals $activeTimesBuffer
     * @param QuoteBuffer $latestQuotesBuffer
     * @param array<Channel> $channelList
     * @param array<array<Nick>> $ignoreList
     * @return bool
     */
    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList,
        array $ignoreList
    ) : bool;
}
