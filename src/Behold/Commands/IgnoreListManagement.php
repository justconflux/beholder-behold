<?php

namespace Beholder\Modules\Behold\Commands;

use Beholder\Common\Commands\Command;
use Beholder\Common\Contracts\CommandNamespace;
use Beholder\Common\Contracts\Invoker;
use Beholder\Common\Irc\Nick;
use Beholder\Modules\Behold\ManagesChannels;
use Beholder\Modules\Behold\ManagesIgnoreList;
use Beholder\Modules\Behold\ValueObjects\Context;

class IgnoreListManagement
{
    public function __construct(
        protected ManagesIgnoreList $ignoreList,
        protected ManagesChannels $channelManager,
    ) {}

    public function registerCommands(CommandNamespace $namespace): void
    {
        $namespace->registerCommand(new Command(
            'add',
            'Add a nick to the ignore list',
            '<context> <nick>',
            $this->handleAdd(...),
        ));

        $namespace->registerCommand(new Command(
            'remove',
            'Remove a nick from the ignore list',
            '<context> <nick>',
            $this->handleRemove(...),
        ));

        $namespace->registerCommand(new Command(
            'list',
            'List ignored nicks',
            '<context>',
            $this->handleList(...),
        ));
    }

    protected function handleAdd(Invoker $user, string $rawContext, string $rawNick): void
    {
        if ($user->isAdmin() === false) {
            return;
        }

        $commandSource = $user->commandSource();
        $nick = new Nick($rawNick);
        $context = new Context($rawContext);

        if (! $this->isValidContext($context)) {
            $commandSource->reply("Invalid channel/context");
            return;
        }

        // TODO: These won't work quite right, because it checks context AND global by default
        if ($this->ignoreList->isIgnoredNick($context, $nick)) {
            $commandSource->reply("I'm already ignoring $nick {$context->locative()}");
            return;
        }

        $this->ignoreList->addIgnoredNick($context, $nick);

        $commandSource->reply("Okay, I will ignore $nick {$context->locative()}");
    }

    protected function handleRemove(Invoker $user, string $rawContext, string $rawNick): void
    {
        if ($user->isAdmin() === false) {
            return;
        }

        $commandSource = $user->commandSource();
        $nick = new Nick($rawNick);
        $context = new Context($rawContext);

        if (! $this->isValidContext($context)) {
            $commandSource->reply("Invalid channel/context");
            return;
        }

        // TODO: These won't work quite right, because it checks context AND global by default
        if (! $this->ignoreList->isIgnoredNick($context, $nick)) {
            $commandSource->reply("I wasn't ignoring $nick {$context->locative()} in the first place");
            return;
        }

        $this->ignoreList->removeIgnoredNick($context, $nick);

        $commandSource->reply("Okay, I've stopped ignoring $nick {$context->locative()}");
    }

    protected function handleList(Invoker $user, string $rawContext): void
    {
        if ($user->isAdmin() === false) {
            return;
        }

        $context = new Context($rawContext);

        if ($this->isValidContext($context)) {
            $user->commandSource()->reply("Invalid channel/context");
            return;
        }

        $nicks = $this->ignoreList->getIgnoredNicks($context);

        natcasesort($nicks);

        if (count($nicks) > 1) {
            [$b, $a] = [array_pop($nicks), array_pop($nicks)];
            $nicks[] = "$a and $b";
        }

        $list = implode(', ', $nicks);

        $user->commandSource()->reply("Currently ignoring $list {$context->locative()}");
    }

    protected function isValidContext(Context $context): bool
    {
        return $context->isGlobal()
            || $this->channelManager->hasChannel($context->toChannel());
    }
}
