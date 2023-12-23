<?php

namespace Beholder\Modules\Behold;

use Beholder\Common\Contracts\BeholderModule;
use Beholder\Common\Contracts\Modules\GeneratesLogs;
use Beholder\Common\Contracts\NetworkConfiguration;
use Beholder\Common\Irc\Channel;
use Beholder\Common\Irc\Nick;
use Beholder\Common\Traits\Modules\HasLogger;
use Beholder\Common\Traits\Modules\HasModuleRegistry;
use Beholder\Modules\Behold\Commands\IgnoreListManagement;
use Beholder\Modules\Behold\Commands\ChannelStatsManagement;
use Beholder\Modules\Behold\Commands\StatsUrlCommand;
use Beholder\Modules\Behold\Persistence\MySQL;
use Beholder\Modules\Behold\Persistence\PersistenceInterface;
use Beholder\Modules\Behold\Stats\ActiveTimeTotals;
use Beholder\Modules\Behold\Stats\QuoteBuffer;
use Beholder\Modules\Behold\Stats\StatTotals;
use Beholder\Modules\Behold\Stats\TextStatsBuffer;
use Beholder\Modules\Behold\Subscribers\StatEventSubscriber;
use Beholder\Modules\Behold\ValueObjects\Context;
use Exception;

class BeholdModule implements BeholderModule, GeneratesLogs, ManagesChannels, ManagesIgnoreList
{
    use HasLogger;
    use HasModuleRegistry;

    protected ChannelStatsManagement $channelsControl;
    protected IgnoreListManagement $ignoredNicksControl;
    protected StatEventSubscriber $statEventSubscriber;

    protected int $lastWriteAt;

    /** @var array<Channel> */
    protected array $channels = [];

    /** @var array<array<Nick>> */
    protected array $ignoreNicks;

    protected PersistenceInterface $persistence;

    public function __construct(
        protected ConfigurationInterface $configuration,
    ) {
        $this->lastWriteAt = 0;
    }

    public function initialize(): void
    {
        $this->persistence = new MySQL($this->logger);

        $this->persistence->install();
    }

    public function boot(NetworkConfiguration $networkConfiguration): void
    {
        // Initialize internal data from database
        $this->ignoreNicks = $this->persistence->getIgnoredNicks();
        foreach ($this->persistence->getChannels() as $channel) {
            // Must go through add channel to set up channel requirements
            $this->addChannel($channel);
        }

        $this->channelsControl = new ChannelStatsManagement($this);
        $this->ignoredNicksControl = new IgnoreListManagement($this, $this);

        $this->statEventSubscriber = new StatEventSubscriber($this, $this);
    }

    public function register(): void
    {
        $this->registerAdminCommands();
        $this->subscribeToStatEvents();
    }

    protected function registerAdminCommands(): void
    {
        $commandNamespace = $this->moduleRegistry
            ->findOrRegisterCommandNamespace(['stats']);
        $commandNamespace->registerCommand(new StatsUrlCommand($this));

        $commandNamespace = $this->moduleRegistry
            ->findOrRegisterCommandNamespace(['stats', 'channel']);

        $this->channelsControl->registerCommands($commandNamespace);

        $commandNamespace = $this->moduleRegistry
            ->findOrRegisterCommandNamespace(['stats', 'ignore']);

        $this->ignoredNicksControl->registerCommands($commandNamespace);
    }

    protected function subscribeToStatEvents(): void
    {
        $this->moduleRegistry->registerEventSubscriber('tick', $this->writeToDatabase(...));

        $this->moduleRegistry->registerEventSubscriber('chat', $this->statEventSubscriber->handleChat(...));

        $this->moduleRegistry->registerEventSubscriber('kick', $this->statEventSubscriber->handleKick(...));

        $this->moduleRegistry->registerEventSubscriber('join', $this->statEventSubscriber->handleJoinPart(...));

        $this->moduleRegistry->registerEventSubscriber('part', $this->statEventSubscriber->handleJoinPart(...));

        $this->moduleRegistry->registerEventSubscriber('mode', $this->statEventSubscriber->handleMode(...));
    }

    public function addIgnoredNick(Context $context, Nick $nick): void
    {
        $this->ignoreNicks[$context->normalize()][] = $nick;
    }

    public function removeIgnoredNick(Context $context, Nick $nick): void
    {
        $this->ignoreNicks[$context->normalize()] = array_filter(
            $this->ignoreNicks[$context->normalize()],
            fn (Nick $item) => $item->equals($nick) === false,
        );
    }

    public function getIgnoredNicks(Context $context): array
    {
        return $this->ignoreNicks[$context->normalize()];
    }

    public function isIgnoredNick(Context $context, Nick $nick): bool
    {
        // Is the nick ignored in the given context?
        if ($nick->isIn($this->ignoreNicks[$context->normalize()] ?? [])) {
            return true;
        }

        if ($context->isGlobal()) {
            // The given context is global, so no need to check again
            return false;
        }

        // Also check the global context
        return $nick->isIn($this->ignoreNicks[Context::global()->normalize()] ?? []);
    }

    public function addChannel(Channel $channel): void
    {
        if ($this->hasChannel($channel)) {
            return;
        }

        $this->channels[] = $channel;

        $this->moduleRegistry->addChannelRequirement($this, $channel);
    }

    public function removeChannel(Channel $channel): void
    {
        if (!$this->hasChannel($channel)) {
            return;
        }

        $this->channels = array_filter(
            $this->channels,
            fn (Channel $listChannel) => ! $listChannel->equals($channel),
        );

        $this->moduleRegistry->removeChannelRequirement($this, $channel);
        $this->statEventSubscriber->purgeBuffers($channel->normalize());
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function matchChannelNames(string $a, string $b): bool
    {
        return $this->normalizeChannel($a) === $this->normalizeChannel($b);
    }

    public function hasChannel(Channel $channel): bool
    {
        return $channel->isIn($this->channels);
    }

    protected function normalizeChannel(string $channel): string
    {
        return strtolower(trim($channel));
    }

    /**
     * @return void
     * @throws BeholdException
     */
    protected function writeToDatabase(): void
    {
        if (time() - $this->lastWriteAt < $this->configuration->getWriteFrequencySeconds()) {
            return;
        }

        $this->logger->debug('Writing to database...');

        try {
            $this->statEventSubscriber->persist(function (
                StatTotals $lineStats,
                TextStatsBuffer $textStats,
                ActiveTimeTotals $activeTimes,
                QuoteBuffer $latestQuotes,
            ) {
                return $this->persistence->persist(
                    $lineStats,
                    $textStats,
                    $activeTimes,
                    $latestQuotes,
                    $this->channels,
                    $this->ignoreNicks,
                );
            });
        } catch (Exception $exception) {
            throw new BeholdException('Error encountered while persisting data.', 0, $exception);
        }

        $this->lastWriteAt = time();
    }
}
