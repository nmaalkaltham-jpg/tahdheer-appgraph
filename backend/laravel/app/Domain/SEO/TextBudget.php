<?php

namespace App\Domain\SEO;

/**
 * Word-safe trimming for the two MIP §7.6 budgets.
 *
 * The title rules are DIFFERENT rules, not a conflict:
 *   · TITLE_BUDGET (60) is the editor input budget — a validator rule.
 *   · TITLE_ALARM (68) is the rendered-title truncation alarm threshold, which
 *     applies to the FULL rendered string (title + brand suffix).
 */
final class TextBudget
{
    /** Editor input budget for an authored SEO title. */
    public const TITLE_BUDGET = 60;

    /** Rendered-title truncation alarm threshold. */
    public const TITLE_ALARM = 68;

    /** Meta description budget (~155, word-safe). */
    public const DESCRIPTION_BUDGET = 155;

    /**
     * Trim to a budget on a word boundary. Never cuts mid-word when a boundary
     * exists in the back half of the budget; adds the Arabic-safe ellipsis «…»
     * only when text was actually removed.
     */
    public static function words(?string $text, int $max): ?string
    {
        $text = self::normalize($text);

        if ($text === null) {
            return null;
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        // Reserve one character for the ellipsis.
        $cut = mb_substr($text, 0, $max - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($max / 2)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut)."\u{2026}";
    }

    /**
     * Collapse whitespace, strip tags and return null for anything that carries
     * no information. Empty is a real state — it is never padded.
     */
    public static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text === '' ? null : $text;
    }
}
