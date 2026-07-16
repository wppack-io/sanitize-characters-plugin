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

use WP_Query;
use WPPack\Plugin\SanitizeCharactersPlugin\SanitizeCharactersPlugin;

final class SanitizeCharactersPluginTest extends TestCase
{
    public function testSaveStripsZeroWidthCharactersFromTitleContentAndExcerpt(): void
    {
        $postId = $this->createPost([
            'post_title' => 'Broad' . self::ZWSP . 'way',
            'post_content' => self::BOM . 'Body ' . self::WORD_JOINER . 'text',
            'post_excerpt' => 'Ex' . self::ZWSP . 'cerpt',
        ]);

        $post = get_post($postId);
        \assert($post instanceof \WP_Post);

        $this->assertSame('Broadway', $post->post_title);
        $this->assertSame('Body text', $post->post_content);
        $this->assertSame('Excerpt', $post->post_excerpt);
    }

    public function testSaveKeepsEmojiSequencesIntact(): void
    {
        // Family emoji is glued with U+200D ZWJ, which must survive.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $postId = $this->createPost(['post_title' => 'Fun ' . $family . self::ZWSP]);

        $post = get_post($postId);
        \assert($post instanceof \WP_Post);

        $this->assertSame('Fun ' . $family, $post->post_title);
    }

    public function testUpdateStripsToo(): void
    {
        $postId = $this->createPost();
        wp_update_post([
            'ID' => $postId,
            'post_title' => 'Up' . self::ZWSP . 'dated',
        ]);

        $post = get_post($postId);
        \assert($post instanceof \WP_Post);

        $this->assertSame('Updated', $post->post_title);
    }

    public function testSearchTermIsStrippedSoPastedQueriesMatch(): void
    {
        $postId = $this->createPost(['post_title' => 'Broadway musical roundup']);

        $query = new WP_Query([
            's' => 'Broad' . self::ZWSP . 'way',
            'post_type' => 'post',
            'fields' => 'ids',
        ]);

        $this->assertSame('Broadway', $query->get('s'));
        $this->assertContains($postId, $query->posts);
    }

    public function testAcfValuesAreStrippedOnSave(): void
    {
        $string = apply_filters('acf/update_value', 'A' . self::ZWSP . 'B', 1, [], null);
        $this->assertSame('AB', $string);

        $array = apply_filters(
            'acf/update_value',
            ['x' . self::BOM => 'a' . self::ZWSP . 'b', 'nested' => ['c' . self::WORD_JOINER . 'd']],
            1,
            [],
            null,
        );
        // Keys pass through; only values are normalised.
        $this->assertSame(['x' . self::BOM => 'ab', 'nested' => ['cd']], $array);
    }

    public function testAcfNonStringValuesPassThrough(): void
    {
        $this->assertSame(42, apply_filters('acf/update_value', 42, 1, [], null));
        $this->assertNull(apply_filters('acf/update_value', null, 1, [], null));
    }

    public function testStripRemovesZeroWidthCharacters(): void
    {
        $this->assertSame('abc', SanitizeCharactersPlugin::strip('a' . self::ZWSP . 'b' . self::BOM . 'c' . self::WORD_JOINER));
    }

    public function testStripRemovesControlCharacters(): void
    {
        // C0 (except TAB/LF/CR), DEL and C1.
        $this->assertSame('ab', SanitizeCharactersPlugin::strip("a\x00\x01\x08\x0B\x0C\x0E\x1Fb"));
        $this->assertSame('ab', SanitizeCharactersPlugin::strip("a\u{007F}\u{0080}\u{009F}b"));
    }

    public function testStripRemovesSoftHyphenAndBidiControls(): void
    {
        $this->assertSame('ab', SanitizeCharactersPlugin::strip("a\u{00AD}b"));
        $this->assertSame('ab', SanitizeCharactersPlugin::strip("a\u{061C}\u{200E}\u{200F}\u{202A}\u{202E}\u{2066}\u{2069}b"));
    }

    public function testStripNormalisesLineAndParagraphSeparatorsToNewlines(): void
    {
        $this->assertSame("a\nb\nc", SanitizeCharactersPlugin::strip("a\u{2028}b\u{2029}c"));
    }

    public function testStripKeepsMeaningfulCharacters(): void
    {
        // TAB/LF/CR, ZWNJ/ZWJ (U+200C/U+200D), NBSP and variation selectors stay.
        $kept = "a\tb\nc\rd\u{200C}e\u{200D}f\u{00A0}g\u{FE0F}";
        $this->assertSame($kept, SanitizeCharactersPlugin::strip($kept));
    }

    public function testTargetCharactersMatchesThePattern(): void
    {
        foreach (SanitizeCharactersPlugin::targetCharacters() as $char) {
            $this->assertNotSame(
                'x' . $char . 'y',
                SanitizeCharactersPlugin::strip('x' . $char . 'y'),
                sprintf('U+%04X should be removed or rewritten', mb_ord($char, 'UTF-8')),
            );
        }
    }
}
