<?php

namespace Beholder\Modules\Behold\Commands;

use Beholder\Common\Commands\Command;
use Beholder\Common\Contracts\Invoker;
use Beholder\Common\Traits\FormatsIrcMessages;
use Beholder\Modules\Behold\BeholdModule;

class StatsUrlCommand extends Command
{
    use FormatsIrcMessages;

    public function __construct(protected BeholdModule $beholdModule)
    {
        parent::__construct(
            'url',
            'Gives the URL for the current channel\'s stats page.',
            '',
            $this->run(...),
        );
    }

    public function run(Invoker $invoker, string $arguments): void
    {
        if (! $invoker->commandSource()->isChannel()) {
            return;
        }

        $channel = $invoker->commandSource()->getChannel();

        $nick = $invoker->getNick();

        if (! $this->beholdModule->hasChannel($channel)) {
            $invoker->commandSource()->reply(
                "$nick: Sorry, there are no stats for this channel!",
            );
            return;
        }

        $urlPart = urlencode($channel->normalize());

        $invoker->commandSource()->reply(
            "$nick: Channel stats: https://justconflux.net/channels/$urlPart/stats",
        );
    }
}
