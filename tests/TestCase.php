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

namespace WPPack\Plugin\SanitizeCharactersPlugin\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use WP_Hook;

/**
 * Base class that keeps WordPress booted while isolating hooks and globals
 * between tests. wp-phpunit's WP_UnitTestCase is not compatible with
 * PHPUnit 11, so it is not used.
 */
abstract class TestCase extends BaseTestCase
{
    protected const ZWSP = "\u{200B}";
    protected const BOM = "\u{FEFF}";
    protected const WORD_JOINER = "\u{2060}";

    /** @var array<string, WP_Hook> */
    private array $filterBackup = [];

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        global $wp_filter;
        $this->filterBackup = array_map(static fn(WP_Hook $hook): WP_Hook => clone $hook, $wp_filter);

        foreach (['wp_query', 'wp_the_query'] as $name) {
            $this->globalsBackup[$name] = $GLOBALS[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        global $wp_filter, $wpdb;
        $wp_filter = $this->filterBackup;

        foreach ($this->globalsBackup as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        wp_set_current_user(0);

        // Tests insert posts and meta straight into the DB; wipe them (and
        // their caches) so later tests start from a clean slate.
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        wp_cache_flush();
    }

    /** @param array<string, mixed> $args */
    protected function createPost(array $args = []): int
    {
        $postId = wp_insert_post($args + [
            'post_title' => 'Test post',
            'post_content' => 'Body',
            'post_status' => 'publish',
        ]);
        \assert(is_int($postId) && $postId > 0);

        return $postId;
    }

    /**
     * Insert straight into the DB, past the save-time filter, to simulate
     * rows that were stored before the plugin was active.
     *
     * @param array<string, string> $columns
     */
    protected function insertRawPost(array $columns = []): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->insert($wpdb->posts, $columns + [
            'post_title' => 'Raw post',
            'post_content' => 'Body',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => $now,
            'post_date_gmt' => $now,
            'post_modified' => $now,
            'post_modified_gmt' => $now,
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
        ]);

        return (int) $wpdb->insert_id;
    }
}
