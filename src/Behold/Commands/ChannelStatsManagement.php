<?php

namespace Beholder\Modules\Behold\Commands;

use Beholder\Common\Commands\Command;
use Beholder\Common\Contracts\CommandNamespace;
use Beholder\Common\Contracts\Invoker;
use Beholder\Common\Irc\Channel;
use Beholder\Modules\Behold\ManagesChannels;

class ChannelStatsManagement
{
    public function __construct(
        protected ManagesChannels $channelManager,
    ) {}

    public function registerCommands(CommandNamespace $namespace): void
    {
        $namespace->registerCommand(new Command(
            'behold',
            'Start logging stats for a channel',
            '<channel>',
            $this->handleBehold(...),
        ));

        $namespace->registerCommand(new Command(
            'disregard',
            'Stop logging stats for a channel',
            '<channel>',
            $this->handleDisregard(...),
        ));

        $namespace->registerCommand(new Command(
            'list',
            'List beheld channels',
            '',
            $this->handleList(...),
        ));
    }

    protected function handleBehold(Invoker $user, string $rawChannel): void
    {
        if ($user->isAdmin() === false) {
            return;
        }

        $commandSource = $user->commandSource();
        // TODO: We need to do some validation of $rawChannel
        $channel = new Channel($rawChannel);

        if ($this->channelManager->hasChannel($channel)) {
            $commandSource->reply("I'm already recording stats for $channel");
            return;
        }

        $this->channelManager->addChannel($channel);

        $commandSource->reply("Okay, recording stats for $channel");
    }

    protected function handleDisregard(Invoker $user, string $rawChannel): void
    {
        if ($user->isAdmin() === false) {
            return;
        }

        $commandSource = $user->commandSource();
        $channel = new Channel($rawChannel);

        if (! $this->channelManager->hasChannel($channel)) {
            $commandSource->reply("I already don't record stats for $channel");
            return;
        }

        $this->channelManager->removeChannel($channel);

        $commandSource->reply("Okay, stopped recording stats for $channel");
    }

    protected function handleList(Invoker $user): void
    {
        $channels = $this->channelManager->getChannels();

        natcasesort($channels);

        if (count($channels) > 1) {
            [$b, $a] = [array_pop($channels), array_pop($channels)];
            $channels[] = "$a and $b";
        }

        $list = implode(', ', $channels);

        $user->commandSource()->reply("Currently beholding $list");
    }
}
