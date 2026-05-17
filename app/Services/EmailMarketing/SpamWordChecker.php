<?php

namespace App\Services\EmailMarketing;

class SpamWordChecker
{
    /**
     * Returns an array of spam-trigger words found in the subject.
     * Empty array = clean.
     */
    public function checkSubject(string $subject): array
    {
        $words = (array) config('email_marketing.spam_trigger_words', []);
        $lower = mb_strtolower($subject);
        $hits = [];
        foreach ($words as $w) {
            if (str_contains($lower, mb_strtolower($w))) {
                $hits[] = $w;
            }
        }

        return array_values(array_unique($hits));
    }
}
