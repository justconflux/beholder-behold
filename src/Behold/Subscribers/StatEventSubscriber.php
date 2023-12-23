<?php

namespace Beholder\Modules\Behold\Subscribers;

use Beholder\Common\Irc\Channel;
use Beholder\Common\Irc\Nick;
use Beholder\Modules\Behold\Common\StatType;
use Beholder\Modules\Behold\ManagesChannels;
use Beholder\Modules\Behold\ManagesIgnoreList;
use Beholder\Modules\Behold\Stats\ActiveTimeTotals;
use Beholder\Modules\Behold\Stats\MonologueMonitor;
use Beholder\Modules\Behold\Stats\QuoteBuffer;
use Beholder\Modules\Behold\Stats\StatTotals;
use Beholder\Modules\Behold\Stats\TextStatsBuffer;
use Beholder\Modules\Behold\ValueObjects\Context;
use InvalidArgumentException;
use LogicException;

class StatEventSubscriber
{
    protected string $profanitiesEncoded = 'WyJzaGl0IiwicGlzcyIsImZ1Y2siLCJjdW50IiwiY29ja3N1Y2tlciIsInR1cmQiLCJ0d2F0IiwiYXNzaG9sZSIsImJpdGNoIiwicHVzc3kiXQ==';

    protected array $profanities;

    protected array $violentWords = [
        'smacks',
        'beats',
        'punches',
        'hits',
        'slaps',
    ];

    protected StatTotals $lineStatsBuffer;
    protected TextStatsBuffer $textStatsBuffer;
    protected ActiveTimeTotals $activeTimesBuffer;
    protected QuoteBuffer $latestQuotesBuffer;
    protected MonologueMonitor $monologueMonitor;

    public function __construct(
        protected ManagesChannels $channelManager,
        protected ManagesIgnoreList $ignoreListManager,
    )
    {
        $this->profanities = json_decode($this->profanitiesEncoded, true);

        // Initialize stats buffers
        $this->lineStatsBuffer = new StatTotals();
        $this->textStatsBuffer = new TextStatsBuffer();
        $this->activeTimesBuffer = new ActiveTimeTotals();
        $this->latestQuotesBuffer = new QuoteBuffer();
        $this->monologueMonitor = new MonologueMonitor();
    }

    public function persist(callable $persistenceFn): void
    {
        $success = $persistenceFn(
            $this->lineStatsBuffer,
            $this->textStatsBuffer,
            $this->activeTimesBuffer,
            $this->latestQuotesBuffer,
        );

        if (! is_bool($success)) {
            throw new LogicException('Invalid persistence response');
        }

        if ($success) {
            $this->resetBuffers();
        }
    }

    public function purgeBuffers($channel): void
    {
        $this->lineStatsBuffer->purgeChannel($channel);
        $this->textStatsBuffer->purgeChannel($channel);
        $this->activeTimesBuffer->purgeChannel($channel);
        $this->latestQuotesBuffer->purgeChannel($channel);
        $this->monologueMonitor->purgeChannel($channel);
    }

    public function handleChat($event): void
    {
        $nick = new Nick($event->from);
        $channel = new Channel($event->channel);
        $message = $event->text;

        // TODO: IMPORTANT! Ignore things that look like commands... but less magically than this.
        $commandCharacter = '!';
        if (str_starts_with($message, $commandCharacter)) {
            return;
        }

        if ($event->is_self) {
            return;
        }

        if (! $this->channelManager->hasChannel($channel)) {
            return;
        }

        if ($this->ignoreListManager->isIgnoredNick(new Context($channel), $nick)) {
            return;
        }

        $this->recordChatMessage($nick, $channel, $message);
    }

    public function handleKick($event): void
    {
        $channel = new Channel($event->channel);
        $kicker = new Nick($event->kicker);
        $victim = new Nick($event->victim);

        if (! $this->channelManager->hasChannel($channel)) {
            return;
        }

        if (! $this->ignoreListManager->isIgnoredNick(new Context($channel), $victim)) {
            $this->lineStatsBuffer->add($channel, $victim, StatType::KickVictim);
        }
        if (! $this->ignoreListManager->isIgnoredNick(new Context($channel), $kicker)) {
            $this->lineStatsBuffer->add($channel, $kicker, StatType::KickPerpetrator);
        }
    }

    public function handleJoinPart($event): void
    {
        if ($event->is_self) {
            return;
        }

        $nick = new Nick($event->nick);
        $channel = new Channel($event->channel);

        if (! $this->channelManager->hasChannel($channel)) {
            return;
        }

        if ($this->ignoreListManager->isIgnoredNick(new Context($channel), $nick)) {
            return;
        }

        $statType = match($event->command) {
            'JOIN' => StatType::Join,
            'PART' => StatType::Part,
            default => throw new InvalidArgumentException(),
        };

        $this->lineStatsBuffer->add($channel, $nick, $statType);
    }

    public function handleMode($event): void
    {
        if ($event->is_self) {
            return;
        }

        $nick = new Nick($event->nick);
        $channel = new Channel($event->channel);
        $changes = $event->changes;

        if (! $this->channelManager->hasChannel($channel)) {
            return;
        }

        if ($this->ignoreListManager->isIgnoredNick(new Context($channel), $nick)) {
            return;
        }

        foreach ($changes as [$polarity, $mode, $recipient]) {
            if ($mode === 'o') {
                $this->lineStatsBuffer->add(
                    $channel,
                    $nick,
                    $polarity === '+'
                        ? StatType::DonatedOps
                        : StatType::RevokedOps,
                );
            }
        }
    }

    protected function recordChatMessage(string $nick, string $channel, string $message): void
    {
        $this->monologueMonitor->spoke($channel, $nick);
        if ($this->monologueMonitor->isBecomingMonologue($channel)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Monologue);
        }

        $this->textStatsBuffer->add($nick, $channel, 1, str_word_count($message, 0, '1234567890'), strlen($message));

        $this->activeTimesBuffer->add($nick, $channel, date('G'));

        if ($this->isProfane($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Profanity);
        }

        if ($this->isAction($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Action);

            if ($this->isViolentAction($message)) {
                $this->lineStatsBuffer->add($channel, $nick, StatType::Violence);
            }
        } else {
            $this->latestQuotesBuffer->set($nick, $channel, $message);
        }

        if ($this->isQuestion($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Question);
        }

        if ($this->isShouting($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Shout);
        }

        if ($this->isAllCapsMessage($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Caps);
        }
        if ($this->isSmile($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Smile);
        }
        if ($this->isFrown($message)) {
            $this->lineStatsBuffer->add($channel, $nick, StatType::Frown);
        }
    }

    protected function isProfane($message): bool
    {
        return preg_match('/' . implode('|', $this->profanities) . '/', $message);
    }

    protected function isAction($message): bool
    {
        return preg_match('/^' . chr(1) . 'ACTION.*' . chr(1) . '$/', $message);
    }

    protected function isViolentAction($message): bool
    {
        return preg_match('/^' . chr(1) . 'ACTION (' . implode('|', $this->violentWords) . ')/', $message);
    }

    protected function isQuestion($message) : bool
    {
        return str_contains($message, '?');
    }

    protected function isShouting($message) : bool
    {
        return str_contains($message, '!');
    }

    protected function isAllCapsMessage($message) : bool
    {
        // All caps lock (and not just a smiley, eg ":D")
        return preg_match_all('/[A-Z]/', $message) > 2 && strtoupper($message) === $message;
    }

    protected function isSmile($message): bool
    {
        return preg_match('/[:;=8X][ ^o-]?[D)>pP\\]}]/', $message);
    }

    protected function isFrown($message): bool
    {
        return preg_match('#[:;=8X][ ^o-]?[(\\[\\\\/{]#', $message);
    }

    protected function resetBuffers(): void
    {
        $this->textStatsBuffer->reset();
        $this->lineStatsBuffer->reset();
        $this->activeTimesBuffer->reset();
        $this->latestQuotesBuffer->reset();
    }
}