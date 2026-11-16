<?php

namespace App\Services\Knowledge;

/** Splits text into passages of about chat.crawl.chunk_chars characters, on paragraph and sentence boundaries. */
class Chunker
{
    /** @return list<string> */
    public function split(string $text, ?int $size = null): array
    {
        $size ??= (int) config('chat.crawl.chunk_chars');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = '';
        foreach (preg_split("/\n\s*\n|\n/", $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            foreach ($this->pieces($paragraph, $size) as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 1 > $size) {
                    $chunks[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $piece : $current."\n".$piece;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** A paragraph longer than $size, cut at sentence ends (or hard, as a last resort). */
    private function pieces(string $paragraph, int $size): array
    {
        if (mb_strlen($paragraph) <= $size) {
            return [$paragraph];
        }
        $pieces = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?؟。])\s+/u', $paragraph) as $sentence) {
            while (mb_strlen($sentence) > $size) {
                $pieces[] = mb_substr($sentence, 0, $size);
                $sentence = mb_substr($sentence, $size);
            }
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $size) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
