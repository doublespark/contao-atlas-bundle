<?php
// v0.7. Proves the image meta writer's decisions with NO libraries: a fake gateway stands in for Contao and the database, so this runs on any PHP 8.1+
// (php tests/image_meta.php). It covers the meta array handling (ImageMeta), the expected-value refusal (409 with the live values, nothing written),
// the file path (only that language's alt and title change, caption/link/license and other languages survive), the placement path (override fields
// only, only while overwriteMeta is on, the row must hold the uuid, galleries refused) and what is invalidated and logged. Not a site test: the real
// FilesModel save, the cache tag manager and the log are proved by the Atlas dry run and verification on a site.

declare(strict_types=1);

namespace Contao\CoreBundle\Monolog { class ContaoContext { public const GENERAL = 'GENERAL'; public function __construct(public string $func, public string $action) {} } }

namespace {
spl_autoload_register(static function (string $c): void {
    $p = 'Doublespark\\ContaoAtlasBundle\\';
    if (str_starts_with($c, $p)) { $f = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($c, strlen($p))).'.php'; if (is_file($f)) { require $f; } }
});

use Doublespark\ContaoAtlasBundle\Service\CacheTags;
use Doublespark\ContaoAtlasBundle\Service\ImageMeta;
use Doublespark\ContaoAtlasBundle\Service\ImageMetaGateway;
use Doublespark\ContaoAtlasBundle\Service\ImageMetaRefused;
use Doublespark\ContaoAtlasBundle\Service\ImageMetaWriter;

$fails = 0;
function check(string $what, bool $ok, $detail = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ').$what.($ok ? '' : '  '.json_encode($detail))."\n"; if (!$ok) { ++$fails; } }

final class FakeGateway implements ImageMetaGateway
{
    public array $savedMeta = [];
    public array $savedRows = [];
    public function __construct(public array $files = [], public array $tables = [], public array $types = [], public ?string $fallback = 'en', public array $placements = []) {}
    public function fallbackLanguage(): ?string { return $this->fallback; }
    public function file(string $uuidHex): ?array { return $this->files[$uuidHex] ?? null; }
    public function saveFileMeta(string $uuidHex, string $serialised): void { $this->savedMeta[$uuidHex] = $serialised; $this->files[$uuidHex]['meta'] = $serialised; }
    public function columns(string $table): array { return array_keys($this->types[$table] ?? []); }
    public function columnType(string $table, string $column): ?string { return $this->types[$table][$column] ?? null; }
    public function row(string $table, int $id): ?array { return $this->tables[$table][$id] ?? null; }
    public function saveRow(string $table, int $id, array $values): void { $this->savedRows[] = [$table, $id, $values]; $this->tables[$table][$id] = $values + $this->tables[$table][$id]; }
    public function parentTags(string $table, array $row): array { return ['contao.db.tl_article.7', 'contao.db.tl_page.3']; }
    public function referenceTags(string $uuidHex, int $fileId): array { return CacheTags::forImage($fileId, $this->placements); }
}
final class FakeTags { public array $invalidated = []; public function invalidateTags(array $t): void { $this->invalidated[] = $t; } }
final class FakeLog { public array $lines = []; public function info(string $m, array $c = []): void { $this->lines[] = [$m, $c]; } }

$hex = str_repeat('ab', 16);
$other = str_repeat('cd', 16);
$meta = ['en' => ['title' => 'Old title', 'alt' => 'Old alt', 'link' => 'https://x.test/', 'caption' => 'A caption', 'license' => 'CC0'], 'de' => ['title' => 'Alter Titel', 'alt' => 'Alter Alt']];

function files(string $hex, array $meta): array { return [$hex => ['id' => 42, 'meta' => serialize($meta)]]; }
function placementTypes(): array { return ['tl_content' => ['id' => 'int(10) unsigned', 'pid' => 'int(10) unsigned', 'singleSRC' => 'binary(16)', 'multiSRC' => 'blob', 'overwriteMeta' => 'char(1)', 'alt' => 'varchar(255)', 'imageTitle' => 'varchar(255)', 'tstamp' => 'int(10) unsigned']]; }
function refused(callable $f): ?ImageMetaRefused { try { $f(); } catch (ImageMetaRefused $e) { return $e; } return null; }
function writer(ImageMetaGateway $g, ?FakeTags $t = null, ?FakeLog $l = null): ImageMetaWriter { return new ImageMetaWriter($g, $t, $l, static fn (): int => 1700000000); }

// --- ImageMeta: the array handling ---
check('parse: empty and missing values are an empty list', ImageMeta::parse(null) === [] && ImageMeta::parse('') === [] && ImageMeta::parse('a:0:{}') === []);
check('parse: junk is unreadable, not empty', null === ImageMeta::parse('not serialised') && null === ImageMeta::parse('s:3:"abc";') && null === ImageMeta::parse(12));
check('parse: objects are never instantiated', null === ImageMeta::parse('O:8:"stdClass":0:{}'));
check('current: reads one language, missing language and missing keys read empty', ImageMeta::current($meta, 'en') === ['alt' => 'Old alt', 'title' => 'Old title'] && ImageMeta::current($meta, 'fr') === ['alt' => '', 'title' => ''] && ImageMeta::current(['en' => ['alt' => 'x']], 'en') === ['alt' => 'x', 'title' => '']);
$applied = ImageMeta::apply($meta, 'en', ['alt' => 'New alt', 'title' => 'New title']);
check('apply: changes only alt and title of that language', $applied['en']['alt'] === 'New alt' && $applied['en']['title'] === 'New title');
check('apply: caption, link, license and the other language survive', $applied['en']['caption'] === 'A caption' && $applied['en']['link'] === 'https://x.test/' && $applied['en']['license'] === 'CC0' && $applied['de'] === $meta['de']);
check('apply: the key order of the language is kept', array_keys($applied['en']) === array_keys($meta['en']));
check('apply: one field only leaves the other alone', ImageMeta::apply($meta, 'en', ['alt' => 'Only alt'])['en']['title'] === 'Old title');
check('apply: a new language is added with just alt and title', ImageMeta::apply($meta, 'fr', ['alt' => 'A', 'title' => 'T'])['fr'] === ['alt' => 'A', 'title' => 'T']);
check('serialise round-trips through parse', ImageMeta::parse(ImageMeta::serialise($applied)) === $applied);

// --- the file path ---
$g = new FakeGateway(files($hex, $meta)); $tags = new FakeTags(); $log = new FakeLog();
$r = writer($g, $tags, $log)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'New alt', 'title' => 'New title', 'expected_alt' => 'Old alt', 'expected_title' => 'Old title']);
check('file: before and after are returned', $r['before'] === ['alt' => 'Old alt', 'title' => 'Old title'] && $r['after'] === ['alt' => 'New alt', 'title' => 'New title'] && true === $r['changed']);
$saved = ImageMeta::parse($g->savedMeta[$hex] ?? null);
check('file: saved meta has the new alt and title and keeps caption, link, license and German', is_array($saved) && $saved['en']['alt'] === 'New alt' && $saved['en']['caption'] === 'A caption' && $saved['en']['link'] === 'https://x.test/' && $saved['en']['license'] === 'CC0' && $saved['de'] === $meta['de'], $saved);
check('file: the file cache tag is invalidated and reported', $tags->invalidated === [['contao.db.tl_files.42']] && true === $r['cache_cleared'] && $r['cache_tags'] === ['contao.db.tl_files.42']);

check('file: the Contao log names Atlas', 1 === count($log->lines) && str_contains($log->lines[0][0], 'Atlas') && str_contains($log->lines[0][0], 'image file ID 42') && true === $r['logged'] && isset($log->lines[0][1]['contao']));
$r = writer($g)->write(['uuid' => strtoupper($hex), 'language' => 'en', 'target' => 'file', 'alt' => 'New alt', 'expected_alt' => 'New alt']);
check('file: writing the value already live changes nothing and invalidates nothing', false === $r['changed'] && false === $r['cache_cleared'] && [] === $r['cache_tags']);
$g = new FakeGateway(files($hex, $meta));
$r = writer($g)->write(['uuid' => $hex, 'target' => 'file', 'alt' => 'Default language alt', 'expected_alt' => 'Old alt']);
check('file: no language means the site\'s fallback language', 'en' === $r['language'] && 'Default language alt' === ImageMeta::parse($g->savedMeta[$hex])['en']['alt']);
$e = refused(fn () => writer(new FakeGateway(files($hex, $meta), [], [], null))->write(['uuid' => $hex, 'target' => 'file', 'alt' => 'x', 'expected_alt' => 'Old alt']));
check('file: no language and no fallback root is a 422', 422 === $e?->status);
$g = new FakeGateway(files($hex, $meta));
writer($g)->write(['uuid' => $hex, 'language' => 'fr', 'target' => 'file', 'alt' => 'Alt FR', 'expected_alt' => '']);
check('file: a language the file has no entry for is written without touching the others', ImageMeta::parse($g->savedMeta[$hex])['fr']['alt'] === 'Alt FR' && ImageMeta::parse($g->savedMeta[$hex])['en'] === $meta['en']);
$g = new FakeGateway([$hex => ['id' => 5, 'meta' => null]]);
writer($g)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'First alt', 'title' => 'First title', 'expected_alt' => '', 'expected_title' => null]);
check('file: a file with no meta at all takes the first values (expected "" and null both mean empty)', ImageMeta::parse($g->savedMeta[$hex]) === ['en' => ['alt' => 'First alt', 'title' => 'First title']]);

// --- the expected-value refusal ---
$g = new FakeGateway(files($hex, $meta)); $tags = new FakeTags(); $log = new FakeLog();
$e = refused(fn () => writer($g, $tags, $log)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'New alt', 'title' => 'New title', 'expected_alt' => 'Old alt', 'expected_title' => 'Someone edited this']));
check('409: a title changed since the inventory refuses the whole write', 409 === $e?->status);
check('409: the answer carries the live values and which field differs', ($e?->body['live'] ?? null) === ['alt' => 'Old alt', 'title' => 'Old title'] && ($e?->body['mismatch']['title'] ?? null) === ['expected' => 'Someone edited this', 'live' => 'Old title'] && !isset($e?->body['mismatch']['alt']), $e?->body);
check('409: nothing was saved, invalidated or logged', [] === $g->savedMeta && [] === $tags->invalidated && [] === $log->lines);
$e = refused(fn () => writer($g)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'New alt', 'expected_alt' => 'Old alt ']));
check('409: the comparison is exact text (a trailing space differs)', 409 === $e?->status);
$e = refused(fn () => writer($g)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'New alt']));
check('400: expected_alt must be sent with alt', 400 === $e?->status && [] === $g->savedMeta);
$g = new FakeGateway([$hex => ['id' => 1, 'meta' => 'garbage']]);
$e = refused(fn () => writer($g)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'x', 'expected_alt' => '']));
check('422: unreadable meta is never replaced', 422 === $e?->status && [] === $g->savedMeta);
check('404: unknown file', 404 === refused(fn () => writer(new FakeGateway())->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'x', 'expected_alt' => '']))?->status);
check('400: bad uuid, no fields, bad target, long alt, bad language', 400 === refused(fn () => writer(new FakeGateway())->write(['uuid' => 'xyz', 'target' => 'file', 'alt' => 'x', 'expected_alt' => '']))?->status
    && 400 === refused(fn () => writer(new FakeGateway(files($hex, $meta)))->write(['uuid' => $hex, 'target' => 'file']))?->status
    && 400 === refused(fn () => writer(new FakeGateway(files($hex, $meta)))->write(['uuid' => $hex, 'target' => 'folder', 'alt' => 'x', 'expected_alt' => '']))?->status
    && 400 === refused(fn () => writer(new FakeGateway(files($hex, $meta)))->write(['uuid' => $hex, 'target' => 'file', 'alt' => str_repeat('a', 256), 'expected_alt' => '']))?->status
    && 400 === refused(fn () => writer(new FakeGateway(files($hex, $meta)))->write(['uuid' => $hex, 'language' => '../x', 'target' => 'file', 'alt' => 'x', 'expected_alt' => 'Old alt']))?->status);

// --- the placement path ---
$row = ['id' => 12, 'pid' => 7, 'singleSRC' => hex2bin($hex), 'multiSRC' => null, 'overwriteMeta' => '1', 'alt' => 'Override alt', 'imageTitle' => 'Override title'];
$types = placementTypes();
$target = ['table' => 'tl_content', 'id' => 12, 'column' => 'singleSRC'];
$g = new FakeGateway(files($hex, $meta), ['tl_content' => [12 => $row]], $types); $tags = new FakeTags(); $log = new FakeLog();
$r = writer($g, $tags, $log)->write(['uuid' => $hex, 'target' => $target, 'alt' => 'Placement alt', 'title' => 'Placement title', 'expected_alt' => 'Override alt', 'expected_title' => 'Override title']);
check('placement: before and after, and the override columns are the only ones written', $r['before'] === ['alt' => 'Override alt', 'title' => 'Override title'] && $r['after'] === ['alt' => 'Placement alt', 'title' => 'Placement title']
    && $g->savedRows === [['tl_content', 12, ['alt' => 'Placement alt', 'imageTitle' => 'Placement title', 'tstamp' => 1700000000]]], $g->savedRows);
check('placement: the file\'s own meta is not touched', [] === $g->savedMeta);
check('placement: the row\'s tag and its parents\' tags are invalidated', $tags->invalidated === [['contao.db.tl_content.12', 'contao.db.tl_article.7', 'contao.db.tl_page.3']] && true === $r['cache_cleared']);
check('placement: logged, naming Atlas and the element', 1 === count($log->lines) && str_contains($log->lines[0][0], 'Atlas') && str_contains($log->lines[0][0], 'tl_content ID 12'));
$g = new FakeGateway(files($hex, $meta), ['tl_content' => [12 => $row]], $types); $tags = new FakeTags();
writer($g, $tags)->write(['uuid' => $hex, 'target' => $target, 'title' => 'Only title', 'expected_title' => 'Override title']);
check('placement: sending only the title leaves the alt column out of the update', $g->savedRows === [['tl_content', 12, ['imageTitle' => 'Only title', 'tstamp' => 1700000000]]], $g->savedRows);
$e = refused(fn () => writer($g)->write(['uuid' => $hex, 'target' => $target, 'alt' => 'x', 'expected_alt' => 'What Atlas thought']));
check('placement 409: the live override is returned and nothing written', 409 === $e?->status && ($e?->body['live']['alt'] ?? null) === 'Override alt' && 1 === count($g->savedRows), $e?->body);
$off = $row; $off['overwriteMeta'] = '';
$e = refused(fn () => writer(new FakeGateway([], ['tl_content' => [12 => $off]], $types))->write(['uuid' => $hex, 'target' => $target, 'alt' => 'x', 'expected_alt' => 'Override alt']));
check('placement 422: overwriteMeta off means no override is in use, so nothing is written', 422 === $e?->status);
$e = refused(fn () => writer(new FakeGateway([], ['tl_content' => [12 => $row]], $types))->write(['uuid' => $other, 'target' => $target, 'alt' => 'x', 'expected_alt' => 'Override alt']));
check('placement 422: a row that does not hold this image is refused', 422 === $e?->status);
$e = refused(fn () => writer(new FakeGateway([], ['tl_content' => [12 => $row]], $types))->write(['uuid' => $hex, 'target' => ['table' => 'tl_content', 'id' => 12, 'column' => 'multiSRC'], 'alt' => 'x', 'expected_alt' => 'Override alt']));
check('placement 422: a gallery column has no per-image override', 422 === $e?->status);
$e = refused(fn () => writer(new FakeGateway([], [], ['tl_news' => ['id' => 'int(10)', 'singleSRC' => 'binary(16)']]))->write(['uuid' => $hex, 'target' => ['table' => 'tl_news', 'id' => 1, 'column' => 'singleSRC'], 'alt' => 'x', 'expected_alt' => '']));
check('placement 422: a table with no alt, imageTitle and overwriteMeta columns is refused', 422 === $e?->status);
check('placement 404: no such row', 404 === refused(fn () => writer(new FakeGateway([], [], $types))->write(['uuid' => $hex, 'target' => $target, 'alt' => 'x', 'expected_alt' => '']))?->status);
check('placement 400: a table name that is not a tl_ table, or an id that is not a whole number', 400 === refused(fn () => writer(new FakeGateway([], [], $types))->write(['uuid' => $hex, 'target' => ['table' => 'users; drop', 'id' => 1, 'column' => 'singleSRC'], 'alt' => 'x', 'expected_alt' => '']))?->status
    && 400 === refused(fn () => writer(new FakeGateway([], [], $types))->write(['uuid' => $hex, 'target' => ['table' => 'tl_content', 'id' => '12', 'column' => 'singleSRC'], 'alt' => 'x', 'expected_alt' => '']))?->status);
$g = new FakeGateway([], ['tl_content' => [12 => $row]], $types);
$r = writer($g)->write(['uuid' => $hex, 'target' => $target, 'alt' => 'Override alt', 'expected_alt' => 'Override alt']);
check('no tag manager: the write still happens and the answer says cache_cleared false', false === $r['cache_cleared'] && false === $r['changed'] && [] === $g->savedRows);
$g = new FakeGateway([], ['tl_content' => [12 => $row]], $types);
$r = writer($g)->write(['uuid' => $hex, 'target' => $target, 'alt' => 'Changed', 'expected_alt' => 'Override alt']);
check('no tag manager and no logger: changed true, cache_cleared false, logged false', true === $r['changed'] && false === $r['cache_cleared'] && false === $r['logged']);

// --- v0.7.1: the cache tags of every page that shows the image (CacheTags, pure) ---
$parent = static fn (array $over = []): array => $over + ['page_id' => null, 'article_id' => null, 'news_id' => null, 'news_archive_id' => null, 'content_chain' => [], 'parent_table' => null, 'parent_id' => null];
check('tags: a file is tagged by its tl_files row', 'contao.db.tl_files.42' === CacheTags::forFile(42));
check('tags: a content element in an article gives the element, its article and its page',
    CacheTags::forPlacement(['table' => 'tl_content', 'row_id' => 12, 'parent' => $parent(['article_id' => 7, 'page_id' => 3])]) === ['contao.db.tl_content.12', 'contao.db.tl_article.7', 'contao.db.tl_page.3']);
check('tags: an element nested in element groups gives each enclosing element too, then the article and page',
    CacheTags::forPlacement(['table' => 'tl_content', 'row_id' => 43, 'parent' => $parent(['content_chain' => [42, 41], 'article_id' => 7, 'page_id' => 3])])
    === ['contao.db.tl_content.43', 'contao.db.tl_content.42', 'contao.db.tl_content.41', 'contao.db.tl_article.7', 'contao.db.tl_page.3']);
check('tags: an element of a news entry gives the entry and its archive (the list pages), no page',
    CacheTags::forPlacement(['table' => 'tl_content', 'row_id' => 50, 'parent' => $parent(['news_id' => 30, 'news_archive_id' => 2])]) === ['contao.db.tl_content.50', 'contao.db.tl_news.30', 'contao.db.tl_news_archive.2']);
check('tags: the news entry\'s own image gives the entry and the archive',
    CacheTags::forPlacement(['table' => 'tl_news', 'row_id' => 30, 'parent' => $parent(['news_id' => 30, 'news_archive_id' => 2])]) === ['contao.db.tl_news.30', 'contao.db.tl_news_archive.2']);
check('tags: a page\'s own image (an og image column) gives the page once',
    CacheTags::forPlacement(['table' => 'tl_page', 'row_id' => 10, 'parent' => $parent(['page_id' => 10])]) === ['contao.db.tl_page.10']);
check('tags: a custom table with an unknown parent gives its own tag and that parent',
    CacheTags::forPlacement(['table' => 'tl_ds_portfolio', 'row_id' => 50, 'parent' => $parent(['parent_table' => 'tl_ds_category', 'parent_id' => 4])]) === ['contao.db.tl_ds_portfolio.50', 'contao.db.tl_ds_category.4']);
check('tags: nothing is invented for missing parents, and a table name that is not tl_ is ignored',
    CacheTags::forPlacement(['table' => 'tl_content', 'row_id' => 9, 'parent' => $parent()]) === ['contao.db.tl_content.9'] && [] === CacheTags::forPlacement(['table' => 'users', 'row_id' => 1, 'parent' => $parent()]));
$two = [
    ['table' => 'tl_content', 'row_id' => 12, 'parent' => $parent(['article_id' => 7, 'page_id' => 3])],
    ['table' => 'tl_content', 'row_id' => 13, 'parent' => $parent(['article_id' => 7, 'page_id' => 3])],
    ['table' => 'tl_news', 'row_id' => 30, 'parent' => $parent(['news_id' => 30, 'news_archive_id' => 2])],
];
check('tags: an image shown in several places gives the file tag first and every tag once',
    CacheTags::forImage(42, $two) === ['contao.db.tl_files.42', 'contao.db.tl_content.12', 'contao.db.tl_article.7', 'contao.db.tl_page.3', 'contao.db.tl_content.13', 'contao.db.tl_news.30', 'contao.db.tl_news_archive.2']);
check('tags: an image placed nowhere still gives its file tag', CacheTags::forImage(42, []) === ['contao.db.tl_files.42']);

// --- v0.7.1: a file write invalidates the referencing rows' tags; the invalidate call changes no data ---
$g = new FakeGateway(files($hex, $meta), [], [], 'en', $two); $tags = new FakeTags(); $log = new FakeLog();
$r = writer($g, $tags, $log)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'New alt', 'expected_alt' => 'Old alt']);
$all = CacheTags::forImage(42, $two);
check('file write: every referencing row\'s tag and parent tag is invalidated in one call and returned in full', $tags->invalidated === [$all] && $r['cache_tags'] === $all && true === $r['cache_cleared'], $r['cache_tags']);
$g = new FakeGateway(files($hex, $meta), [], [], 'en', $two); $tags = new FakeTags(); $log = new FakeLog();
$r = writer($g, $tags, $log)->write(['uuid' => $hex, 'language' => 'en', 'target' => 'file', 'alt' => 'Old alt', 'expected_alt' => 'Old alt']);
check('file write that changes nothing: no tag invalidated', [] === $tags->invalidated && [] === $r['cache_tags']);
$g = new FakeGateway(files($hex, $meta), [], [], 'en', $two); $tags = new FakeTags(); $log = new FakeLog();
$r = writer($g, $tags, $log)->invalidate(['uuid' => strtoupper($hex)]);
check('invalidate: the same tags are cleared, nothing is saved, the answer lists them all', $tags->invalidated === [$all] && $r['cache_tags'] === $all && true === $r['cache_cleared'] && false === $r['changed'] && [] === $g->savedMeta && [] === $g->savedRows, $r);
check('invalidate: logged, naming Atlas and saying no data changed', 1 === count($log->lines) && str_contains($log->lines[0][0], 'Atlas') && str_contains($log->lines[0][0], 'no data changed'));
$r = writer(new FakeGateway(files($hex, $meta), [], [], 'en', $two))->invalidate(['uuid' => $hex]);
check('invalidate with no tag manager: the tags are still listed, cache_cleared false', false === $r['cache_cleared'] && $r['cache_tags'] === $all);
check('invalidate: unknown file is 404, a bad uuid is 400', 404 === refused(fn () => writer(new FakeGateway())->invalidate(['uuid' => $hex]))?->status && 400 === refused(fn () => writer(new FakeGateway())->invalidate(['uuid' => 'zz']))?->status);

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
}
