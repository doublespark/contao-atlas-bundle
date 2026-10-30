<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsModel;
use Doctrine\DBAL\Connection;

/**
 * Creates and updates tl_news (and the body in tl_content) through DBAL. Entries are tracked in tl_atlas_news_ref,
 * which is how the API knows which entries it created and finds a duplicate by atlas_ref.
 *
 * Categories (v0.2): not core Contao. The writer looks for the news categories extension's own data model in the schema and
 * uses it as found: a tl_news_category table, and the relation table that links it to tl_news (news_id, category_id), which is
 * what the extension reads its front end from. If tl_news also has a `categories` column that can hold text, the serialised id
 * list the back end's own widget would save is written there too. With no such mechanism, category_ids is refused (see
 * unprocessable()), never dropped. Not run against a live site: the extension's table and column names are matched by shape.
 *
 * Categories, column mode (30 Oct 2026): a site with its own category dropdown (Doublespark: tl_news.primary_category, a whole-number id) sets
 * category_mode: column and category_column: <name> in config/atlas_api.yaml. category_ids must then hold exactly one whole number, which is
 * written to that column in the column's real type, and the answer echoes category_ids as [value] read back from the column. The extension
 * mechanism is not used at all in this mode. The label for an id is the site's own business (its Options class); the bundle stores the id.
 */
class NewsWriter
{
    /** API field => tl_news column, for the plain copies. */
    private const COLUMNS = [
        'headline' => 'headline',
        'teaser' => 'teaser',
        'meta_title' => 'pageTitle',
        'meta_description' => 'description',
        'author_id' => 'author',
    ];

    /** tl_news columns that hold a yes/no flag or an optional timestamp: written by the column's real type (see typed()). */
    private const FLAGS = ['published', 'featured', 'hofff_st'];

    /**
     * v0.3 optional fields => the tl_news column each is written to, only where this site's tl_news HAS that column (found by name):
     * featured is core on 4.13 and 5.x; linkText is core on 5.x only (checked in Contao's source at 4.13.56 and 5.7, 30 Oct 2026); the two
     * Open Graph fields are the hofff/contao-social-tags extension's columns, which only take effect with its hofff_st switch on and an
     * og type set (see optionalColumns()). A field whose column is missing is reported back as unsupported, never silently dropped.
     */
    public const OPTIONAL_FIELDS = ['featured' => 'featured', 'link_text' => 'linkText', 'og_title' => 'hofff_st_og_title', 'og_description' => 'hofff_st_og_description'];
    private const OPTIONAL_TIMES = ['start', 'stop'];

    /** tl_news columns the bundle writes itself or Contao owns: a category_column may not name one of these. */
    private const PROTECTED_COLUMNS = ['id', 'pid', 'tstamp', 'sorting', 'date', 'time', 'alias', 'source', 'published', 'start', 'stop', 'headline', 'teaser', 'author', 'pagetitle', 'description'];

    /** @var array<string,\Doctrine\DBAL\Schema\Column>|null lower-cased column name => column, for tl_news */
    private ?array $newsColumns = null;

    /** @var array{relation:string,blob:?string}|null the categories mechanism found in the schema, once probed */
    private ?array $categoryMechanism = null;
    private bool $categoriesProbed = false;
    private string $categoryReason = '';

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly ?object $contentUrlGenerator,
        private readonly string $timezone,
        private readonly int $defaultAuthorId = 0,
        private readonly string $categoryMode = '',
        private readonly string $categoryColumn = '',
    ) {
    }

    /** @return array{id:int,url:?string}|null */
    public function findByRef(string $ref): ?array
    {
        $id = $this->db->fetchOne('SELECT news_id FROM tl_atlas_news_ref WHERE atlas_ref = ?', [$ref]);

        return false === $id ? null : $this->result((int) $id);
    }

    public function created(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT id FROM tl_atlas_news_ref WHERE news_id = ?', [$id]);
    }

    public function archiveExists(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT id FROM tl_news_archive WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $in validated input */
    public function create(array $in): array
    {
        $author = $this->authorFor($in['author_id'] ?? null);
        if (null === $author) {
            unset($in['author_id']); // no author anywhere: the column is left to its own default, never an explicit 0
        } else {
            $in['author_id'] = $author;
        }
        $this->categories(); // probed before the transaction opens

        $this->db->transactional(function () use ($in, &$id): void {
            $now = time();
            $when = $this->whenFrom($in['publish_at'] ?? null) ?? $now;
            $published = (bool) ($in['published'] ?? false);

            $row = $this->plain($in) + $this->optionalColumns($in, null) + [
                'pid' => (int) $in['archive_id'],
                'tstamp' => $now,
                'date' => $when,
                'time' => $when,
                'alias' => $this->uniqueAlias((string) ($in['alias'] ?? ''), (string) $in['headline'], (int) $in['archive_id'], 0),
                'source' => 'default',
            ] + $this->publishState($published, $in['publish_at'] ?? null, $when, $now);

            $this->db->insert('tl_news', $this->existingColumns($row));
            $id = (int) $this->db->lastInsertId();

            $contentId = 0;
            if (isset($in['body_html'])) {
                $contentId = $this->insertBody($id, (string) $in['body_html'], $now);
            }

            $this->db->insert('tl_atlas_news_ref', ['tstamp' => $now, 'atlas_ref' => $in['atlas_ref'], 'news_id' => $id, 'content_id' => $contentId]);

            if (isset($in['elements'])) {
                $this->replaceElements($id, $in['elements'], $now);
            }

            if ([] !== ($in['category_ids'] ?? [])) {
                $this->setCategories($id, $in['category_ids']);
            }
        });

        return $this->result($id);
    }

    /** @param array<string,mixed> $in validated input, every field optional */
    public function update(int $id, array $in): array
    {
        $this->categories(); // probed before the transaction opens

        $this->db->transactional(function () use ($id, $in): void {
            $now = time();
            $current = $this->db->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', [$id]);
            $set = $this->plain($in) + $this->optionalColumns($in, $current) + ['tstamp' => $now];

            if (isset($in['archive_id'])) {
                $set['pid'] = (int) $in['archive_id'];
            }
            if (isset($in['alias'])) {
                $set['alias'] = $this->uniqueAlias((string) $in['alias'], (string) ($in['headline'] ?? $current['headline']), (int) ($set['pid'] ?? $current['pid']), $id);
            }

            $when = null;
            if (array_key_exists('publish_at', $in)) {
                $when = $this->whenFrom($in['publish_at']);
                if (null !== $when) {
                    $set['date'] = $when;
                    $set['time'] = $when;
                }
            }
            if (array_key_exists('published', $in) || null !== $when) {
                $published = array_key_exists('published', $in) ? (bool) $in['published'] : \in_array(strtolower((string) $current['published']), ['1', 'true'], true);
                $set += $this->publishState($published, $when ? ($in['publish_at'] ?? null) : null, $when ?? (int) $current['date'], $now, (string) $current['start']);
            }

            $this->db->update('tl_news', $this->existingColumns($set), ['id' => $id]);

            if (isset($in['elements'])) {
                $this->replaceElements($id, $in['elements'], $now);
            } elseif (isset($in['body_html']) && \count($this->trackedElements($id)) > 1) {
                // The entry was sent as an element list and now arrives as one body: the list is replaced by that one element.
                $this->replaceElements($id, [['section_id' => '', 'headline' => '', 'html' => (string) $in['body_html']]], $now);
            } elseif (isset($in['body_html'])) {
                $contentId = (int) $this->db->fetchOne('SELECT content_id FROM tl_atlas_news_ref WHERE news_id = ?', [$id]);
                if ($contentId > 0 && false !== $this->db->fetchOne('SELECT id FROM tl_content WHERE id = ?', [$contentId])) {
                    $this->db->update('tl_content', ['text' => $in['body_html'], 'tstamp' => $now], ['id' => $contentId]);
                } else {
                    $this->db->update('tl_atlas_news_ref', ['content_id' => $this->insertBody($id, (string) $in['body_html'], $now)], ['news_id' => $id]);
                }
            }

            // category_ids replaces the entry's categories when sent (an empty list clears them); absent leaves them alone.
            if (array_key_exists('category_ids', $in) && null !== $this->categories()) {
                $this->setCategories($id, $in['category_ids']);
            }
        });

        return $this->result($id);
    }

    /**
     * Deletes an entry this API created, with everything that belongs to it: its content elements (all of them, as the back end's own
     * delete does), its category links, and its tl_atlas_news_ref row. Only reachable for an id in tl_atlas_news_ref whose atlas_ref the
     * caller has repeated (see NewsController::delete), so an entry a person made can never be deleted through the API.
     */
    public function delete(int $id): void
    {
        $mechanism = $this->categories(); // probed before the transaction opens

        $this->db->transactional(function () use ($id, $mechanism): void {
            $this->db->executeStatement("DELETE FROM tl_content WHERE ptable = 'tl_news' AND pid = ?", [$id]);
            if (null !== $mechanism) {
                $this->db->executeStatement('DELETE FROM '.$this->db->quoteIdentifier($mechanism['relation']).' WHERE news_id = ?', [$id]);
            }
            $this->db->executeStatement('DELETE FROM tl_news WHERE id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM tl_atlas_news_ref WHERE news_id = ?', [$id]);
        });
    }

    public function refFor(int $id): ?string
    {
        $ref = $this->db->fetchOne('SELECT atlas_ref FROM tl_atlas_news_ref WHERE news_id = ?', [$id]);

        return false === $ref ? null : (string) $ref;
    }

    /**
     * Which of the optional fields sent this site can store: [applied, unsupported], in the order sent. category_ids counts as applied
     * whenever it was sent, because unprocessable() has already refused it with a 422 on a site that cannot store it.
     *
     * @return array{0:list<string>,1:list<string>}
     */
    public function optionalReport(array $in): array
    {
        $applied = $unsupported = [];
        foreach (array_keys(self::OPTIONAL_FIELDS) as $field) {
            if (array_key_exists($field, $in)) {
                if (null !== ($this->newsColumns()[strtolower(self::OPTIONAL_FIELDS[$field])] ?? null)) {
                    $applied[] = $field;
                } else {
                    $unsupported[] = $field;
                }
            }
        }
        if (array_key_exists('category_ids', $in)) {
            $applied[] = 'category_ids';
        }

        return [$applied, $unsupported];
    }

    /** How many content elements this API holds for the entry: what an element list was stored as. */
    public function elementCount(int $id): int
    {
        return \count($this->trackedElements($id));
    }

    /**
     * What the API answers with. id and url as in v0.1; author_id and (only where the site has the categories mechanism) category_ids
     * are read back from the database, so the answer shows what was stored, not what was asked.
     *
     * @return array{id:int,url:?string,author_id:?int,category_ids?:list<int>}
     */
    private function result(int $id): array
    {
        $author = (int) $this->db->fetchOne('SELECT author FROM tl_news WHERE id = ?', [$id]);
        $out = ['id' => $id, 'url' => $this->url($id), 'author_id' => $author > 0 ? $author : null];

        if ($this->columnMode()) {
            // Column mode: the single value stored in the category column, read back. An unset column (0 or NULL) is an empty list.
            $column = $this->categoryColumn();
            if (null !== $column) {
                $value = (int) $this->db->fetchOne('SELECT '.$this->db->quoteIdentifier($column->getName()).' FROM tl_news WHERE id = ?', [$id]);
                $out['category_ids'] = $value > 0 ? [$value] : [];
            }
        } elseif (null !== $mechanism = $this->categories()) {
            $ids = $this->db->fetchFirstColumn('SELECT category_id FROM '.$this->db->quoteIdentifier($mechanism['relation']).' WHERE news_id = ?', [$id]);
            $ids = array_map('intval', $ids);
            sort($ids);
            $out['category_ids'] = $ids;
        }

        return $out;
    }

    /**
     * A reason the site cannot do what a well-formed request asks, or null. Checked before anything is written.
     * 422 material: an author_id that is not a back-end user; category_ids (non-empty) on a site with no usable categories
     * mechanism, or naming a category the site does not have. An empty category_ids asks for nothing and is always accepted.
     *
     * @param array<string,mixed> $in validated input
     */
    public function unprocessable(array $in): ?string
    {
        if (isset($in['author_id']) && !$this->userExists((int) $in['author_id'])) {
            return 'author_id does not name a back-end user on this site.';
        }

        if ($this->columnMode()) {
            return array_key_exists('category_ids', $in) ? $this->columnProblem($in['category_ids']) : null;
        }

        $ids = array_values(array_unique($in['category_ids'] ?? []));
        if ([] === $ids) {
            return null;
        }
        if (null === $mechanism = $this->categories()) {
            return 'category_ids was sent but this site has no usable news categories mechanism ('.$this->categoryReason.'). Nothing was saved.';
        }

        $marks = implode(',', array_fill(0, \count($ids), '?'));
        $known = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_news_category WHERE id IN ('.$marks.')', $ids));
        $missing = array_values(array_diff($ids, $known));

        return [] === $missing ? null : 'category_ids names categories this site does not have: '.implode(', ', $missing).'. Nothing was saved.';
    }

    /** True when this site stores its category in one tl_news column (category_mode: column). */
    private function columnMode(): bool
    {
        return 'column' === $this->categoryMode;
    }

    /** The tl_news column for column mode, or null when it is not configured or does not exist. */
    private function categoryColumn(): ?\Doctrine\DBAL\Schema\Column
    {
        return '' === $this->categoryColumn ? null : ($this->newsColumns()[strtolower($this->categoryColumn)] ?? null);
    }

    /**
     * 422 text for category_ids in column mode, or null when it can be written. The rule: exactly one whole number (1 or more), because the site
     * stores a single category in a single column; and the configured column must exist in tl_news and not be one the bundle or Contao owns.
     *
     * @param list<int> $ids already checked to be a list of positive whole numbers
     */
    private function columnProblem(array $ids): ?string
    {
        if (1 !== \count($ids)) {
            return 'category_ids must contain exactly one whole number on this site (category_mode is column: the category is stored in the single tl_news column '
                .('' === $this->categoryColumn ? '(not set)' : $this->categoryColumn).'). Nothing was saved.';
        }
        if ('' === $this->categoryColumn) {
            return 'category_mode is column but category_column is not set in config/atlas_api.yaml. Nothing was saved.';
        }
        if (null === $this->categoryColumn() || \in_array(strtolower($this->categoryColumn), self::PROTECTED_COLUMNS, true)) {
            return 'category_mode is column but tl_news has no usable column called '.$this->categoryColumn.' (it is missing, or it is one the bundle writes itself). Nothing was saved.';
        }

        return null;
    }

    private function userExists(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT id FROM tl_user WHERE id = ?', [$id]);
    }

    /**
     * The author to write: the one sent, else the configured default_author_id (doublespark_contao_atlas.default_author_id, set in the
     * site's own config/config.yaml) when that user exists, else null.
     * Core Contao's tl_news_archive has no author field (not checked against this site), so there is no
     * archive level default to fall back to. Atlas keeps a per-site default and sends it as author_id on every post.
     */
    private function authorFor(mixed $given): ?int
    {
        if (null !== $given && (int) $given > 0) {
            return (int) $given;
        }

        return $this->defaultAuthorId > 0 && $this->userExists($this->defaultAuthorId) ? $this->defaultAuthorId : null;
    }

    /**
     * The categories mechanism, or null with categoryReason saying why not. Looked for by shape, once per request:
     *  - a tl_news_category table, and
     *  - a relation table (tl_news_categories, else any other tl_news_categor* table) with news_id and category_id columns.
     * @return array{relation:string,blob:?string}|null
     */
    private function categories(): ?array
    {
        if ($this->categoriesProbed) {
            return $this->categoryMechanism;
        }
        $this->categoriesProbed = true;

        if ($this->columnMode()) {
            $this->categoryReason = 'category_mode is column, so the news categories extension is not used';

            return null;
        }

        $schema = $this->db->createSchemaManager();
        if (!$schema->tablesExist(['tl_news_category'])) {
            $this->categoryReason = 'there is no tl_news_category table';

            return null;
        }

        $names = array_map('strtolower', array_values($schema->listTableNames()));
        $candidates = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'tl_news_categor') && 'tl_news_category' !== $n));
        usort($candidates, static fn (string $a, string $b): int => ('tl_news_categories' === $b) <=> ('tl_news_categories' === $a));

        foreach ($candidates as $table) {
            $columns = array_map(static fn ($c): string => strtolower($c->getName()), array_values($schema->listTableColumns($table)));
            if (\in_array('news_id', $columns, true) && \in_array('category_id', $columns, true)) {
                $blob = null;
                $column = $this->newsColumns()['categories'] ?? null;
                if (null !== $column && ($column->getType() instanceof \Doctrine\DBAL\Types\BlobType
                    || $column->getType() instanceof \Doctrine\DBAL\Types\TextType
                    || $column->getType() instanceof \Doctrine\DBAL\Types\StringType)) {
                    $blob = $column->getName();
                }

                return $this->categoryMechanism = ['relation' => $table, 'blob' => $blob];
            }
        }

        $this->categoryReason = 'tl_news_category exists but no relation table with news_id and category_id columns was found';

        return null;
    }

    /** @param list<int> $ids the entry's categories after this call, replacing what it had */
    private function setCategories(int $newsId, array $ids): void
    {
        $mechanism = $this->categories();
        if (null === $mechanism) {
            return; // unprocessable() has already refused a non-empty list; an empty one asks for nothing
        }
        $ids = array_values(array_unique($ids));
        $relation = $this->db->quoteIdentifier($mechanism['relation']);

        $this->db->executeStatement('DELETE FROM '.$relation.' WHERE news_id = ?', [$newsId]);
        foreach ($ids as $categoryId) {
            $this->db->executeStatement('INSERT INTO '.$relation.' (news_id, category_id) VALUES (?, ?)', [$newsId, $categoryId]);
        }
        if (null !== $mechanism['blob']) {
            $this->db->update('tl_news', [$mechanism['blob'] => [] === $ids ? null : serialize(array_map('strval', $ids))], ['id' => $newsId]);
        }
    }

    /** Absolute URL of the entry, or null when Contao cannot work one out (no reader page, page unpublished). */
    private function url(int $id): ?string
    {
        try {
            $this->framework->initialize();
            $model = NewsModel::findByPk($id);
            if (null === $model) {
                return null;
            }
            if (null !== $this->contentUrlGenerator) { // Contao 5.3 and later
                return $this->contentUrlGenerator->generate($model, [], 1); // 1 = UrlGeneratorInterface::ABSOLUTE_URL
            }
            if (method_exists(\Contao\News::class, 'generateNewsUrl')) { // Contao 4.13 to 5.2
                return \Contao\News::generateNewsUrl($model, false, true);
            }
        } catch (\Throwable) {
            // Fall through: a missing URL must not fail a write that has already succeeded.
        }

        return null;
    }

    private function insertBody(int $newsId, string $html, int $now): int
    {
        $this->db->insert('tl_content', [
            'pid' => $newsId, 'ptable' => 'tl_news', 'sorting' => 128, 'tstamp' => $now, 'type' => 'text', 'text' => $html,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The tl_content ids this API created for an entry and that still exist under it: content_id (one body, v0.1 and v0.2) and content_ids
     * (an element list, v0.3), in their stored order. Only ids still parented to this entry count, so a person's own elements are never touched.
     *
     * @return list<int>
     */
    private function trackedElements(int $newsId): array
    {
        $ref = $this->db->fetchAssociative('SELECT * FROM tl_atlas_news_ref WHERE news_id = ?', [$newsId]);
        if (false === $ref) {
            return [];
        }
        $ids = '' !== (string) ($ref['content_ids'] ?? '') ? array_map('intval', explode(',', (string) $ref['content_ids'])) : [(int) $ref['content_id']];
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
        if ([] === $ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, \count($ids), '?'));
        $alive = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM tl_content WHERE ptable = 'tl_news' AND pid = ? AND id IN ($marks)", [$newsId, ...$ids]));

        return array_values(array_filter($ids, static fn (int $i): bool => \in_array($i, $alive, true)));
    }

    /**
     * Replaces the elements this API created for an entry with the list given: one tl_content text element per block, in order, with its CSS id
     * and headline (an h2). Elements a person added to the entry in the back end are left alone. Needs the content_ids column that
     * contao:migrate adds with v0.3; without it nothing is written and the request fails with that reason.
     *
     * @param list<array{section_id:string,headline:string,html:string}> $elements
     */
    private function replaceElements(int $newsId, array $elements, int $now): void
    {
        $refColumns = array_map(static fn ($c): string => strtolower($c->getName()), array_values($this->db->createSchemaManager()->listTableColumns('tl_atlas_news_ref')));
        if (!\in_array('content_ids', $refColumns, true)) {
            throw new \RuntimeException('tl_atlas_news_ref has no content_ids column yet: run contao:migrate after installing bundle v0.3.');
        }

        $old = $this->trackedElements($newsId);
        if ([] !== $old) {
            $marks = implode(',', array_fill(0, \count($old), '?'));
            $this->db->executeStatement("DELETE FROM tl_content WHERE ptable = 'tl_news' AND pid = ? AND id IN ($marks)", [$newsId, ...$old]);
        }

        $contentColumns = [];
        foreach (array_values($this->db->createSchemaManager()->listTableColumns('tl_content')) as $column) {
            $contentColumns[strtolower($column->getName())] = $column->getName();
        }

        $ids = [];
        foreach (array_values($elements) as $n => $element) {
            $row = ['pid' => $newsId, 'ptable' => 'tl_news', 'sorting' => 128 * ($n + 1), 'tstamp' => $now, 'type' => 'text', 'text' => $element['html']];
            if ('' !== $element['headline']) {
                $row['headline'] = serialize(['value' => $element['headline'], 'unit' => 'h2']);
            }
            if ('' !== $element['section_id']) {
                $row['cssID'] = serialize([$element['section_id'], '']);
            }
            $row = array_filter($row, static fn (string $k): bool => isset($contentColumns[strtolower($k)]), ARRAY_FILTER_USE_KEY);
            $this->db->insert('tl_content', $row);
            $ids[] = (int) $this->db->lastInsertId();
        }

        $this->db->update('tl_atlas_news_ref', ['content_id' => $ids[0] ?? 0, 'content_ids' => implode(',', $ids)], ['news_id' => $newsId]);
    }

    /**
     * The optional v0.3 fields as tl_news columns, for the ones this site has (optionalReport() says which). The Open Graph values switch on
     * the social tags extension for the entry (hofff_st) and give it the og type 'article' when it has none, because the extension ignores
     * its title and description otherwise and its type is a required field in the back end.
     *
     * @param array<string,mixed>|null $current the entry's row when updating, null when creating
     * @return array<string,mixed>
     */
    private function optionalColumns(array $in, ?array $current): array
    {
        $columns = $this->newsColumns();
        $out = [];
        foreach (self::OPTIONAL_FIELDS as $field => $column) {
            if (array_key_exists($field, $in) && null !== ($columns[strtolower($column)] ?? null)) {
                $out[$column] = 'featured' === $field ? (bool) $in[$field] : (string) $in[$field];
            }
        }
        if ((isset($out['hofff_st_og_title']) || isset($out['hofff_st_og_description'])) && null !== ($columns['hofff_st'] ?? null)) {
            $out['hofff_st'] = true;
            if (null !== ($columns['hofff_st_og_type'] ?? null) && '' === (string) ($current['hofff_st_og_type'] ?? '')) {
                $out['hofff_st_og_type'] = 'article';
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function plain(array $in): array
    {
        $out = [];
        foreach (self::COLUMNS as $field => $column) {
            if (array_key_exists($field, $in)) {
                $out[$column] = 'author' === $column ? (int) $in[$field] : (string) $in[$field];
            }
        }
        // Column mode: unprocessable() has already checked there is exactly one id and that the column exists. Written in the column's real type.
        if ($this->columnMode() && isset($in['category_ids'][0]) && null !== $column = $this->categoryColumn()) {
            $numeric = $column->getType() instanceof \Doctrine\DBAL\Types\IntegerType
                || $column->getType() instanceof \Doctrine\DBAL\Types\SmallIntType
                || $column->getType() instanceof \Doctrine\DBAL\Types\BigIntType;
            $out[$column->getName()] = $numeric ? (int) $in['category_ids'][0] : (string) $in['category_ids'][0];
        }

        return $out;
    }

    /**
     * published and start for a row. Contao shows an entry when published is 1 and start is empty or in the past, so a
     * future publish_at becomes start. Unpublishing clears published and leaves start alone.
     *
     * Returns the LOGICAL values (bool, and a timestamp string or ''): existingColumns() turns them into what the column
     * holds, because published is char(1) on Contao 4.13 ('1' or '') and an integer or boolean column on 5.x (1 or 0).
     *
     * @return array{published:bool,start:string}
     */
    private function publishState(bool $published, mixed $publishAt, int $when, int $now, string $currentStart = ''): array
    {
        if (!$published) {
            return ['published' => false, 'start' => $currentStart];
        }

        return ['published' => true, 'start' => (null !== $publishAt && $when > $now) ? (string) $when : ''];
    }

    /**
     * Unix time for a publish_at string. A string with its own offset or Z keeps it; a plain local date and time is read as
     * Europe/London (the configured zone), so a July value is BST and a January value is GMT without any fixed offset here.
     */
    public function whenFrom(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (new \DateTimeImmutable((string) $value, new \DateTimeZone($this->timezone)))->getTimestamp();
    }

    private function uniqueAlias(string $given, string $headline, int $archive, int $selfId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($given !== '' ? $given : $headline)), '-');
        $base = '' === $base ? 'news' : substr($base, 0, 120);
        $alias = $base;

        for ($n = 2; false !== $this->db->fetchOne('SELECT id FROM tl_news WHERE alias = ? AND id != ?', [$alias, $selfId]); ++$n) {
            $alias = $base.'-'.$n;
        }

        return $alias;
    }

    /** @return array<string,\Doctrine\DBAL\Schema\Column> lower-cased column name => column, for tl_news */
    private function newsColumns(): array
    {
        // Column::getName() rather than the array keys: the keys are names on DBAL 3 but not guaranteed to be on DBAL 4.
        if (null === $this->newsColumns) {
            $this->newsColumns = [];
            foreach (array_values($this->db->createSchemaManager()->listTableColumns('tl_news')) as $column) {
                $this->newsColumns[strtolower($column->getName())] = $column;
            }
        }

        return $this->newsColumns;
    }

    /**
     * Drops keys for columns this Contao version's tl_news does not have (pageTitle and description are not in every version),
     * and writes the flag and optional-time columns in the type the column actually has.
     */
    private function existingColumns(array $row): array
    {
        $columns = $this->newsColumns();

        $out = [];
        foreach ($row as $name => $value) {
            $column = $columns[strtolower($name)] ?? null;
            if (null === $column) {
                continue;
            }
            if (\in_array($name, self::FLAGS, true)) {
                $value = self::typed($column, (bool) $value, true);
            } elseif (\in_array($name, self::OPTIONAL_TIMES, true)) {
                $empty = '' === (string) $value || '0' === (string) $value;
                $value = $empty ? self::typed($column, false, false) : self::typed($column, (string) $value, false);
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * A flag (true/false) or an optional timestamp (a string, or false for none) in the column's real type. Integer, small, big
     * integer and boolean columns get 1/0 (a timestamp as an int); anything else (char, varchar, text) gets '1'/'' (a timestamp as
     * its digits). A nullable column gets null for "none" on a timestamp. Contao 5.x moved published to an integer/boolean column;
     * 4.13 still has char(1), and MySQL strict mode rejects '' for an integer column (SQLSTATE 22007).
     *
     * @return int|string|null
     */
    private static function typed(\Doctrine\DBAL\Schema\Column $column, bool|string $value, bool $isFlag): int|string|null
    {
        $type = $column->getType();
        $numeric = $type instanceof \Doctrine\DBAL\Types\IntegerType
            || $type instanceof \Doctrine\DBAL\Types\SmallIntType
            || $type instanceof \Doctrine\DBAL\Types\BigIntType
            || $type instanceof \Doctrine\DBAL\Types\BooleanType;

        if ($isFlag) {
            return $numeric ? (int) $value : ($value ? '1' : '');
        }
        if (false === $value) {
            return $column->getNotnull() ? ($numeric ? 0 : '') : null;
        }

        return $numeric ? (int) $value : (string) $value;
    }
}
