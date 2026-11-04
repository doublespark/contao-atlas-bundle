<?php
// Drives the bundle's real NewsController and NewsWriter against SQLite, in the shape of a Contao 5.7 site with the social tags
// extension (Doublespark) and a Contao 4.13 site (no linkText, no social tags). Not a site test: proves the PHP logic only.

declare(strict_types=1);

namespace Contao\CoreBundle\Framework { class ContaoFramework { public function initialize(): void {} } }
namespace Contao { class NewsModel { public static function findByPk($id) { return null; } } }

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

use Doctrine\DBAL\DriverManager;
use Doublespark\ContaoAtlasBundle\Controller\NewsController;
use Doublespark\ContaoAtlasBundle\Service\NewsWriter;
use Symfony\Component\HttpFoundation\Request;

$fails = 0;
function check(string $what, bool $ok, $detail = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ').$what.($ok ? '' : '  '.json_encode($detail))."\n"; if (!$ok) { ++$fails; } }

// v0.6: stands in for Contao's Dbafs (which needs the whole framework): records what was registered and answers a fixed 16-byte uuid.
// v0.9: each path gets its own uuid (the first one registered is 16 x 01, as the v0.6 checks expect), and uuidOf answers from the same map, as tl_files would.
class FakeImageStore extends Doublespark\ContaoAtlasBundle\Service\NewsImageStore {
    public array $registered = [];
    public array $uuids = [];
    protected function register(string $path): string { $this->registered[] = $path; return $this->uuids[$path] ??= str_repeat(chr(\count($this->uuids) + 1), 16); }
    protected function uuidOf(string $path): ?string { return $this->uuids[$path] ?? null; }
}
$projectDir = sys_get_temp_dir().'/atlas-bundle-test-'.getmypid();
@mkdir($projectDir, 0775, true);
$store = new FakeImageStore($projectDir, 'files', new Contao\CoreBundle\Framework\ContaoFramework());

function site(bool $v5, bool $migrated = true, bool $withImageStore = true): array {
    global $store;
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $extra = $v5 ? ", linkText varchar(255) NOT NULL default '', hofff_st char(1) NOT NULL default '', hofff_st_og_type varchar(255) NOT NULL default '', hofff_st_og_title varchar(255) NOT NULL default '', hofff_st_og_description text NULL" : '';
    $published = $v5 ? "integer NOT NULL default 0" : "char(1) NOT NULL default ''";
    $db->executeStatement("CREATE TABLE tl_news (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL default 0, tstamp int NOT NULL default 0, date int NOT NULL default 0, time int NOT NULL default 0,
        alias varchar(255) NOT NULL default '', source varchar(32) NOT NULL default '', headline varchar(255) NOT NULL default '', teaser text NULL, author int NOT NULL default 0,
        pageTitle varchar(255) NOT NULL default '', description text NULL, published $published, start varchar(10) NOT NULL default '', stop varchar(10) NOT NULL default '',
        featured char(1) NOT NULL default '', subheadline varchar(255) NOT NULL default '',
        addImage ".($v5 ? "boolean NOT NULL default 0" : "char(1) NOT NULL default ''").", overwriteMeta ".($v5 ? "boolean NOT NULL default 0" : "char(1) NOT NULL default ''").",
        singleSRC blob NULL, alt ".($v5 ? "text NULL" : "varchar(255) NOT NULL default ''")." $extra)");
    $db->executeStatement("CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY, title varchar(255))");
    $db->executeStatement("INSERT INTO tl_news_archive VALUES (2, 'News')");
    $db->executeStatement("CREATE TABLE tl_user (id INTEGER PRIMARY KEY, name varchar(255))");
    $db->executeStatement("INSERT INTO tl_user VALUES (2, 'Gary Cottam')");
    $db->executeStatement("CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL default 0, ptable varchar(64) NOT NULL default '', sorting int NOT NULL default 0,
        tstamp int NOT NULL default 0, type varchar(64) NOT NULL default '', text text NULL, headline varchar(255) NOT NULL default '', cssID varchar(255) NOT NULL default '')");
    $db->executeStatement("CREATE TABLE tl_atlas_news_ref (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL default 0, atlas_ref varchar(190) NOT NULL default '', news_id int NOT NULL default 0,
        content_id int NOT NULL default 0".($migrated ? ", content_ids varchar(1000) NOT NULL default ''" : '').")");
    $writer = new NewsWriter($db, new Contao\CoreBundle\Framework\ContaoFramework(), null, 'Europe/London', 0, '', '', $withImageStore ? $store : null);

    return [$db, new NewsController($writer, hash('sha256', 'tok'))];
}
function call(NewsController $c, string $action, $body, ?int $id = null, string $token = 'tok'): array {
    $r = Request::create('/x', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer $token", 'CONTENT_TYPE' => 'application/json'], is_string($body) ? $body : json_encode($body));
    $resp = null === $id ? $c->$action($r) : $c->$action($r, $id);

    return [$resp->getStatusCode(), json_decode((string) $resp->getContent(), true)];
}

$elements = [
    ['key' => 'tldr', 'section_id' => 'tldr', 'headline' => '', 'html' => '<p><strong>TL;DR:</strong> one</p>'],
    ['key' => 'at_a_glance', 'section_id' => 'at-a-glance', 'headline' => 'At A Glance', 'html' => '<ul><li>two</li></ul>'],
    ['key' => 'body', 'section_id' => '', 'headline' => '', 'html' => '<h2>Three</h2><p>body</p>'],
];
$post = ['archive_id' => 2, 'headline' => 'Test post', 'teaser' => 'T', 'atlas_ref' => 'atlas-piece-1', 'author_id' => 2, 'published' => false,
    'elements' => $elements, 'featured' => true, 'link_text' => 'Read it', 'og_title' => 'OG T', 'og_description' => 'OG D'];

// --- Contao 5.7 shape (Doublespark) ---
[$db, $c] = site(true);
[$st, $a] = call($c, 'create', $post);
check('5.7 create with elements: 201', 201 === $st, $a);
check('5.7 elements_count 3', 3 === ($a['elements_count'] ?? null), $a);
check('5.7 all four optional fields applied', ['featured', 'link_text', 'og_title', 'og_description'] === ($a['fields_applied'] ?? null) && [] === ($a['fields_unsupported'] ?? null), $a);
$id = (int) $a['id'];
$rows = $db->fetchAllAssociative("SELECT * FROM tl_content WHERE pid = ? ORDER BY sorting", [$id]);
check('5.7 three text elements in order', 3 === count($rows) && '<ul><li>two</li></ul>' === $rows[1]['text'] && 128 === (int) $rows[0]['sorting'] && 384 === (int) $rows[2]['sorting'], $rows);
check('5.7 css ids stored as Contao does', serialize(['tldr', '']) === $rows[0]['cssID'] && serialize(['at-a-glance', '']) === $rows[1]['cssID'] && '' === $rows[2]['cssID'], $rows);
check('5.7 headline stored as an h2', serialize(['value' => 'At A Glance', 'unit' => 'h2']) === $rows[1]['headline'] && '' === $rows[0]['headline'], $rows);
$news = $db->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', [$id]);
check('5.7 featured, link text and OG written, social tags switched on with type article', '1' === (string) $news['featured'] && 'Read it' === $news['linkText'] && 'OG T' === $news['hofff_st_og_title']
    && 'OG D' === $news['hofff_st_og_description'] && '1' === (string) $news['hofff_st'] && 'article' === $news['hofff_st_og_type'], $news);
check('5.7 created unpublished', 0 === (int) $news['published'], $news);

// a person adds an element of their own in the back end, then Atlas re-sends with two blocks
$db->insert('tl_content', ['pid' => $id, 'ptable' => 'tl_news', 'sorting' => 1000, 'type' => 'image', 'text' => 'person']);
[$st, $a] = call($c, 'update', ['headline' => 'Test post', 'elements' => array_slice($elements, 0, 2)], $id);
check('5.7 re-send with two blocks: 200 and elements_count 2', 200 === $st && 2 === ($a['elements_count'] ?? null), $a);
$texts = $db->fetchFirstColumn("SELECT text FROM tl_content WHERE pid = ? ORDER BY sorting", [$id]);
check('5.7 re-send replaced only Atlas elements, the person\'s stays', ['<p><strong>TL;DR:</strong> one</p>', '<ul><li>two</li></ul>', 'person'] === $texts, $texts);
$db->update('tl_news', ['hofff_st_og_type' => 'website'], ['id' => $id]);
call($c, 'update', ['og_title' => 'OG 2'], $id);
check('5.7 an og type someone set is kept', 'website' === $db->fetchOne('SELECT hofff_st_og_type FROM tl_news WHERE id = ?', [$id]));
[$st, $a] = call($c, 'update', ['body_html' => '<p>one body</p>'], $id);
$texts = $db->fetchFirstColumn("SELECT text FROM tl_content WHERE pid = ? ORDER BY sorting", [$id]);
check('5.7 one body after a list replaces the list', 200 === $st && ['<p>one body</p>', 'person'] === $texts, $texts);
[$st, $a] = call($c, 'update', ['body_html' => '<p>edited</p>'], $id);
check('5.7 one body then one body: updated in place', 200 === $st && ['<p>edited</p>', 'person'] === $db->fetchFirstColumn("SELECT text FROM tl_content WHERE pid = ? ORDER BY sorting", [$id]));

// refusals
[$st] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r2', 'body_html' => '<p>a</p>', 'elements' => $elements]);
check('both body_html and elements: 400', 400 === $st);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r3', 'elements' => [['html' => 'a', 'section_id' => '1bad']]]);
check('a section id that is not a CSS id: 400', 400 === $st, $a);
[$st] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r4', 'elements' => []]);
check('an empty element list: 400', 400 === $st);
check('nothing created by the refusals', 1 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news'));

// delete
[$st, $a] = call($c, 'delete', ['atlas_ref' => 'wrong'], $id);
check('delete with the wrong atlas_ref: 409, nothing deleted', 409 === $st && 1 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news'), $a);
[$st] = call($c, 'delete', ['atlas_ref' => 'atlas-piece-1'], $id, 'nottok');
check('delete without the token: 401', 401 === $st);
[$st, $a] = call($c, 'delete', ['atlas_ref' => 'atlas-piece-1'], $id);
check('delete: 200 deleted', 200 === $st && true === ($a['deleted'] ?? null), $a);
check('delete removed the entry, all its elements and its ref', 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news') && 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_content')
    && 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_atlas_news_ref'));
[$st] = call($c, 'delete', ['atlas_ref' => 'atlas-piece-1'], $id);
check('delete again: 404', 404 === $st);
$db->insert('tl_news', ['pid' => 2, 'headline' => 'A person\'s post']);
[$st] = call($c, 'delete', ['atlas_ref' => ''], (int) $db->lastInsertId());
check('an entry Atlas did not make cannot be deleted: 404', 404 === $st);

// v0.2 path unchanged
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'Old', 'atlas_ref' => 'v2', 'body_html' => '<p>v2</p>']);
check('v0.2 body_html create still 201, no v0.3 keys in the answer', 201 === $st && !isset($a['elements_count']) && !isset($a['fields_applied']), $a);

// --- Contao 4.13 shape (Artificial Grass Maintenance, Urban Square) ---
[$db, $c] = site(false);
[$st, $a] = call($c, 'create', $post);
check('4.13 create with elements: 201, elements_count 3', 201 === $st && 3 === ($a['elements_count'] ?? null), $a);
check('4.13 featured applied; link text and OG reported unsupported', ['featured'] === ($a['fields_applied'] ?? null) && ['link_text', 'og_title', 'og_description'] === ($a['fields_unsupported'] ?? null), $a);
check('4.13 featured stored as char 1', '1' === $db->fetchOne('SELECT featured FROM tl_news WHERE id = ?', [(int) $a['id']]));

// --- before contao:migrate has added content_ids ---
[$db, $c] = site(true, false);
[$st, $a] = call($c, 'create', $post);
check('without content_ids: 500 naming contao:migrate', 500 === $st && str_contains((string) ($a['message'] ?? ''), 'contao:migrate'), $a);
check('without content_ids: nothing written', 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news') && 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_content'));

// --- v0.6: subheadline and image ---
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='; // a 1x1 PNG
$image = ['filename' => 'Phone on a desk.PNG', 'data_base64' => $png, 'alt' => 'Smartphone on a desk'];
foreach ([true, false] as $v5) {
    $tag = $v5 ? '5.7' : '4.13';
    [$db, $c] = site($v5);
    [$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'Img', 'atlas_ref' => 'atlas-piece-7', 'body_html' => '<p>b</p>', 'subheadline' => 'Card heading', 'image' => $image]);
    check("$tag create with subheadline and image: 201, both applied, image_path answered", 201 === $st && in_array('subheadline', $a['fields_applied'] ?? [], true)
        && in_array('image', $a['fields_applied'] ?? [], true) && 'files/atlas-news/atlas-piece-7-phone-on-a-desk.png' === ($a['image_path'] ?? null), $a);
    $news = $db->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', [(int) $a['id']]);
    check("$tag image columns: addImage and overwriteMeta on, uuid in singleSRC, alt, subheadline", '1' === (string) $news['addImage'] && '1' === (string) $news['overwriteMeta']
        && str_repeat("\x01", 16) === $news['singleSRC'] && 'Smartphone on a desk' === $news['alt'] && 'Card heading' === $news['subheadline'], array_map('bin2hex', array_filter($news, 'is_string')));
    check("$tag file written under files/atlas-news and registered", is_file("$projectDir/files/atlas-news/atlas-piece-7-phone-on-a-desk.png")
        && 'files/atlas-news/atlas-piece-7-phone-on-a-desk.png' === end($store->registered));
    [$st, $a] = call($c, 'update', ['subheadline' => 'New heading'], (int) $a['id']);
    $news = $db->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', [(int) $a['id']]);
    check("$tag update without image leaves the image alone", 200 === $st && !isset($a['image_path']) && str_repeat("\x01", 16) === $news['singleSRC'] && 'New heading' === $news['subheadline'], $a);
}
[$db, $c] = site(true);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r-gif', 'body_html' => '<p>b</p>', 'image' => ['filename' => 'a.gif', 'data_base64' => base64_encode('GIF89a not really'), 'alt' => 'x']]);
check('an image that is not JPEG, PNG or WebP: 422, nothing created', 422 === $st && 0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news'), $a);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r-b64', 'body_html' => '<p>b</p>', 'image' => ['filename' => 'a.png', 'data_base64' => '***', 'alt' => 'x']]);
check('image data that is not base64: 422', 422 === $st, $a);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r-alt', 'body_html' => '<p>b</p>', 'image' => ['filename' => 'a.png', 'data_base64' => $png, 'alt' => ' ']]);
check('an image without alt text: 400', 400 === $st, $a);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r-shape', 'body_html' => '<p>b</p>', 'image' => 'not an object']);
check('image that is not an object: 400', 400 === $st, $a);
[$db, $c] = site(true, true, false);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'X', 'atlas_ref' => 'r-nostore', 'body_html' => '<p>b</p>', 'image' => $image]);
check('no image store: created, image reported unsupported, nothing set', 201 === $st && ['image'] === ($a['fields_unsupported'] ?? null)
    && '' === (string) $db->fetchOne('SELECT singleSRC FROM tl_news WHERE id = ?', [(int) $a['id']]), $a);

// --- v0.9: image.folder ---
$folder = 'files/blog-news/2026/news/banner';
$post = ['filename' => 'brightonseo-2026-ppc-strategies.png', 'data_base64' => $png, 'alt' => 'A stage at BrightonSEO', 'folder' => $folder];
[$db, $c] = site(true);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'F', 'atlas_ref' => 'atlas-piece-41', 'body_html' => '<p>b</p>', 'image' => $post]);
$own = $db->fetchOne('SELECT singleSRC FROM tl_news WHERE id = ?', [(int) ($a['id'] ?? 0)]);
check('folder accepted: the file is the slug alone in that folder, created and registered', 201 === $st && "$folder/brightonseo-2026-ppc-strategies.png" === ($a['image_path'] ?? null)
    && is_file("$projectDir/$folder/brightonseo-2026-ppc-strategies.png") && "$folder/brightonseo-2026-ppc-strategies.png" === end($store->registered)
    && $store->uuids["$folder/brightonseo-2026-ppc-strategies.png"] === $own, $a);
$entry = (int) $a['id'];
$before = count($store->registered);
[$st, $a] = call($c, 'update', ['image' => $post], $entry);
check('re-send of the same entry overwrites its own file, no -2 copy', 200 === $st && "$folder/brightonseo-2026-ppc-strategies.png" === ($a['image_path'] ?? null)
    && !is_file("$projectDir/$folder/brightonseo-2026-ppc-strategies-2.png") && $own === $db->fetchOne('SELECT singleSRC FROM tl_news WHERE id = ?', [$entry])
    && count($store->registered) === $before + 1, $a);
// A file of the same name a person put there (another post's banner): known to tl_files under its own uuid, and one on disk that tl_files does not know.
@mkdir("$projectDir/files/blog-news/2025/news/banner", 0775, true);
file_put_contents("$projectDir/files/blog-news/2025/news/banner/their-post.png", 'theirs');
$store->uuids['files/blog-news/2025/news/banner/their-post.png'] = str_repeat("\x7f", 16);
file_put_contents("$projectDir/files/blog-news/2025/news/banner/their-post-2.png", 'unregistered');
$foreign = ['filename' => 'their-post.png', 'data_base64' => $png, 'alt' => 'x', 'folder' => 'files/blog-news/2025/news/banner/'];
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'G', 'atlas_ref' => 'atlas-piece-42', 'body_html' => '<p>b</p>', 'image' => $foreign]);
check('collision with a foreign file: neither the registered nor the unregistered file is touched, -3 is written', 201 === $st
    && 'files/blog-news/2025/news/banner/their-post-3.png' === ($a['image_path'] ?? null) && 'theirs' === file_get_contents("$projectDir/files/blog-news/2025/news/banner/their-post.png")
    && 'unregistered' === file_get_contents("$projectDir/files/blog-news/2025/news/banner/their-post-2.png"), $a);
[$st, $a] = call($c, 'update', ['image' => $foreign], $entry);
check('another entry sending the same name is a foreign file too: it gets the next free name', 200 === $st && 'files/blog-news/2025/news/banner/their-post-4.png' === ($a['image_path'] ?? null), $a);
$count = (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news');
foreach (['files/../config', '../files/x', '/var/www/files/x', 'files\\blog', 'files', 'templates/x', 'files/blog news', 'files/./x', 'C:/files/x', 'files//x', 42, ''] as $bad) {
    [$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'H', 'atlas_ref' => 'r-bad-'.md5(json_encode($bad)), 'body_html' => '<p>b</p>', 'image' => ['folder' => $bad] + $post]);
    check('folder '.json_encode($bad).' refused with 400 naming image.folder', 400 === $st && str_contains((string) ($a['error'] ?? ''), 'image.folder'), $a);
}
check('a refused folder saves nothing', $count === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_news') && !is_dir("$projectDir/config"));
[$st, $a] = call($c, 'update', ['image' => ['folder' => 'files/../../etc'] + $post], $entry);
check('path traversal on an update: 400, the entry keeps its image', 400 === $st && $own === $db->fetchOne('SELECT singleSRC FROM tl_news WHERE id = ?', [$entry]), $a);
unset($post['folder']);
[$st, $a] = call($c, 'create', ['archive_id' => 2, 'headline' => 'I', 'atlas_ref' => 'atlas-piece-43', 'body_html' => '<p>b</p>', 'image' => $post]);
check('no folder falls back to files/atlas-news with the atlas_ref prefix (v0.6)', 201 === $st
    && 'files/atlas-news/atlas-piece-43-brightonseo-2026-ppc-strategies.png' === ($a['image_path'] ?? null) && is_file("$projectDir/files/atlas-news/atlas-piece-43-brightonseo-2026-ppc-strategies.png"), $a);

echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
}
