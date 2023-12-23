<?php

namespace Beholder\Modules\Behold\Stats;

class TextStatsBuffer
{
    // This class deals with clever stuff like word and character count averages
    private array $data = [];

    function add($nick, $chan, $messages, $words, $characters): void
    {
        if (isset($this->data[$nick][$chan])) {
            $this->data[$nick][$chan]['messages'] += $messages;
            $this->data[$nick][$chan]['words'] += $words;
            $this->data[$nick][$chan]['chars'] += $characters;
        } else {
            $this->data[$nick][$chan] = [
                'messages' => $messages,
                'words' => $words,
                'chars' => $characters
            ];
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
