<?php

namespace Beholder\Modules\Behold\Stats;

class MonologueMonitor
{
    private array $lastSpokeNick = [];

    private array $lastSpokeCount = [];

    function spoke($channel, $nick): void
    {
        if (isset($this->lastSpokeNick[$channel]) && $this->lastSpokeNick[$channel] == $nick) {
            $this->lastSpokeCount[$channel]++;
        } else {
            $this->lastSpokeNick[$channel] = $nick;
            $this->lastSpokeCount[$channel] = 1;
        }
    }

    function isBecomingMonologue($channel): bool
    {
        return $this->lastSpokeCount[$channel] == 5;
    }

    public function purgeChannel($channel): void
    {
        unset($this->lastSpokeNick[$channel]);
        unset($this->lastSpokeCount[$channel]);
    }
}
