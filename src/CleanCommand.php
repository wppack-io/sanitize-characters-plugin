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

/**
 * Removes invisible and control characters stored in posts and post meta.
 */
final class CleanCommand
{
    /**
     * Scans wp_posts (title, content, excerpt; revisions excluded) and
     * wp_postmeta, and removes control characters, zero-width characters,
     * bidirectional controls and soft hyphens (see
     * SanitizeCharactersPlugin::PATTERN); U+2028/U+2029 become plain
     * newlines. Dry-run by default.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Persist the changes. Without this flag the affected rows are only listed.
     *
     * ## EXAMPLES
     *
     *     # List rows that contain the target characters.
     *     $ wp sanitize-characters
     *
     *     # Strip them.
     *     $ wp sanitize-characters --apply
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $apply = \WP_CLI\Utils\get_flag_value($assocArgs, 'apply', false) === true;

        $cleaner = new DatabaseCleaner(static function (string $line): void {
            \WP_CLI::log($line);
        });

        $result = $cleaner->clean($apply);

        if ($result['skipped'] > 0) {
            \WP_CLI::warning("{$result['skipped']} meta rows skipped (object values).");
        }

        \WP_CLI::success($apply
            ? "Cleaned {$result['posts']} posts and {$result['metas']} meta rows."
            : "Would clean {$result['posts']} posts and {$result['metas']} meta rows (dry-run; pass --apply to persist).");
    }
}
