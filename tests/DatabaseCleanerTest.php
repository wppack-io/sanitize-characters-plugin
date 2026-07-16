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

use WPPack\Plugin\SanitizeCharactersPlugin\DatabaseCleaner;

final class DatabaseCleanerTest extends TestCase
{
    public function testDryRunReportsWithoutChangingAnything(): void
    {
        $postId = $this->insertRawPost(['post_title' => 'Dir' . self::ZWSP . 'ty']);

        $lines = [];
        $result = (new DatabaseCleaner(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }))->clean(false);

        $this->assertSame(['posts' => 1, 'metas' => 0, 'skipped' => 0], $result);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString((string) $postId, $lines[0]);

        global $wpdb;
        $title = $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $postId));
        $this->assertSame('Dir' . self::ZWSP . 'ty', $title, 'dry-run must not modify the row');
    }

    public function testApplyStripsWithoutDeletingOrTouchingOtherRows(): void
    {
        global $wpdb;

        $dirty = $this->insertRawPost([
            'post_title' => 'Dir' . self::ZWSP . 'ty',
            'post_content' => self::BOM . 'Body',
            'post_excerpt' => 'Ex' . self::WORD_JOINER . 'cerpt',
        ]);
        $clean = $this->insertRawPost(['post_title' => 'Already clean']);

        $countBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $modifiedBefore = $wpdb->get_var($wpdb->prepare("SELECT post_modified FROM {$wpdb->posts} WHERE ID = %d", $dirty));

        $result = (new DatabaseCleaner())->clean(true);
        $this->assertSame(['posts' => 1, 'metas' => 0, 'skipped' => 0], $result);

        // No post disappears and no new rows (revisions) appear.
        $this->assertSame($countBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"));

        $row = $wpdb->get_row($wpdb->prepare("SELECT post_title, post_content, post_excerpt, post_modified FROM {$wpdb->posts} WHERE ID = %d", $dirty));
        \assert(is_object($row));
        $this->assertSame('Dirty', $row->post_title);
        $this->assertSame('Body', $row->post_content);
        $this->assertSame('Excerpt', $row->post_excerpt);
        $this->assertSame($modifiedBefore, $row->post_modified, 'a pure normalisation must not touch post_modified');

        $this->assertSame('Already clean', $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $clean)));
    }

    public function testRevisionsAreSkipped(): void
    {
        global $wpdb;

        $revision = $this->insertRawPost([
            'post_title' => 'Rev' . self::ZWSP . 'ision',
            'post_type' => 'revision',
        ]);

        $result = (new DatabaseCleaner())->clean(true);

        $this->assertSame(0, $result['posts']);
        $this->assertSame(
            'Rev' . self::ZWSP . 'ision',
            $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $revision)),
        );
    }

    public function testControlCharactersInPostsAreDetectedAndCleaned(): void
    {
        global $wpdb;

        $postId = $this->insertRawPost([
            'post_title' => "Con\x01trol",
            'post_content' => "Line\u{2028}separated",
        ]);

        $result = (new DatabaseCleaner())->clean(true);
        $this->assertSame(1, $result['posts']);

        $row = $wpdb->get_row($wpdb->prepare("SELECT post_title, post_content FROM {$wpdb->posts} WHERE ID = %d", $postId));
        \assert(is_object($row));
        $this->assertSame('Control', $row->post_title);
        $this->assertSame("Line\nseparated", $row->post_content);
    }

    public function testMetaPlainAndSerializedValuesAreCleaned(): void
    {
        global $wpdb;

        $postId = $this->insertRawPost();
        $wpdb->insert($wpdb->postmeta, [
            'post_id' => $postId,
            'meta_key' => 'plain',
            'meta_value' => 'A' . self::ZWSP . 'B',
        ]);
        $wpdb->insert($wpdb->postmeta, [
            'post_id' => $postId,
            'meta_key' => 'serialized',
            'meta_value' => serialize(['k' => 'C' . self::BOM . 'D']),
        ]);

        $result = (new DatabaseCleaner())->clean(true);
        $this->assertSame(2, $result['metas']);

        $this->assertSame('AB', get_post_meta($postId, 'plain', true));
        $this->assertSame(['k' => 'CD'], get_post_meta($postId, 'serialized', true));
    }

    public function testObjectMetaIsSkippedUntouched(): void
    {
        global $wpdb;

        $postId = $this->insertRawPost();
        $serializedObject = serialize((object) ['field' => 'A' . self::ZWSP . 'B']);
        $wpdb->insert($wpdb->postmeta, [
            'post_id' => $postId,
            'meta_key' => 'object',
            'meta_value' => $serializedObject,
        ]);

        $result = (new DatabaseCleaner())->clean(true);

        $this->assertSame(0, $result['metas']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(
            $serializedObject,
            $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'object'", $postId)),
        );
    }
}
