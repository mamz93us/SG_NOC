<?php

namespace App\Services\Ai;

/**
 * Whether two names are the same name written differently — across Arabic
 * and English, and across the several ways English spells an Arabic name.
 *
 * Why the assistant needs it: on 2026-10-06 محمد was held as Mohammed (38
 * people), Mohamed (24), Mohammad (16) and Muhammad (3); 130 active employees
 * had no Arabic name on record at all, every SSS Egypt employee among them;
 * and an employee asking about a colleague in Arabic was told nobody matched
 * until they spelled the name in English, the right way, themselves.
 *
 * A name is reduced to the consonants that survive every spelling:
 *
 *  - short vowels go — they are what English spells five ways and Arabic does
 *    not write;
 *  - letters that become one sound in English become one letter here (ح and ه
 *    are both h; س and ص both s; ق and ك both k; ج is j, and so is the g
 *    Egyptian names write it with);
 *  - a doubled letter is one, and an ending ة / ه / -ah / -eh is dropped;
 *  - a name that opens on a vowel keeps a mark for it (`A`), which is all
 *    that tells أحمد / Ahmed (`Ahmd`) from حامد / Hamed (`hmd`).
 *
 * محمد, Mohammed, Mohamed, Mohammad and Muhammad all come out as `mhmd`.
 *
 * **Two strengths, because the long vowels are the hard part.** Arabic writes
 * them (و, ي) and English only sometimes shows them (ou, oo, ee, ei, ai). The
 * *strict* key keeps them, so محمود / Mahmoud (`mhmwd`) is not محمد / Mohamed
 * (`mhmd`). The *weak* key drops them too, for the spellings that hide them:
 * إبراهيم is `Abrhym` and Ibrahim is `Abrhm`, and only the weak key joins them.
 * A weak key of one consonant is no key at all: علي and Alaa are not a match.
 * {@see score()} answers 2 for a strict match and 1 for a weak one, and a
 * caller that finds any strict match should not offer the weak ones.
 *
 * **A word has several keys wherever its spelling does not say.** Latin th,
 * sh, kh and gh are each read as one sound and as two letters — Fathi is
 * فتحي (t, h) and Haitham is هيثم (th). The article is read as part of the
 * word and as not: الحربي / Alharbi is Harbi, but the ال of عبد العزيز is
 * what makes it Abdulaziz. And two words may be one name: عبد الله and
 * Abdel Rahman against Abdullah and عبدالرحمن.
 *
 * **A last resort, never a first.** It will call two different names the
 * same (Omar and Amr, Saleh and Salah), so it is only asked once the name as
 * written has matched nobody, and its answer is a list to choose from.
 *
 * Pure: no database, no config.
 */
final class NameSound
{
    /** Each Arabic letter as the Latin consonant it is written with; '' for one that leaves no consonant. */
    private const ARABIC = [
        'ا' => '', 'أ' => '', 'إ' => '', 'آ' => '', 'ء' => '', 'ئ' => '', 'ع' => '', 'ى' => '', 'ة' => '',
        'ؤ' => 'w', 'و' => 'w', 'ي' => 'y',
        'ب' => 'b', 'پ' => 'b', 'ت' => 't', 'ث' => 't', 'ط' => 't', 'ج' => 'j', 'چ' => 'j', 'گ' => 'j',
        'ح' => 'h', 'ه' => 'h', 'خ' => 'X', 'د' => 'd', 'ض' => 'd', 'ذ' => 'z', 'ز' => 'z', 'ظ' => 'z',
        'ر' => 'r', 'س' => 's', 'ص' => 's', 'ش' => 'S', 'غ' => 'G', 'ف' => 'f', 'ڤ' => 'f',
        'ق' => 'k', 'ك' => 'k', 'ک' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
    ];

    /** Two Latin letters that may be one Arabic sound — or may not. */
    private const DIGRAPHS = ['th' => 't', 'sh' => 'S', 'kh' => 'X', 'gh' => 'G'];

    /** Latin spellings of a long vowel, which Arabic writes as a letter. */
    private const LONG_VOWELS = ['ou' => 'W', 'oo' => 'W', 'uu' => 'W', 'ee' => 'Y', 'ii' => 'Y', 'ei' => 'Y', 'ie' => 'Y', 'ey' => 'Y', 'ai' => 'Y', 'ay' => 'Y'];

    private const LATIN = [
        'b' => 'b', 'p' => 'b', 'c' => 'k', 'k' => 'k', 'q' => 'k', 'd' => 'd', 'f' => 'f', 'v' => 'f', 'g' => 'j', 'j' => 'j',
        'h' => 'h', 'l' => 'l', 'm' => 'm', 'n' => 'n', 'r' => 'r', 's' => 's', 't' => 't', 'w' => 'w', 'x' => 'ks', 'y' => 'y', 'z' => 'z',
        // Long vowels and digraphs already read, kept as the letter Arabic writes.
        'W' => 'w', 'Y' => 'y', 'S' => 'S', 'G' => 'G', 'X' => 'X',
    ];

    /** A word on its own that is an article, not a name: Al Harbi is Harbi. */
    private const ARTICLES = ['al', 'el', 'ال'];

    /** A name that opens on a vowel: Ahmed, not Hamed. */
    private const VOWEL = 'A';

    /** A name is never worth more readings than this. */
    private const MAX_KEYS = 12;

    /**
     * How well $query names the person these names belong to: 2 when every
     * word of it is one of their names' words by its strict key, 1 when only
     * by the weak key, 0 when not at all.
     */
    public static function score(string $query, string ...$names): int
    {
        $wanted = array_values(array_filter(array_map(self::keys(...), self::words($query)), fn (array $keys) => $keys['strict'] !== []));

        if ($wanted === []) {
            return 0;
        }

        $best = 0;

        foreach ($names as $name) {
            $held = array_map(self::keys(...), self::words($name));

            if ($held !== []) {
                $best = max($best, self::compare($wanted, $held));
            }
        }

        return $best;
    }

    /**
     * The strict keys of one word: more than one where the spelling leaves a
     * digraph or the article open.
     *
     * @return list<string>
     */
    public static function strict(string $word): array
    {
        return self::keys($word)['strict'];
    }

    /** @return list<string> the same without the long vowels */
    public static function weak(string $word): array
    {
        return self::keys($word)['weak'];
    }

    /**
     * A name's words, without a free-standing article: dots, hyphens and
     * underscores split words too, since a mailbox name is a name.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $words = preg_split('/[\s._\-,]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word) => ! in_array($word, self::ARTICLES, true)));
    }

    /**
     * Every wanted word has to be found, in order of appearance or not: as
     * one of the held words, as two of them run together, or — with the next
     * wanted word — as one of them.
     *
     * @param  list<array{strict: list<string>, weak: list<string>}>  $wanted
     * @param  list<array{strict: list<string>, weak: list<string>}>  $held
     */
    private static function compare(array $wanted, array $held): int
    {
        // What a wanted key may equal: a held word, or two neighbours as one
        // (Abdel Rahman for عبدالرحمن). And the whole name run together, for
        // a key long enough that being inside it is no accident (Rahman).
        $words = ['strict' => [], 'weak' => []];
        $whole = ['strict' => [], 'weak' => []];

        foreach (['strict', 'weak'] as $strength) {
            foreach ($held as $i => $word) {
                array_push($words[$strength], ...$word[$strength]);

                if (isset($held[$i + 1])) {
                    array_push($words[$strength], ...self::join([$word, $held[$i + 1]], $strength));
                }
            }

            $whole[$strength] = self::join($held, $strength);
        }

        $level = function (array $keys, string $strength) use ($words, $whole): bool {
            foreach ($keys as $key) {
                if (in_array($key, $words[$strength], true)) {
                    return true;
                }

                // Inside the whole name the opening mark means nothing.
                $inside = ltrim($key, self::VOWEL);

                if (strlen($inside) >= 4 && collect($whole[$strength])->contains(fn (string $name) => str_contains($name, $inside))) {
                    return true;
                }
            }

            return false;
        };

        $found = fn (array $word): int => match (true) {
            $level($word['strict'], 'strict') => 2,
            $level($word['weak'], 'weak') => 1,
            default => 0,
        };

        $score = 2;

        for ($i = 0, $count = count($wanted); $i < $count; $i++) {
            $alone = $found($wanted[$i]);
            $paired = 0;

            // عبد الله against Abdullah: neither word is anybody's, both are.
            if (isset($wanted[$i + 1])) {
                $pair = [$wanted[$i], $wanted[$i + 1]];
                $paired = $found(['strict' => self::join($pair, 'strict'), 'weak' => self::join($pair, 'weak')]);
            }

            if ($alone === 0 && $paired === 0) {
                return 0;
            }

            if ($paired > $alone) {
                $score = min($score, $paired);
                $i++;

                continue;
            }

            $score = min($score, $alone);
        }

        return $score;
    }

    /**
     * Every way these words run together, by one strength of key.
     *
     * @param  list<array{strict: list<string>, weak: list<string>}>  $words
     * @return list<string>
     */
    private static function join(array $words, string $strength): array
    {
        $joined = [''];

        foreach ($words as $word) {
            $next = [];

            foreach ($joined as $soFar) {
                foreach ($word[$strength] ?: [''] as $key) {
                    // Only the first word opens the name.
                    $next[] = self::squeeze($soFar.($soFar === '' ? $key : ltrim($key, self::VOWEL)));
                }
            }

            $joined = array_slice(array_values(array_unique($next)), 0, self::MAX_KEYS);
        }

        return array_values(array_filter($joined, fn (string $key) => $key !== ''));
    }

    /** @return array{strict: list<string>, weak: list<string>} */
    private static function keys(string $word): array
    {
        $word = trim($word);

        $strict = preg_match('/\p{Arabic}/u', $word) ? self::arabic($word) : self::latin($word);
        $strict = array_slice(array_values(array_unique(array_filter($strict, fn (string $key) => $key !== ''))), 0, self::MAX_KEYS);

        return [
            'strict' => $strict,
            'weak' => array_values(array_unique(array_filter(
                array_map(self::weaken(...), $strict),
                // One consonant matches half the company.
                fn (string $key) => strlen(ltrim($key, self::VOWEL)) >= 2
            ))),
        ];
    }

    /** @return list<string> with the article and, where it has one, without */
    private static function arabic(string $word): array
    {
        // Harakat and tatweel are not letters.
        $word = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $word) ?? $word;
        $forms = [$word];

        // الحربي is حربي; الله and آلاء are words of their own.
        if (mb_strlen($word) >= 5 && str_starts_with($word, 'ال')) {
            $forms[] = mb_substr($word, 2);
        }

        return array_map(function (string $form) {
            $key = in_array(mb_substr($form, 0, 1), ['ا', 'أ', 'إ', 'آ', 'ع'], true) ? self::VOWEL : '';

            foreach (mb_str_split($form) as $letter) {
                $key .= self::ARABIC[$letter] ?? '';
            }

            return self::finish($key);
        }, $forms);
    }

    /** @return list<string> one key per way of reading the word's article and digraphs */
    private static function latin(string $word): array
    {
        $word = preg_replace('/[^a-z]/', '', strtolower(self::ascii($word))) ?? '';

        if ($word === '') {
            return [];
        }

        $forms = [$word];

        // Alharbi and Elsayed are Harbi and Sayed; Ali, Alaa and Elham are not.
        if (preg_match('/^(?:al|el)([a-z]{4,})$/', $word, $m)) {
            $forms[] = $m[1];
        }

        $keys = [];

        foreach ($forms as $form) {
            // An ending -i or -y is the ي of حربي and علي, not a short vowel.
            $form = preg_replace('/[iy]$/', 'Y', $form) ?? $form;
            $form = strtr($form, self::LONG_VOWELS);

            foreach (self::readings($form) as $reading) {
                $key = preg_match('/^[aeiou]/', $form) ? self::VOWEL : '';

                foreach (str_split($reading) as $letter) {
                    $key .= self::LATIN[$letter] ?? '';
                }

                $keys[] = self::finish($key);
            }
        }

        return $keys;
    }

    /**
     * The word with each th / sh / kh / gh read as one sound, and as two
     * letters: a handful at most, since a name holds one or two of them.
     *
     * @return list<string>
     */
    private static function readings(string $word): array
    {
        $readings = [''];
        $length = strlen($word);

        for ($i = 0; $i < $length; $i++) {
            $pair = substr($word, $i, 2);

            if (isset(self::DIGRAPHS[$pair]) && count($readings) < self::MAX_KEYS) {
                $next = [];

                foreach ($readings as $reading) {
                    $next[] = $reading.self::DIGRAPHS[$pair];
                    $next[] = $reading.$pair;
                }

                $readings = $next;
                $i++;

                continue;
            }

            foreach ($readings as $k => $reading) {
                $readings[$k] = $reading.$word[$i];
            }
        }

        return $readings;
    }

    /**
     * The key without the long vowels a spelling may hide. The first letter
     * stays whatever it is: the و of وليد and the ي of يوسف are consonants.
     */
    private static function weaken(string $key): string
    {
        $mark = str_starts_with($key, self::VOWEL) ? self::VOWEL : '';
        $rest = substr($key, strlen($mark));

        return $rest === '' ? $key : self::finish($mark.$rest[0].str_replace(['w', 'y'], '', substr($rest, 1)));
    }

    /** One letter for a doubled one, and no closing h: سلامة, Salameh and Salama are one name. */
    private static function finish(string $key): string
    {
        $key = self::squeeze($key);

        return strlen($key) > 1 ? (preg_replace('/h$/', '', $key) ?? $key) : $key;
    }

    private static function squeeze(string $key): string
    {
        return preg_replace('/(.)\1+/', '$1', $key) ?? $key;
    }

    /** é as e and so on, for the few names that carry an accent. */
    private static function ascii(string $word): string
    {
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);

        return $plain === false ? $word : $plain;
    }
}
