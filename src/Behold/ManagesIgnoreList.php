<?php

namespace Beholder\Modules\Behold;

use Beholder\Modules\Behold\ValueObjects\Context;
use Beholder\Common\Irc\Nick;

interface ManagesIgnoreList
{
    public function addIgnoredNick(Context $context, Nick $nick): void;

    public function removeIgnoredNick(Context $context, Nick $nick): void;

    /**
     * @return array<Nick>
     */
    public function getIgnoredNicks(Context $context): array;

    public function isIgnoredNick(Context $context, Nick $nick, bool $strictContext = false): bool;
}
