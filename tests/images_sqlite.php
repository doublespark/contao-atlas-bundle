<?php
// Drives the bundle's real ImagesController and ImageInventory against SQLite in the shape of a Contao 5.7 site (Doublespark's custom image
// columns included), with information_schema attached as a second SQLite database so the real schema scan SQL runs. Not a site test: proves the
// PHP logic only. Same libraries as tests/api_sqlite.php (tests/lib/ or BUNDLE_TEST_LIB).

declare(strict_types=1);

namespace Contao\CoreBundle\Framework { class ContaoFramework { public function initialize(): void {} } }
namespace Contao { class NewsModel { public static function findByPk($id) { return null; } } class PageModel { public static function findByPk($id) { return null; } } }

namespace {
$L = getenv('BUNDLE_TEST_LIB') ?: __DIR__.'/lib';
require "$L/symfony-deprecation-contracts/function.php";
spl_autoload_register(static function (string $c) use ($L): void {
    $map = [
        'Doctrine\\DBAL\\' => "$L/doctrine-dbal/src/", 'Doctrine\\Deprecations\\' => "$L/doctrine-deprecations/src/",
        'Doctrine\\Common\\' => "$L/doctrine-event-manager/src/", 'Psr\\Cache\\' => "$L/php-fig-cache/src/", 'Psr\\Log\\' => "$L/php-fig-log/src/",
        'Symfony\\Component\\HttpFoundation\\' => "$L/symfony-http-foundation/", 'Symfony\\Polyfill\\Mbstring\\' => "$L/symfony-polyfill-mbstring/",
        'Doublespark\\ContaoAtlasBundle\\' => dirname(__DIR__).'/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($c, $prefix)) { $f = $dir.str_replace('\\', '/', substr($c, strlen($prefix))).'.php'; if (is_file($f)) { require $f; } return; }
    }
});
if (is_dir("$L/doctrine-deprecations/lib")) { spl_autoload_register(static function ($c) use ($L) { $f = "$L/doctrine-deprecations/lib/".str_replace('\\', '/', $c).'.php'; if (is_file($f)) require $f; }); }

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doublespark\ContaoAtlasBundle\Controller\ImagesController;
use Doublespark\ContaoAtlasBundle\Service\ContentUrls;
use Doublespark\ContaoAtlasBundle\Service\ImageInventory;
use Symfony\Component\HttpFoundation\Request;

$fails = 0;
function check(string $what, bool $ok, $detail = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ').$what.($ok ? '' : '  '.json_encode($detail, JSON_INVALID_UTF8_SUBSTITUTE))."\n"; if (!$ok) { ++$fails; } }

/** A table in SQLite, and its columns in the attached information_schema with their MySQL column types, the way the scan reads them. */
function table(Connection $db, string $name, array $cols): void {
    $defs = [];
    $pos = 0;
    foreach ($cols as $col => $type) {
        $defs[] = 'id' === $col ? 'id INTEGER PRIMARY KEY' : "\"$col\" ".(str_contains($type, 'binary') || str_contains($type, 'blob') ? 'BLOB' : (str_starts_with($type, 'int') || str_starts_with($type, 'tinyint') ? 'INTEGER' : 'TEXT')).' NULL';
        $db->insert('information_schema.COLUMNS', ['TABLE_SCHEMA' => 'site', 'TABLE_NAME' => $name, 'COLUMN_NAME' => $col, 'COLUMN_TYPE' => $type, 'ORDINAL_POSITION' => ++$pos]);
    }
    $db->executeStatement("CREATE TABLE $name (".implode(', ', $defs).')');
}
function png(int $w, int $h): string { return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $w, $h)."\x08\x02\x00\x00\x00".pack('N', 0); }
function snapshot(Connection $db, string $dir): string {
    $s = '';
    foreach ($db->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $t) { $s .= $t.json_encode(array_map('bin2hex', array_map('serialize', $db->fetchAllAssociative("SELECT * FROM \"$t\"")))); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($it as $f) { $files[] = $f->getPathname().':'.md5_file($f->getPathname()); }
    sort($files);

    return md5($s.implode('|', $files));
}

$u = static fn (int $n): string => str_pad(chr($n), 16, "\xAB");     // a 16-byte binary UUID
$hex = static fn (int $n): string => bin2hex(str_pad(chr($n), 16, "\xAB"));

// --- the site's project folder: two real image files, one deferred variant record ---
$root = sys_get_temp_dir().'/atlas-images-'.getmypid();
@mkdir("$root/files/team", 0777, true);
@mkdir("$root/assets/images/deferred/c", 0777, true);
file_put_contents("$root/files/hero.png", png(1200, 630));
file_put_contents("$root/files/logo.svg", '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><rect/></svg>');
file_put_contents("$root/assets/images/deferred/c/banner-c0ffee123.webp.json", json_encode(['path' => '../../files/team/banner.jpg', 'coordinates' => [], 'options' => []]));

// --- the database: Contao 5.7 shape plus Doublespark's own columns ---
$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$pdo = $db->getNativeConnection();
$pdo->sqliteCreateFunction('UNHEX', static fn ($h) => null === $h ? null : hex2bin((string) $h), 1);
$pdo->sqliteCreateFunction('DATABASE', static fn () => 'site', 0);
$db->executeStatement("ATTACH DATABASE ':memory:' AS information_schema");
$db->executeStatement('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA text, TABLE_NAME text, COLUMN_NAME text, COLUMN_TYPE text, ORDINAL_POSITION int)');
$db->insert('information_schema.COLUMNS', ['TABLE_SCHEMA' => 'another_site', 'TABLE_NAME' => 'tl_content', 'COLUMN_NAME' => 'otherSRC', 'COLUMN_TYPE' => 'binary(16)', 'ORDINAL_POSITION' => 99]);

table($db, 'tl_files', ['id' => 'int(10) unsigned', 'pid' => 'binary(16)', 'uuid' => 'binary(16)', 'type' => 'varchar(16)', 'path' => 'varchar(1022)', 'extension' => 'varchar(16)', 'hash' => 'varchar(32)', 'name' => 'varchar(255)', 'meta' => 'blob']);
table($db, 'tl_page', ['id' => 'int(10) unsigned', 'pid' => 'int(10) unsigned', 'alias' => 'varchar(255)', 'published' => 'tinyint(1)', 'hofff_st_og_image' => 'binary(16)']);
table($db, 'tl_article', ['id' => 'int(10) unsigned', 'pid' => 'int(10) unsigned', 'published' => 'tinyint(1)']);
table($db, 'tl_news', ['id' => 'int(10) unsigned', 'pid' => 'int(10) unsigned', 'alias' => 'varchar(255)', 'published' => 'tinyint(1)', 'singleSRC' => 'binary(16)', 'overwriteMeta' => 'tinyint(1)', 'alt' => 'varchar(255)', 'imageTitle' => 'varchar(255)', 'caption' => 'text']);
table($db, 'tl_content', ['id' => 'int(10) unsigned', 'pid' => 'int(10) unsigned', 'ptable' => 'varchar(64)', 'type' => 'varchar(64)', 'invisible' => 'tinyint(1)', 'singleSRC' => 'binary(16)',
    'multiSRC' => 'blob', 'orderSRC' => 'blob', 'dsImg_defaultSrc' => 'binary(16)', 'overwriteMeta' => 'tinyint(1)', 'alt' => 'varchar(255)', 'imageTitle' => 'varchar(255)', 'caption' => 'text', 'text' => 'mediumtext']);
table($db, 'tl_ds_portfolio', ['id' => 'int(10) unsigned', 'main_image' => 'binary(16)', 'published' => 'char(1)']);
table($db, 'tl_user', ['id' => 'int(10) unsigned', 'homeDir' => 'binary(16)', 'filemounts' => 'blob']);

$file = static function (int $id, int $n, string $path, string $ext, array $meta = []) use ($db, $u): void {
    $db->insert('tl_files', ['id' => $id, 'uuid' => $u($n), 'type' => 'file', 'path' => $path, 'extension' => $ext, 'hash' => md5($path), 'name' => basename($path), 'meta' => $meta ? serialize($meta) : null]);
};
$file(1, 1, 'files/hero.png', 'png', ['en' => ['alt' => 'Hero alt', 'title' => '', 'caption' => 'Cap', 'link' => '', 'license' => '']]);
$file(2, 2, 'files/logo.svg', 'svg');
$file(3, 3, 'files/team/banner.jpg', 'jpg');
$file(4, 4, 'files/brochure.pdf', 'pdf');                    // not an image
$file(5, 5, 'files/a/photo.jpg', 'jpg');
$file(6, 6, 'files/b/photo.JPG', 'JPG');                     // same name in another folder: a variant of "photo" is ambiguous
$db->insert('tl_files', ['id' => 7, 'uuid' => $u(7), 'type' => 'folder', 'path' => 'files/team', 'extension' => '', 'name' => 'team']);

$db->insert('tl_page', ['id' => 10, 'pid' => 1, 'alias' => 'about', 'published' => 1, 'hofff_st_og_image' => $u(1)]);
$db->insert('tl_article', ['id' => 20, 'pid' => 10, 'published' => 1]);
$db->insert('tl_news', ['id' => 30, 'pid' => 2, 'alias' => 'a-post', 'published' => 1, 'singleSRC' => $u(1), 'overwriteMeta' => 1, 'alt' => 'News alt', 'imageTitle' => 'News title']);
$db->insert('tl_content', ['id' => 40, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'image', 'invisible' => 0, 'singleSRC' => $u(1), 'overwriteMeta' => 0, 'alt' => 'ignored']);
$db->insert('tl_content', ['id' => 41, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'invisible' => 1, 'multiSRC' => serialize([$u(1), $u(2)]), 'orderSRC' => serialize([$u(2), $u(1)])]);
$db->insert('tl_content', ['id' => 42, 'pid' => 30, 'ptable' => 'tl_news', 'type' => 'element_group']);
$db->insert('tl_content', ['id' => 43, 'pid' => 42, 'ptable' => 'tl_content', 'type' => 'ds_image', 'dsImg_defaultSrc' => $u(2)]);
$db->insert('tl_content', ['id' => 44, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'text', 'text' => 'no image']);
$db->insert('tl_ds_portfolio', ['id' => 50, 'main_image' => $u(3), 'published' => '1']);
$db->insert('tl_user', ['id' => 1, 'homeDir' => $u(1), 'filemounts' => serialize([$u(1)])]);

$inventory = new ImageInventory($db, new ContentUrls(new Contao\CoreBundle\Framework\ContaoFramework(), null), $root);
$c = new ImagesController($inventory, hash('sha256', 'tok'));
function get(ImagesController $c, string $action, array $query, string $token = 'tok'): array {
    $r = Request::create('/x', 'GET', $query, [], [], ['HTTP_AUTHORIZATION' => "Bearer $token"]);
    $resp = $c->$action($r);

    return [$resp->getStatusCode(), json_decode((string) $resp->getContent(), true)];
}

$before = snapshot($db, $root);

[$st] = get($c, 'list', [], 'wrong');
check('list without the token: 401', 401 === $st);
[$st] = get($c, 'list', ['per_page' => '501']);
check('per_page over 500: 400', 400 === $st);

[$st, $a] = get($c, 'list', ['per_page' => '100']);
check('list: 200, five image files (pdf and folder left out, JPG upper case kept)', 200 === $st && 5 === $a['total'] && 5 === count($a['images']) && 1 === $a['pages'], $a);
$byPath = array_column($a['images'], null, 'path');
$hero = $byPath['files/hero.png'] ?? [];
check('uuid as lower-case hex, hash and extension', $hex(1) === ($hero['uuid'] ?? null) && md5('files/hero.png') === $hero['hash'] && 'png' === $hero['extension'], $hero);
check('meta per language', 'Hero alt' === ($hero['meta']['en']['alt'] ?? null) && 'Cap' === $hero['meta']['en']['caption'], $hero['meta'] ?? null);
check('png width and height from the header', 1200 === $hero['width'] && 630 === $hero['height'], $hero);
check('svg width and height from the viewBox', 240 === $byPath['files/logo.svg']['width'] && 80 === $byPath['files/logo.svg']['height'], $byPath['files/logo.svg']);
check('a file not on disk has null dimensions', null === $byPath['files/team/banner.jpg']['width'], $byPath['files/team/banner.jpg']);

$where = static fn (array $img): array => array_map(static fn ($p) => $p['table'].'.'.$p['column'].'#'.$p['row_id'], $img['placements']);
$heroAt = $where($hero);
sort($heroAt);
check('hero placements: page og image, news image, content image, gallery; never tl_user or orderSRC',
    ['tl_content.multiSRC#41', 'tl_content.singleSRC#40', 'tl_news.singleSRC#30', 'tl_page.hofff_st_og_image#10'] === $heroAt, $heroAt);
$p = array_column(array_map(static fn ($p) => ['k' => $p['table'].'#'.$p['row_id'], 'p' => $p], $hero['placements']), 'p', 'k');
check('content element: type, published, no override, parent article and page', 'image' === $p['tl_content#40']['element_type'] && true === $p['tl_content#40']['published']
    && false === $p['tl_content#40']['overwrite_meta'] && null === $p['tl_content#40']['alt'] && 20 === $p['tl_content#40']['parent']['article_id']
    && 10 === $p['tl_content#40']['parent']['page_id'] && 'about' === $p['tl_content#40']['parent']['page_alias'], $p['tl_content#40']);
check('hidden gallery reads published false, column kind blob', false === $p['tl_content#41']['published'] && 'blob' === $p['tl_content#41']['column_kind'] && 'binary' === $p['tl_content#40']['column_kind'], $p['tl_content#41']);
check('news image with overwriteMeta: its own alt and title', true === $p['tl_news#30']['overwrite_meta'] && 'News alt' === $p['tl_news#30']['alt']
    && 'News title' === $p['tl_news#30']['title'] && 30 === $p['tl_news#30']['parent']['news_id'] && 'a-post' === $p['tl_news#30']['parent']['news_alias'], $p['tl_news#30']);
check('page og image: page parent, no overwriteMeta column means null', 10 === $p['tl_page#10']['parent']['page_id'] && null === $p['tl_page#10']['overwrite_meta'], $p['tl_page#10']);
$logo = $byPath['files/logo.svg'];
$nested = array_values(array_filter($logo['placements'], static fn ($x) => 43 === $x['row_id']))[0] ?? [];
check('element inside an element group is followed up to its news entry', 'tl_content' === ($nested['table'] ?? '') && 'dsImg_defaultSrc' === $nested['column'] && 30 === $nested['parent']['news_id'], $nested);
check('custom portfolio column found by the schema scan', ['tl_ds_portfolio.main_image#50'] === $where($byPath['files/team/banner.jpg']) && null === $byPath['files/team/banner.jpg']['placements'][0]['parent']['page_id'], $byPath['files/team/banner.jpg']);
check('v0.7: the list advertises the meta write so Atlas can refuse a site without it', '0.8' === ($a['bundle_version'] ?? null) && in_array('images_meta_write', $a['capabilities'] ?? [], true) && in_array('images_invalidate', $a['capabilities'] ?? [], true), [$a['bundle_version'] ?? null, $a['capabilities'] ?? null]);
check('uuid_columns lists the custom columns, not tl_files, tl_user, orderSRC or another schema', in_array('tl_ds_portfolio.main_image', $a['uuid_columns'], true)
    && in_array('tl_content.dsImg_defaultSrc', $a['uuid_columns'], true) && !array_filter($a['uuid_columns'], static fn ($x) => str_starts_with($x, 'tl_files.') || str_starts_with($x, 'tl_user.') || str_ends_with($x, 'orderSRC') || str_ends_with($x, 'otherSRC')), $a['uuid_columns']);

[$st, $a] = get($c, 'list', ['page' => '2', 'per_page' => '2']);
check('pagination: page 2 of 3, ordered by id', 200 === $st && 3 === $a['pages'] && ['files/team/banner.jpg', 'files/a/photo.jpg'] === array_column($a['images'], 'path'), $a);

// --- the resolver ---
[$st, $a] = get($c, 'resolve', ['url' => [
    'https://www.example.com/files/hero.png',
    'https://www.example.com/assets/images/c/banner-c0ffee123.webp',
    '/assets/images/7/hero-7e1f00a2b.webp?v=2',
    '/assets/images/1/photo-1a2b3c4d5.jpg',
    '/assets/images/9/missing-9a8b7c6d5.jpg',
    '/assets/images/x/hero-7e1f00a2b.webp',
    '/assets/images/7/hero%20image-7e1f00a2b.webp',
]]);
$r = $a['results'] ?? [];
check('resolve: 200, one answer per address in order', 200 === $st && 7 === count($r), $a);
check('a files/ address resolves by path', 'file_path' === $r[0]['method'] && $hex(1) === $r[0]['uuid'], $r[0]);
check('a deferred variant resolves through its stored record', 'deferred_store' === $r[1]['method'] && 'files/team/banner.jpg' === $r[1]['path'] && $hex(3) === $r[1]['uuid'], $r[1]);
check('a generated variant (other format, query string) resolves by its file name', 'filename' === $r[2]['method'] && $hex(1) === $r[2]['uuid'], $r[2]);
check('two files with the name: ambiguous with both candidates', 'ambiguous' === $r[3]['method'] && null === $r[3]['uuid'] && 2 === count($r[3]['candidates']), $r[3]);
check('no such file: unresolved', 'unresolved' === $r[4]['method'] && null === $r[4]['uuid'], $r[4]);
check('folder not the first hash character still resolves (live Contao folders are unrelated to the hash)', 'filename' === $r[5]['method'] && $hex(1) === $r[5]['uuid'], $r[5]);
check('percent-encoded name decoded before the lookup (no such file here)', 'unresolved' === $r[6]['method'], $r[6]);
[$st] = get($c, 'resolve', ['url' => '/files/hero.png']);
check('a single url= also accepted', 200 === $st);
[$st] = get($c, 'resolve', ['url' => array_fill(0, 21, '/files/hero.png')]);
check('more than 20 addresses: 400', 400 === $st);
[$st] = get($c, 'resolve', []);
check('no address: 400', 400 === $st);
[$st] = get($c, 'resolve', ['url' => '/files/hero.png'], 'wrong');
check('resolve without the token: 401', 401 === $st);

check('nothing in the database or the project folder changed', $before === snapshot($db, $root));
check('uuidsIn: text in a blob holds none', [] === ImageInventory::uuidsIn('a plain text value that is longer than sixteen bytes'));

// --- v0.5.1: shapes that used to end in a 500 (array_push on a missing bucket) or could, added after the read-only check above ---
// An image used only in a gallery (no single-image column holds it): the original array_push TypeError.
$file(8, 8, 'files/gallery-only.jpg', 'jpg', []);
$db->insert('tl_content', ['id' => 60, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => serialize([$u(8)])]);
// Blob values that hold no list: NULL, empty, text, a truncated serialisation, a serialised non-array, a serialised empty array.
$db->insert('tl_content', ['id' => 61, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => null, 'orderSRC' => null]);
$db->insert('tl_content', ['id' => 62, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => '']);
$db->insert('tl_content', ['id' => 63, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => 'a:2:{i:0;s:16:"trunc']);
$db->insert('tl_content', ['id' => 64, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => 's:3:"abc";']);
$db->insert('tl_content', ['id' => 65, 'pid' => 20, 'ptable' => 'tl_article', 'type' => 'gallery', 'multiSRC' => 'a:0:{}']);
// Meta that is not a language => fields array: empty, NULL, false, a bare string, and a language whose fields are not an array.
$file(9, 9, 'files/meta-empty.jpg', 'jpg');
$db->update('tl_files', ['meta' => ''], ['id' => 9]);
$file(10, 10, 'files/meta-false.jpg', 'jpg');
$db->update('tl_files', ['meta' => serialize(false)], ['id' => 10]);
$file(11, 11, 'files/meta-string.jpg', 'jpg');
$db->update('tl_files', ['meta' => serialize('abc')], ['id' => 11]);
$file(12, 12, 'files/meta-odd.jpg', 'jpg');
$db->update('tl_files', ['meta' => serialize(['en' => 'x', 'de' => ['alt' => null, 'title' => ['n'], 'caption' => 'ok']])], ['id' => 12]);
$file(13, 13, 'files/meta-garbage.jpg', 'jpg');
$db->update('tl_files', ['meta' => 'a:1:{broken'], ['id' => 13]);

// the blob index is cached per instance, so read the new rows through a fresh one
$c = new ImagesController(new ImageInventory($db, new ContentUrls(new Contao\CoreBundle\Framework\ContaoFramework(), null), $root), hash('sha256', 'tok'));
[$st, $a] = get($c, 'list', ['per_page' => '100']);
check('list after the odd rows: still 200, every image listed', 200 === $st && 11 === $a['total'] && 11 === count($a['images']), $a['error'] ?? $a['total'] ?? $a);
$byPath = array_column($a['images'] ?? [], null, 'path');
check('gallery-only image: one blob placement, no error', ['tl_content.multiSRC#60'] === $where($byPath['files/gallery-only.jpg'] ?? ['placements' => []]), $byPath['files/gallery-only.jpg'] ?? null);
check('gallery-only image still has its column kind', 'blob' === ($byPath['files/gallery-only.jpg']['placements'][0]['column_kind'] ?? null));
check('image in a gallery and a single column keeps both placements', 4 === count($byPath['files/hero.png']['placements'] ?? []), $byPath['files/hero.png']['placements'] ?? null);
check('meta of empty, false, a bare string and unreadable text is an empty list', [] === $byPath['files/meta-empty.jpg']['meta'] && [] === $byPath['files/meta-false.jpg']['meta']
    && [] === $byPath['files/meta-string.jpg']['meta'] && [] === $byPath['files/meta-garbage.jpg']['meta'], array_column($a['images'], 'meta', 'path'));
check('meta NULL (the column default) is an empty list', [] === $byPath['files/logo.svg']['meta']);
check('meta with a non-array language and non-scalar values keeps only the scalars', ['de' => ['caption' => 'ok']] === $byPath['files/meta-odd.jpg']['meta'], $byPath['files/meta-odd.jpg']['meta']);
check('uuidsIn: empty, truncated, non-array and empty-array values hold none', [] === ImageInventory::uuidsIn('') && [] === ImageInventory::uuidsIn('a:2:{i:0;s:16:"trunc')
    && [] === ImageInventory::uuidsIn('s:3:"abc";') && [] === ImageInventory::uuidsIn('a:0:{}'));
[$st] = get($c, 'list', ['page' => '99']);
check('a page past the end: 200 with no images', 200 === $st);

// --- live Doublespark variants: the folder is unrelated to the hash, the format can differ from the source, absolute and relative both used ---
$file(20, 20, 'files/images/bg_panels/map.webp', 'webp');
$file(21, 21, 'files/images/portfolio/cntech/cntech.webp', 'webp');
$file(22, 22, 'files/images/portfolio/cntech/cntech.png', 'png');
$file(23, 23, 'files/images/portfolio/todd-reserarch/todd-research.png', 'png');
$file(24, 24, 'files/images/seo/software/hotjar/hotjar-conversion-rate-funnel.webp', 'webp');
$file(25, 25, 'files/images/system/logo/doublespark-google-partner-h-100.webp', 'webp');
$c = new ImagesController(new ImageInventory($db, new ContentUrls(new Contao\CoreBundle\Framework\ContaoFramework(), null), $root), hash('sha256', 'tok'));
[$st, $a] = get($c, 'resolve', ['url' => [
    'https://www.doublespark.co.uk/assets/images/3/map-19q9c3b6bzgg216.webp',
    '/assets/images/h/cntech-8pg9ss5qb645k1t.webp',
    'https://www.doublespark.co.uk/assets/images/t/todd-research-s9e8jz5kd9qxqst.png',
    'https://www.doublespark.co.uk/assets/images/q/hotjar-conversion-rate-funnel-7yfhdhx5kcf9j0q.webp',
    'https://www.doublespark.co.uk/assets/images/t/doublespark-google-partner-h-100-0ft2kancqk7tm8y.webp',
]]);
$r = $a['results'] ?? [];
check('live samples: map, todd-research, hotjar and the hyphenated logo name resolve by file name',
    'filename' === $r[0]['method'] && 'files/images/bg_panels/map.webp' === $r[0]['path']
    && 'filename' === $r[2]['method'] && 'files/images/portfolio/todd-reserarch/todd-research.png' === $r[2]['path']
    && 'filename' === $r[3]['method'] && 'files/images/seo/software/hotjar/hotjar-conversion-rate-funnel.webp' === $r[3]['path']
    && 'filename' === $r[4]['method'] && 'files/images/system/logo/doublespark-google-partner-h-100.webp' === $r[4]['path'], $r);
check('live samples: cntech has a .webp and a .png source, so it is ambiguous with both candidates listed',
    'ambiguous' === $r[1]['method'] && 2 === count($r[1]['candidates']), $r[1]);

echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
}
