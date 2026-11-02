<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * v0.5, read-only. Lists the site's image files (tl_files), every placement that references each one, and maps a rendered assets/images/ variant
 * address back to its source file. Strictly SELECT statements and file reads: nothing here writes to the database or the filesystem, and nothing
 * asks Contao's image factory for a variant (which would create one).
 *
 * Placements are found from the schema, never from a fixed list of columns: every binary(16) column (one UUID, e.g. singleSRC) and every blob
 * column (a serialised list of UUIDs, e.g. multiSRC) of every tl_ table, from information_schema. Sites carry their own columns
 * (Doublespark: dsImg_*Src, tl_ds_portfolio.main_image; SJH: tl_sjh_categories.imageSRC), which is why the scan is schema-driven.
 */
class ImageInventory
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];

    /** Tables whose binary columns are not placements: the file tree itself, Contao's bookkeeping, permissions and image size definitions. */
    private const SKIP_TABLES = ['tl_files', 'tl_version', 'tl_undo', 'tl_log', 'tl_search', 'tl_search_index', 'tl_search_term', 'tl_crawl_queue',
        'tl_cron_job', 'tl_message_queue', 'tl_remember_me', 'tl_trusted_device', 'tl_opt_in', 'tl_opt_in_related', 'tl_user', 'tl_user_group',
        'tl_member', 'tl_member_group', 'tl_image_size', 'tl_image_size_item', 'tl_atlas_news_ref'];

    private const BLOB_TYPES = ['tinyblob', 'blob', 'mediumblob', 'longblob'];

    /** Columns read beside a reference when the table has them, to describe the placement. */
    private const CONTEXT = ['pid', 'ptable', 'type', 'published', 'invisible', 'overwriteMeta', 'alt', 'imageTitle', 'caption'];

    public const MAX_PER_PAGE = 500;

    /** @var array<string, array<string, string>>|null table => column => column type (lower case) */
    private ?array $schema = null;

    /** @var array<string, list<array>>|null hex uuid => placements, for the blob columns (built once per request) */
    private ?array $blobIndex = null;

    /** @var array<string, array> */
    private array $parentCache = [];

    public function __construct(private readonly Connection $db, private readonly ContentUrls $urls, private readonly string $projectDir)
    {
    }

    /**
     * One page of image files, oldest id first, each with its meta per language and every placement that references it.
     *
     * @return array{page:int,per_page:int,total:int,pages:int,uuid_columns:list<string>,images:list<array>}
     */
    public function listImages(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $in = implode(', ', array_fill(0, \count(self::EXTENSIONS), '?'));
        $where = "type = 'file' AND LOWER(extension) IN ($in)";

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM tl_files WHERE $where", self::EXTENSIONS);
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, HEX(uuid) AS uuid_hex, path, hash, extension, meta FROM tl_files WHERE $where ORDER BY id LIMIT $perPage OFFSET ".(($page - 1) * $perPage),
            self::EXTENSIONS,
        );

        $placements = $this->placementsFor(array_map(static fn (array $r): string => strtolower((string) $r['uuid_hex']), $rows));

        $images = [];
        foreach ($rows as $row) {
            $uuid = strtolower((string) $row['uuid_hex']);
            [$width, $height] = $this->dimensions((string) $row['path'], strtolower((string) $row['extension']));
            $images[] = [
                'id' => (int) $row['id'],
                'uuid' => $uuid,
                'path' => (string) $row['path'],
                'hash' => (string) $row['hash'],
                'extension' => strtolower((string) $row['extension']),
                'width' => $width,
                'height' => $height,
                'meta' => $this->meta($row['meta']),
                'placements' => $placements[$uuid] ?? [],
            ];
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'uuid_columns' => $this->columnNames(),
            'images' => $images,
        ];
    }

    /**
     * Maps a rendered image address back to its source tl_files row. method says how:
     *  - file_path: the address is the source file itself (files/...).
     *  - deferred_store: a variant Contao has not generated yet; assets/images/deferred/<path>.json names the source exactly.
     *  - filename: a generated variant. Contao names a variant <source file name>-<hash>.<format> in a folder named after the hash's first
     *    character (contao/image Resizer::createCachePath); the hash covers the resize settings and cannot be reversed, and Contao keeps no
     *    record of a variant once it exists (the deferred file is removed when the variant is generated), so the source is the single image
     *    file with that name. Several files with the same name is 'ambiguous', with the candidates listed.
     *  - unresolved: none of the above found it.
     *
     * @return array{url:string,uuid:?string,path:?string,method:string,candidates:list<array{uuid:string,path:string}>}
     */
    public function resolve(string $url): array
    {
        $answer = ['url' => $url, 'uuid' => null, 'path' => null, 'method' => 'unresolved', 'candidates' => []];
        $path = ltrim(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')), '/');

        if (str_starts_with($path, 'files/')) {
            $file = $this->fileByPath($path);

            return null === $file ? $answer : ['method' => 'file_path'] + $file + $answer;
        }

        if (!preg_match('#^assets/images/(?:deferred/)?([0-9a-z])/(.+)-([0-9a-z]{6,16})\.([a-z0-9]+)$#i', $path, $m)) {
            return $answer;
        }
        [, $dir, $name, $hash, $format] = $m;

        $deferred = $this->deferredSource($dir.'/'.$name.'-'.$hash.'.'.$format);
        if (null !== $deferred && null !== $file = $this->fileByPath($deferred)) {
            return ['method' => 'deferred_store'] + $file + $answer;
        }

        if (strtolower($dir) !== strtolower($hash[0])) {
            return $answer; // not a Contao variant name: the folder is always the hash's first character
        }

        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name).'.%';
        $rows = $this->db->fetchAllAssociative("SELECT HEX(uuid) AS uuid_hex, path, name, extension FROM tl_files WHERE type = 'file' AND name LIKE ? ESCAPE '!' ORDER BY id", [$like]);
        $candidates = [];
        foreach ($rows as $row) {
            if (pathinfo((string) $row['name'], PATHINFO_FILENAME) === $name && \in_array(strtolower((string) $row['extension']), self::EXTENSIONS, true)) {
                $candidates[] = ['uuid' => strtolower((string) $row['uuid_hex']), 'path' => (string) $row['path']];
            }
        }
        if (1 === \count($candidates)) {
            return ['method' => 'filename', 'uuid' => $candidates[0]['uuid'], 'path' => $candidates[0]['path']] + $answer;
        }
        if (\count($candidates) > 1) {
            return ['method' => 'ambiguous', 'candidates' => $candidates] + $answer;
        }

        return $answer;
    }

    /**
     * The UUID columns the scan covers, as table.column, for the caller to see what was looked at.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        $names = [];
        foreach ($this->uuidColumns() as [$table, $column, $kind]) {
            $names[] = "$table.$column";
        }

        return $names;
    }

    /** @return list<array{0:string,1:string,2:string}> [table, column, 'binary'|'blob'] */
    private function uuidColumns(): array
    {
        $columns = [];
        foreach ($this->schema() as $table => $cols) {
            if (\in_array($table, self::SKIP_TABLES, true) || !isset($cols['id'])) {
                continue;
            }
            foreach ($cols as $column => $type) {
                if ('binary(16)' === $type) {
                    $columns[] = [$table, $column, 'binary'];
                } elseif (\in_array($type, self::BLOB_TYPES, true) && !str_starts_with(strtolower($column), 'order')) {
                    // orderSRC and the like repeat multiSRC's list in sort order, so they would double every gallery placement
                    $columns[] = [$table, $column, 'blob'];
                }
            }
        }

        return $columns;
    }

    /** @return array<string, array<string, string>> */
    private function schema(): array
    {
        if (null === $this->schema) {
            $this->schema = [];
            $rows = $this->db->fetchAllAssociative(
                "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tl!_%' ESCAPE '!' ORDER BY TABLE_NAME, ORDINAL_POSITION",
            );
            foreach ($rows as $row) {
                $this->schema[(string) $row['t']][(string) $row['c']] = strtolower(trim((string) $row['ct']));
            }
        }

        return $this->schema;
    }

    /**
     * @param list<string> $uuids lower-case hex
     *
     * @return array<string, list<array>>
     */
    private function placementsFor(array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }
        $wanted = array_flip($uuids);
        $found = [];
        $in = implode(', ', array_fill(0, \count($uuids), 'UNHEX(?)'));

        foreach ($this->uuidColumns() as [$table, $column, $kind]) {
            if ('binary' !== $kind) {
                continue;
            }
            $select = $this->contextSelect($table);
            $rows = $this->db->fetchAllAssociative(
                "SELECT $select, HEX({$this->q($column)}) AS ref_hex FROM {$this->q($table)} WHERE {$this->q($column)} IN ($in) ORDER BY id",
                $uuids,
            );
            foreach ($rows as $row) {
                $found[strtolower((string) $row['ref_hex'])][] = $this->placement($table, $column, $row);
            }
        }

        foreach ($this->blobIndex() as $uuid => $list) {
            if (isset($wanted[$uuid])) {
                array_push($found[$uuid], ...$list);
            }
        }

        return $found;
    }

    /** @return array<string, list<array>> */
    private function blobIndex(): array
    {
        if (null !== $this->blobIndex) {
            return $this->blobIndex;
        }
        $this->blobIndex = [];
        foreach ($this->uuidColumns() as [$table, $column, $kind]) {
            if ('blob' !== $kind) {
                continue;
            }
            $select = $this->contextSelect($table);
            $rows = $this->db->fetchAllAssociative("SELECT $select, {$this->q($column)} AS ref_blob FROM {$this->q($table)} WHERE {$this->q($column)} IS NOT NULL ORDER BY id");
            foreach ($rows as $row) {
                $refs = self::uuidsIn((string) $row['ref_blob']);
                if ([] === $refs) {
                    continue;
                }
                unset($row['ref_blob']);
                $placement = $this->placement($table, $column, $row);
                foreach ($refs as $uuid) {
                    $this->blobIndex[$uuid] ??= [];
                    $this->blobIndex[$uuid][] = $placement;
                }
            }
        }

        return $this->blobIndex;
    }

    /**
     * The UUIDs a blob column value holds: one raw 16-byte UUID, or a PHP-serialised list of them (Contao's multiSRC). A value that is neither
     * (text kept in a blob) holds none.
     *
     * @return list<string> lower-case hex, unique
     */
    public static function uuidsIn(string $value): array
    {
        if (16 === \strlen($value)) {
            return [bin2hex($value)];
        }
        if (!str_starts_with($value, 'a:')) {
            return [];
        }
        $list = @unserialize($value, ['allowed_classes' => false]);
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        array_walk_recursive($list, static function ($item) use (&$out): void {
            if (\is_string($item) && 16 === \strlen($item)) {
                $out[bin2hex($item)] = true;
            } elseif (\is_string($item) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item)) {
                $out[strtolower(str_replace('-', '', $item))] = true;
            }
        });

        return array_keys($out);
    }

    private function contextSelect(string $table): string
    {
        $cols = ['id'];
        foreach (self::CONTEXT as $column) {
            if (isset($this->schema()[$table][$column])) {
                $cols[] = $column;
            }
        }

        return implode(', ', array_map($this->q(...), $cols));
    }

    private function placement(string $table, string $column, array $row): array
    {
        $published = null;
        if (\array_key_exists('invisible', $row)) {
            $published = !self::truthy($row['invisible']);
        } elseif (\array_key_exists('published', $row)) {
            $published = self::truthy($row['published']);
        }
        $overwrite = \array_key_exists('overwriteMeta', $row) ? self::truthy($row['overwriteMeta']) : null;

        return [
            'table' => $table,
            'column' => $column,
            'row_id' => (int) $row['id'],
            'element_type' => isset($row['type']) ? (string) $row['type'] : null,
            'published' => $published,
            'overwrite_meta' => $overwrite,
            // The placement's own alt and title are only what the page shows when overwriteMeta is set; otherwise the file's meta applies.
            'alt' => $overwrite ? (string) ($row['alt'] ?? '') : null,
            'title' => $overwrite ? (string) ($row['imageTitle'] ?? '') : null,
            'caption' => $overwrite ? (string) ($row['caption'] ?? '') : null,
            'parent' => $this->parent($table, $row),
        ];
    }

    /**
     * Where the placement appears, as far as it can be worked out: the page, the article and the news entry, with their aliases and public
     * address. Content elements are followed up through nested elements (Contao 5 element groups) to their article or news entry.
     *
     * @return array{page_id:?int,page_alias:?string,article_id:?int,news_id:?int,news_alias:?string,parent_table:?string,parent_id:?int,url:?string}
     */
    private function parent(string $table, array $row): array
    {
        $out = ['page_id' => null, 'page_alias' => null, 'article_id' => null, 'news_id' => null, 'news_alias' => null, 'parent_table' => null, 'parent_id' => null, 'url' => null];
        $id = (int) $row['id'];

        if ('tl_page' === $table) {
            $out['page_id'] = $id;
        } elseif ('tl_article' === $table) {
            $out['article_id'] = $id;
            $out['page_id'] = isset($row['pid']) ? (int) $row['pid'] : null;
        } elseif ('tl_news' === $table) {
            $out['news_id'] = $id;
        } elseif ('tl_content' === $table) {
            $ptable = (string) ($row['ptable'] ?? '') ?: 'tl_article'; // an empty ptable is an article element (Contao 3 rows)
            $pid = (int) ($row['pid'] ?? 0);
            for ($depth = 0; 'tl_content' === $ptable && $pid > 0 && $depth < 10; ++$depth) {
                $up = $this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [$pid]);
                if (false === $up) {
                    break;
                }
                $ptable = (string) $up['ptable'] ?: 'tl_article';
                $pid = (int) $up['pid'];
            }
            if ('tl_article' === $ptable) {
                $out['article_id'] = $pid;
                $page = $this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [$pid]);
                $out['page_id'] = false === $page ? null : (int) $page;
            } elseif ('tl_news' === $ptable) {
                $out['news_id'] = $pid;
            } else {
                $out['parent_table'] = $ptable;
                $out['parent_id'] = $pid;
            }
        } elseif (isset($row['pid'])) {
            $out['parent_table'] = isset($row['ptable']) && '' !== (string) $row['ptable'] ? (string) $row['ptable'] : null;
            $out['parent_id'] = (int) $row['pid'];
        }

        if (null !== $out['news_id']) {
            [$out['news_alias'], $out['url']] = $this->addressOf('tl_news', $out['news_id']);
        } elseif (null !== $out['page_id']) {
            [$out['page_alias'], $out['url']] = $this->addressOf('tl_page', $out['page_id']);
        }

        return $out;
    }

    /** @return array{0:?string,1:?string} alias and public address, cached per row */
    private function addressOf(string $table, int $id): array
    {
        $key = "$table.$id";
        if (!isset($this->parentCache[$key])) {
            $alias = $this->db->fetchOne("SELECT alias FROM $table WHERE id = ?", [$id]);
            $url = 'tl_news' === $table ? $this->urls->news($id) : $this->urls->page($id);
            $this->parentCache[$key] = [false === $alias ? null : (string) $alias, $url];
        }

        return $this->parentCache[$key];
    }

    /** @return array{uuid:string,path:string}|null */
    private function fileByPath(string $path): ?array
    {
        $row = $this->db->fetchAssociative("SELECT HEX(uuid) AS uuid_hex, path FROM tl_files WHERE path = ? AND type = 'file'", [$path]);

        return false === $row ? null : ['uuid' => strtolower((string) $row['uuid_hex']), 'path' => (string) $row['path']];
    }

    /**
     * The source path recorded for a variant Contao has not generated yet (contao/image DeferredImageStorageFilesystem: assets/images/deferred/
     * <variant path>.json, whose 'path' is the source, relative to assets/images or absolute). Read only; null when there is no such record.
     */
    private function deferredSource(string $variant): ?string
    {
        $file = $this->projectDir.'/assets/images/deferred/'.$variant.'.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!\is_array($data) || !\is_string($data['path'] ?? null)) {
            return null;
        }
        $source = $data['path'];
        if (!str_starts_with($source, '/')) {
            $source = $this->projectDir.'/assets/images/'.$source;
        }

        return self::relativeTo($this->projectDir, $source);
    }

    /** $path relative to $base, with . and .. collapsed, without touching the filesystem. Null when it is outside $base. */
    private static function relativeTo(string $base, string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $full = implode('/', $parts);
        $baseNorm = trim($base, '/');

        return str_starts_with($full, $baseNorm.'/') ? substr($full, \strlen($baseNorm) + 1) : null;
    }

    /**
     * Width and height from the file's own header (getimagesize reads only the header) or, for SVG, the root element's width/height or viewBox.
     * Nulls when the file is not on this server's disk or carries no size.
     *
     * @return array{0:?int,1:?int}
     */
    private function dimensions(string $path, string $extension): array
    {
        $file = $this->projectDir.'/'.$path;
        if (!is_file($file) || !is_readable($file)) {
            return [null, null];
        }
        if ('svg' === $extension) {
            $head = (string) @file_get_contents($file, false, null, 0, 8192);
            if (!preg_match('/<svg\b[^>]*>/i', $head, $tag)) {
                return [null, null];
            }
            $w = preg_match('/\swidth\s*=\s*["\']\s*([\d.]+)\s*(px)?\s*["\']/i', $tag[0], $mw) ? (int) round((float) $mw[1]) : null;
            $h = preg_match('/\sheight\s*=\s*["\']\s*([\d.]+)\s*(px)?\s*["\']/i', $tag[0], $mh) ? (int) round((float) $mh[1]) : null;
            if ((null === $w || null === $h) && preg_match('/\sviewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["\']/i', $tag[0], $vb)) {
                $w ??= (int) round((float) $vb[1]);
                $h ??= (int) round((float) $vb[2]);
            }

            return [$w ?: null, $h ?: null];
        }
        $size = @getimagesize($file);

        return false === $size ? [null, null] : [(int) $size[0] ?: null, (int) $size[1] ?: null];
    }

    /** @return array<string, array<string, string>> language => field => value (alt, title, caption, link, license, ...) */
    private function meta(mixed $raw): array
    {
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }
        $meta = @unserialize($raw, ['allowed_classes' => false]);
        if (!\is_array($meta)) {
            return [];
        }
        $out = [];
        foreach ($meta as $language => $fields) {
            if (!\is_array($fields)) {
                continue;
            }
            foreach ($fields as $name => $value) {
                if (\is_scalar($value)) {
                    $out[(string) $language][(string) $name] = (string) $value;
                }
            }
        }

        return $out;
    }

    private static function truthy(mixed $value): bool
    {
        return null !== $value && '' !== $value && '0' !== (string) $value && false !== $value;
    }

    private function q(string $identifier): string
    {
        return $this->db->quoteIdentifier($identifier);
    }
}
