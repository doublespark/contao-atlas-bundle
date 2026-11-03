<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

/**
 * v0.7.1. The Contao HTTP cache tags a page showing an image carries, worked out from where the image is placed. PURE: no database, no Contao, so
 * the resolution is proved by tests/image_meta.php. The input is a placement as ImageInventory returns it (table, row_id, parent{...}).
 *
 * Why this exists: v0.7 invalidated only contao.db.tl_files.<id>, and the live Doublespark pages (behind a Contao HTTP cache and Cloudflare) kept
 * serving the old HTML on about half the pages. A page response is tagged with the rows it was built from (the page, its articles, its content
 * elements, a news entry and its archive), not with the file, so the tags of every row that references the file and of that row's parents are
 * invalidated as well.
 *
 * INFERENCE, NOT CHECKED against Contao's source on this machine: that these are the tags a page response carries. The form (contao.db.<table>.<id>) is
 * the one PageMetaWriter uses and Contao's own backend save invalidates; the news archive tag is the tag Contao's news list modules add.
 */
final class CacheTags
{
    public static function forFile(int $fileId): string
    {
        return 'contao.db.tl_files.'.$fileId;
    }

    /**
     * The row's own tag, then its parents': nested content elements, the article, the news entry and its archive, the page, and any other parent
     * table the scan found.
     *
     * @param array<string, mixed> $placement
     *
     * @return list<string>
     */
    public static function forPlacement(array $placement): array
    {
        $table = (string) ($placement['table'] ?? '');
        $id = (int) ($placement['row_id'] ?? 0);
        $parent = \is_array($placement['parent'] ?? null) ? $placement['parent'] : [];
        $tags = [];
        if (preg_match('/^tl_[A-Za-z0-9_]+$/', $table) && $id > 0) {
            $tags[] = "contao.db.$table.$id";
        }
        foreach ((array) ($parent['content_chain'] ?? []) as $contentId) {
            $tags[] = 'contao.db.tl_content.'.(int) $contentId;
        }
        foreach ([['tl_article', 'article_id'], ['tl_news', 'news_id'], ['tl_news_archive', 'news_archive_id'], ['tl_page', 'page_id']] as [$name, $key]) {
            if (isset($parent[$key]) && (int) $parent[$key] > 0) {
                $tags[] = "contao.db.$name.".(int) $parent[$key];
            }
        }
        $other = (string) ($parent['parent_table'] ?? '');
        if ('' !== $other && preg_match('/^tl_[A-Za-z0-9_]+$/', $other) && (int) ($parent['parent_id'] ?? 0) > 0) {
            $tags[] = "contao.db.$other.".(int) $parent['parent_id'];
        }

        return array_values(array_unique($tags));
    }

    /**
     * Every tag for an image file: the file's own and those of every placement that references it. Sorted by first appearance, no duplicates.
     *
     * @param list<array<string, mixed>> $placements
     *
     * @return list<string>
     */
    public static function forImage(int $fileId, array $placements): array
    {
        $tags = [self::forFile($fileId)];
        foreach ($placements as $placement) {
            array_push($tags, ...self::forPlacement($placement));
        }

        return array_values(array_unique($tags));
    }
}
