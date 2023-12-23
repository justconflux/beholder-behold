<?php

namespace Beholder\Modules\Behold\ValueObjects;

use Beholder\Common\Irc\Channel;
use RuntimeException;

class Context
{
    public static function global(): static
    {
        return new static('global');
    }

    public function __construct(protected string $context)
    {}

    public function __toString(): string
    {
        return $this->context;
    }

    public function normalize(): string
    {
        return strtolower(trim($this->context));
    }

    public function locative(): string
    {
        return $this->isGlobal() ? 'globally' : "in $this->context";
    }

    public function equals(Context $context): bool
    {
        return $this->normalize() === $context->normalize();
    }

    public function isGlobal(): bool
    {
        return $this->context === 'global';
    }

    public function toChannel(): Channel
    {
        if ($this->isGlobal()) {
            throw new RuntimeException();
        }

        return new Channel($this->context);
    }
}
