<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;

/**
 * v0.4: reads and writes a page's meta title (tl_page.pageTitle) and meta description (tl_page.description). Both column names are the same on
 * Contao 4.13 and 5.x (checked in the sources of 4.13.56 and 5.7 on 2 November 2026; not run against a live site). The write goes through
 * PageModel::save(), Contao's own model path, never raw SQL. Contao's model save does not empty the page cache, so after a save the page's
 * cache tag (contao.db.tl_page.<id>) is invalidated through the cache tag manager where the site has one, and the answer says whether that happened.
 */
class PageMetaWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly ?object $tagManager,
    ) {
    }

    /**
     * Pages whose alias matches the path of $url (or the home page for an empty path). More than one row means several site roots share the alias.
     *
     * @return list<array{id:int,alias:string,type:string,pageTitle:string,description:string}>
     */
    public function findByUrl(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = (string) preg_replace('/\.(html|htm)$/i', '', $path);
        $alias = '' === $path ? 'index' : rawurldecode($path);

        $rows = $this->db->fetchAllAssociative('SELECT id, alias, type, pageTitle, description FROM tl_page WHERE alias = ? ORDER BY id', [$alias]);

        return array_map($this->shape(...), $rows);
    }

    /** @return array{id:int,alias:string,type:string,pageTitle:string,description:string}|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, alias, type, pageTitle, description FROM tl_page WHERE id = ?', [$id]);

        return false === $row ? null : $this->shape($row);
    }

    /**
     * Saves the given fields and returns the page as it was before, the page as it is now and whether the cache tag was invalidated.
     *
     * @param array{pageTitle?:string,description?:string} $fields
     *
     * @return array{previous:array{pageTitle:string,description:string},id:int,pageTitle:string,description:string,cache_cleared:bool}
     */
    public function save(int $id, array $fields): array
    {
        $this->framework->initialize();
        $adapter = $this->framework->getAdapter(PageModel::class);
        $model = $adapter->findByPk($id);
        if (null === $model) {
            throw new \RuntimeException('Contao has no page '.$id.'.');
        }

        $previous = ['pageTitle' => (string) $model->pageTitle, 'description' => (string) $model->description];
        foreach ($fields as $name => $value) {
            $model->$name = $value;
        }
        $model->tstamp = time();
        $model->save();

        $now = $this->find($id);

        return ['previous' => $previous, 'id' => $id, 'pageTitle' => $now['pageTitle'] ?? '', 'description' => $now['description'] ?? '', 'cache_cleared' => $this->clearCache($id)];
    }

    private function clearCache(int $id): bool
    {
        if (null === $this->tagManager || !method_exists($this->tagManager, 'invalidateTags')) {
            return false;
        }
        $this->tagManager->invalidateTags(['contao.db.tl_page.'.$id]);

        return true;
    }

    /** @return array{id:int,alias:string,type:string,pageTitle:string,description:string} */
    private function shape(array $row): array
    {
        return ['id' => (int) $row['id'], 'alias' => (string) $row['alias'], 'type' => (string) $row['type'], 'pageTitle' => (string) $row['pageTitle'], 'description' => (string) $row['description']];
    }
}
