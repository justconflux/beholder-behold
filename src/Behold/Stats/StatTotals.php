<?php

namespace Beholder\Modules\Behold\Stats;

use Beholder\Modules\Behold\Common\StatType;

class StatTotals
{
    private array $data = [];

    function add(string $channel, string $nick, StatType $type, $quantity = 1) : void
    {
        if ($quantity != 0) { // we don't want 0 values causing needless database writes
            if (isset($this->data[$type->value][$channel][$nick])) {
                $this->data[$type->value][$channel][$nick] += $quantity;
            } else {
                $this->data[$type->value][$channel][$nick] = $quantity;
            }
        }
    }

    function getData() : array
    {
        return $this->data;
    }

    function reset() : void
    {
        // Just empty the data array - leaving 0 values will generate useless queries otherwise
        $this->data = [];
    }

    public function purgeChannel(string $channel): void
    {
        foreach (array_keys($this->data) as $type) {
            unset($this->data[$type][$channel]);
        }
    }
}