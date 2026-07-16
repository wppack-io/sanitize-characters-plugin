<?php

/*
 * This file is part of the WPPack package.
 *
 * (c) Tsuyoshi Tsurushima
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace WPPack\Plugin\SanitizeCharactersPlugin;

use WP_Query;

/**
 * Sanitizes problem characters out of content on its way into the database
 * and out of search terms on their way into a query.
 *
 * Word processors and note-taking tools (Google Docs, Notion, …) inject
 * zero-width characters as soft line-break hints, and PDFs / terminals leak
 * control characters and odd separators. Pasted into WordPress they survive
 * invisibly inside titles and search boxes; LIKE matching then fails on
 * strings that look identical on screen. Normalising both sides makes
 * visually equal strings actually equal.
 */
final class SanitizeCharactersPlugin
{
    /**
     * Characters removed outright:
     * - C0 controls except TAB/LF/CR, DEL and C1 controls (U+0000–U+001F,
     *   U+007F–U+009F) — never legitimate in stored content.
     * - U+00AD SOFT HYPHEN — invisible except at line breaks; paste junk.
     * - Zero-width: U+200B ZERO WIDTH SPACE, U+2060 WORD JOINER,
     *   U+FEFF ZERO WIDTH NO-BREAK SPACE (BOM).
     * - Bidirectional controls: U+061C, U+200E/U+200F, U+202A–U+202E,
     *   U+2066–U+2069 — invisible, and abusable for trojan-source spoofing.
     *
     * Deliberately kept: TAB/LF/CR, U+200C/U+200D (ZWNJ/ZWJ glue emoji
     * sequences and shape several scripts), U+00A0 NBSP (legitimate
     * spacing), variation selectors.
     */
    public const PATTERN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** U+2028 LINE SEPARATOR / U+2029 PARAGRAPH SEPARATOR become a plain newline. */
    public const LINE_SEPARATOR_PATTERN = '/[\x{2028}\x{2029}]/u';

    public static function boot(): void
    {
        self::stripOnPostSave();
        self::stripOnAcfSave();
        self::stripFromSearchTerms();
        self::registerCliCommand();
    }

    public static function strip(string $text): string
    {
        $text = (string) preg_replace(self::LINE_SEPARATOR_PATTERN, "\n", $text);

        return (string) preg_replace(self::PATTERN, '', $text);
    }

    /**
     * Every character strip() removes or rewrites, for byte-level DB
     * detection (see DatabaseCleaner::likeAny()).
     *
     * @return list<string>
     */
    public static function targetCharacters(): array
    {
        $ranges = [
            [0x0000, 0x0008],
            [0x000B, 0x000C],
            [0x000E, 0x001F],
            [0x007F, 0x009F],
            [0x00AD, 0x00AD],
            [0x061C, 0x061C],
            [0x200B, 0x200B],
            [0x200E, 0x200F],
            [0x2028, 0x2029],
            [0x202A, 0x202E],
            [0x2060, 0x2060],
            [0x2066, 0x2069],
            [0xFEFF, 0xFEFF],
        ];

        $chars = [];
        foreach ($ranges as [$from, $to]) {
            for ($code = $from; $code <= $to; $code++) {
                $chars[] = mb_chr($code, 'UTF-8');
            }
        }

        return $chars;
    }

    /** Strings are stripped, arrays are walked recursively, anything else passes through. */
    public static function stripDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::strip($value);
        }

        if (is_array($value)) {
            array_walk_recursive($value, static function (mixed &$item): void {
                if (is_string($item)) {
                    $item = self::strip($item);
                }
            });
        }

        return $value;
    }

    /** Covers every wp_insert_post()/wp_update_post() path: editor, REST, XML-RPC, CLI. */
    private static function stripOnPostSave(): void
    {
        add_filter('wp_insert_post_data', static function (array $data): array {
            foreach (['post_title', 'post_content', 'post_excerpt'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $data[$key] = self::strip($data[$key]);
                }
            }

            return $data;
        });
    }

    /**
     * ACF saves field values as post meta, which wp_insert_post_data never
     * sees. The filter is registered unconditionally — without ACF it simply
     * never fires.
     */
    private static function stripOnAcfSave(): void
    {
        add_filter('acf/update_value', static fn(mixed $value): mixed => self::stripDeep($value));
    }

    /**
     * Normalises the `s` query var for every WP_Query — admin searches, the
     * front-end `?s=` search and ACF relationship-field lookups all included.
     */
    private static function stripFromSearchTerms(): void
    {
        add_action('pre_get_posts', static function (WP_Query $query): void {
            $s = $query->get('s');
            if (is_string($s) && $s !== '') {
                $query->set('s', self::strip($s));
            }
        });
    }

    private static function registerCliCommand(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('sanitize-characters', CleanCommand::class);
        }
    }
}
