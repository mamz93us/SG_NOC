<?php

namespace App\Services\Ai;

/**
 * Finds the placeholders a model leaves in a draft for someone to fill in:
 * "[Your Name]", "[اسمك]", "{{date}}", "(Your title)".
 *
 * The chat's draft cards have Send and Discard and no Edit, so whatever is in
 * a draft is what goes out: 7 of the first 9 emails the assistant drafted on
 * NOC2 ended "[Your Name]" or "[اسمك]". AssistantToolbox refuses such a draft
 * so the model fills it in, and AssistantController refuses to send one that
 * got through anyway.
 *
 * Only brackets holding a word a placeholder is made of count, so an
 * "[External]" subject tag or a "[Ticket #4521]" reference goes through.
 */
class DraftPlaceholders
{
    /** Words that make bracketed text a placeholder, matched as whole words. */
    private const ENGLISH = 'your|name|names|recipient|recipients|sender|insert|date|time|company|position|title|job|department|phone|number|email|address|signature|contact|manager';

    /** Matched anywhere inside the brackets: PCRE's \b does not see Arabic letters as word characters. */
    private const ARABIC = 'اسم|التاريخ|تاريخ|الوقت|وقت|الشركة|المنصب|الوظيفة|القسم|رقم|الهاتف|البريد|العنوان|التوقيع|توقيع|المستلم|المرسل|أدخل|ادخل';

    /**
     * @return array<int, string> each distinct placeholder, in the order met
     */
    public static function find(mixed ...$texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            $text = is_string($text) ? $text : '';

            // {{ anything }} is a template slot, whatever it says.
            if (preg_match_all('/\{\{[^{}\n]{1,60}\}\}/u', $text, $slots)) {
                array_push($found, ...$slots[0]);
                $text = (string) preg_replace('/\{\{[^{}\n]{1,60}\}\}/u', ' ', $text);
            }

            preg_match_all('/[\[\{<]\s*([^\[\]{}<>\n]{1,60}?)\s*[\]\}>]/u', $text, $bracketed, PREG_SET_ORDER);

            foreach ($bracketed as [$whole, $inside]) {
                if (preg_match('/\b(?:'.self::ENGLISH.')\b/i', $inside) || preg_match('/(?:'.self::ARABIC.')/u', $inside)) {
                    $found[] = $whole;
                }
            }

            // Parentheses are ordinary punctuation, so only "(Your …)" and "(Insert …)".
            if (preg_match_all('/\(\s*(?:your|insert)\b[^()\n]{0,40}\)/iu', $text, $parenthesised)) {
                array_push($found, ...$parenthesised[0]);
            }
        }

        return array_values(array_unique($found));
    }
}
