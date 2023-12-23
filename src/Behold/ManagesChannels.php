<?php

namespace Beholder\Modules\Behold;

use Beholder\Common\Irc\Channel;

interface ManagesChannels
{
    public function addChannel(Channel $channel): void;

    public function removeChannel(Channel $channel): void;

    /**
     * @return array<string>
     */
    public function getChannels(): array;

    public function matchChannelNames(string $a, string $b): bool;

    public function hasChannel(Channel $channel): bool;
}
