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
 * Removes the target characters already stored in wp_posts and wp_postmeta.
 *
 * Posts are updated with a direct UPDATE on the affected columns so
 * post_modified stays untouched and no revision is created — the change is
 * a pure normalisation, not an edit. Revisions themselves are skipped:
 * restoring one goes through wp_insert_post_data, where the runtime filter
 * strips again.
 */
final class DatabaseCleaner
{
    /** @var callable(string): void */
    private $log;

    /** @param callable(string): void $log Receives one line per affected row. */
    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? static function (string $line): void {};
    }

    /**
     * @return array{posts: int, metas: int, skipped: int} Rows that need (dry
     *                                                     run) or received (apply) changes.
     */
    public function clean(bool $apply): array
    {
        $posts = $this->cleanPosts($apply);
        $metas = $this->cleanMetas($apply);

        return [
            'posts' => $posts,
            'metas' => $metas['cleaned'],
            'skipped' => $metas['skipped'],
        ];
    }

    private function cleanPosts(bool $apply): int
    {
        global $wpdb;

        $where = implode(' OR ', [
            $this->likeAny('post_title'),
            $this->likeAny('post_content'),
            $this->likeAny('post_excerpt'),
        ]);

        /** @var list<object{ID: string, post_type: string, post_title: string, post_content: string, post_excerpt: string}> $rows */
        $rows = $wpdb->get_results(
            "SELECT ID, post_type, post_title, post_content, post_excerpt FROM {$wpdb->posts}
             WHERE post_type != 'revision' AND ({$where})",
        );

        $affected = 0;
        foreach ($rows as $row) {
            $update = [];
            foreach (['post_title', 'post_content', 'post_excerpt'] as $column) {
                $stripped = SanitizeCharactersPlugin::strip($row->$column);
                if ($stripped !== $row->$column) {
                    $update[$column] = $stripped;
                }
            }
            if ($update === []) {
                continue;
            }

            $affected++;
            ($this->log)(sprintf(
                'post %d [%s] %s: %s',
                (int) $row->ID,
                $row->post_type,
                implode(',', array_keys($update)),
                mb_substr(SanitizeCharactersPlugin::strip($row->post_title), 0, 60),
            ));

            if ($apply) {
                $wpdb->update($wpdb->posts, $update, ['ID' => (int) $row->ID]);
                clean_post_cache((int) $row->ID);
            }
        }

        return $affected;
    }

    /**
     * Serialized meta is unserialized, stripped recursively and reserialized;
     * a byte-level replace would corrupt the string-length prefixes. Object
     * values are skipped — mutating unknown class instances is not safe.
     *
     * @return array{cleaned: int, skipped: int}
     */
    private function cleanMetas(bool $apply): array
    {
        global $wpdb;

        /** @var list<object{meta_id: string, post_id: string, meta_key: string, meta_value: string}> $rows */
        $rows = $wpdb->get_results(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE " . $this->likeAny('meta_value'),
        );

        $cleaned = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $value = maybe_unserialize($row->meta_value);

            if (is_object($value)) {
                $skipped++;
                ($this->log)("meta {$row->meta_id} (post {$row->post_id}, {$row->meta_key}): skipped (object value)");
                continue;
            }

            $stripped = SanitizeCharactersPlugin::stripDeep($value);
            if ($stripped === $value) {
                continue;
            }

            $cleaned++;
            ($this->log)("meta {$row->meta_id} (post {$row->post_id}, {$row->meta_key})");

            if ($apply) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => maybe_serialize($stripped)],
                    ['meta_id' => (int) $row->meta_id],
                );
                wp_cache_delete((int) $row->post_id, 'post_meta');
            }
        }

        return ['cleaned' => $cleaned, 'skipped' => $skipped];
    }

    /**
     * utf8mb4 collations give the invisible characters weight 0
     * ("ignorable"), so a plain LIKE '%…%' matches every row; BINARY forces
     * byte matching. UNHEX keeps control bytes (NUL, …) out of the SQL
     * string literal. UTF-8 lead/continuation structure guarantees none of
     * the target sequences can span two other characters, so byte matching
     * has no false positives.
     */
    private function likeAny(string $column): string
    {
        $conditions = [];
        foreach (SanitizeCharactersPlugin::targetCharacters() as $char) {
            $hex = strtoupper(bin2hex($char));
            $conditions[] = "{$column} LIKE BINARY CONCAT('%', UNHEX('{$hex}'), '%')";
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }
}
