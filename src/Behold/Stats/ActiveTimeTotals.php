<?php

namespace Beholder\Modules\Behold\Stats;

class ActiveTimeTotals
{
    private array $data = [];

    function add($nick, $chan, $hour, $quantity = 1): void
    {
        if (isset($this->data[$nick][$chan][$hour])) {
            $this->data[$nick][$chan][$hour] += $quantity;
        } else {
            $this->data[$nick][$chan][$hour] = $quantity;
        }
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
