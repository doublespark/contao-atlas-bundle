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
     * v0.8. The pages $url points at, resolved the way Contao routes a request: the URL's host picks the site root (a root whose dns is that host, then
     * its www or apex twin, then a root with an empty dns, which answers any host), the root's URL prefix (language) and URL suffix are taken off the
     * path, and what is left is the alias (an empty path is the home page, alias index). A page counts only under the root it belongs to, found by
     * walking up the page tree. More than one row means the address is still ambiguous inside the chosen root(s); the controller refuses those with 409.
     * A URL with no host (a bare path) is not filtered by host. Implemented here, not through Contao's page finder: it routes a live request and takes
     * no URL, and its API differs between 4.13 and 5.x (inference; Contao's source is not on the build machine).
     *
     * @return list<array{id:int,alias:string,type:string,pageTitle:string,description:string}>
     */
    public function findByUrl(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rawurldecode(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        $roots = [];
        foreach ($this->db->fetchAllAssociative("SELECT * FROM tl_page WHERE type = 'root' ORDER BY id") as $root) {
            $roots[(int) $root['id']] = $root;
        }

        // Which roots can answer this host: the best-ranked group only (an exact dns beats the www/apex twin beats an empty dns).
        $eligible = $roots;
        if ('' !== $host) {
            $ranked = [];
            foreach ($roots as $id => $root) {
                $rank = $this->hostRank($host, (string) ($root['dns'] ?? ''));
                if (null !== $rank) {
                    $ranked[$rank][$id] = $root;
                }
            }
            ksort($ranked);
            $eligible = [] === $ranked ? [] : reset($ranked);
        }

        $found = [];
        foreach ($eligible as $rootId => $root) {
            $alias = $this->aliasUnder($root, $path);
            if (null === $alias) {
                continue;
            }
            $aliases = '' === $alias ? ['index', '/'] : [$alias];
            $in = implode(', ', array_fill(0, \count($aliases), '?'));
            $rows = $this->db->fetchAllAssociative("SELECT id, pid, alias, type, pageTitle, description FROM tl_page WHERE alias IN ($in) AND type NOT IN ('root', 'folder') ORDER BY id", $aliases);
            foreach ($rows as $row) {
                if ($this->rootOf((int) $row['pid'], $roots) === $rootId) {
                    $found[(int) $row['id']] = $this->shape($row);
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    /** 0 exact dns, 1 www/apex twin, 2 empty dns (answers any host), null when the root's dns is some other host. */
    private function hostRank(string $host, string $dns): ?int
    {
        $dns = strtolower(trim($dns));
        if ('' === $dns) {
            return 2;
        }
        $dns = (string) preg_replace('/:\d+$/', '', $dns);
        if ($dns === $host) {
            return 0;
        }
        $bare = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;

        return $bare($dns) === $bare($host) ? 1 : null;
    }

    /** The alias this path means under the root: its URL prefix and URL suffix taken off. Null when the path cannot belong to this root. */
    private function aliasUnder(array $root, string $path): ?string
    {
        $prefix = trim((string) ($root['urlPrefix'] ?? ''), '/');
        if ('' !== $prefix) {
            if ($path === $prefix) {
                $path = '';
            } elseif (str_starts_with($path, $prefix.'/')) {
                $path = substr($path, \strlen($prefix) + 1);
            } else {
                return null;
            }
        }
        // The root's own suffix (a root with the column empty has none). Lenient: a path that lacks it still resolves, because the address was
        // handed over by a person; a site with no such column at all (very old Contao) gets the old .html/.htm strip.
        if (\array_key_exists('urlSuffix', $root)) {
            $suffix = (string) $root['urlSuffix'];
            if ('' !== $suffix && '/' !== $suffix && str_ends_with($path, $suffix)) {
                $path = substr($path, 0, -\strlen($suffix));
            }
        } else {
            $path = (string) preg_replace('/\.(html|htm)$/i', '', $path);
        }

        return trim($path, '/');
    }

    /** @param array<int,array> $roots */
    private function rootOf(int $pid, array $roots): ?int
    {
        for ($depth = 0; $depth < 50 && $pid > 0; ++$depth) {
            if (isset($roots[$pid])) {
                return $pid;
            }
            $up = $this->db->fetchOne('SELECT pid FROM tl_page WHERE id = ?', [$pid]);
            if (false === $up) {
                return null;
            }
            $pid = (int) $up;
        }

        return null;
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
