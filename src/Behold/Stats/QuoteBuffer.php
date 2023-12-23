<?php

namespace Beholder\Modules\Behold\Stats;

class QuoteBuffer
{
    private array $data = [];

    function set($nick, $chan, $quote = ''): void
    {
        $this->data[$nick][$chan] = $quote;
    }

    public function data() : array
    {
        return $this->data;
    }

    function reset(): void
    {
        $this->data = []; // Just empty the data array - leaving 0 values will generate useless queries otherwise
    }

    public function purgeChannel($channel): void
    {
        foreach (array_keys($this->data) as $nick) {
            unset($this->data[$nick][$channel]);
        }
    }
}
