<?php
// Drives the bundle's real PagesController and PageMetaWriter::findByUrl against SQLite: the address-to-page lookup (v0.8). Two table shapes: one with
// the root page's urlPrefix and urlSuffix columns (the Contao 5.x shape) and one without them (an older 4.x shape). Not a site test: proves the PHP logic only.
// Libraries: BUNDLE_TEST_VENDOR (a composer vendor/ dir with doctrine/dbal and symfony/http-foundation), else tests/lib/vendor.

declare(strict_types=1);

namespace Contao\CoreBundle\Framework { class ContaoFramework { public function initialize(): void {} public function getAdapter(string $c) { return null; } } }
namespace Contao { class PageModel {} }

namespace {
$V = getenv('BUNDLE_TEST_VENDOR') ?: __DIR__.'/lib/vendor';
require "$V/autoload.php";
spl_autoload_register(static function (string $c): void {
    $p = 'Doublespark\\ContaoAtlasBundle\\';
    if (str_starts_with($c, $p)) { $f = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($c, strlen($p))).'.php'; if (is_file($f)) { require $f; } }
});

use Doctrine\DBAL\DriverManager;
use Doublespark\ContaoAtlasBundle\Controller\PagesController;
use Doublespark\ContaoAtlasBundle\Service\PageMetaWriter;
use Symfony\Component\HttpFoundation\Request;

$fails = 0;
function check(string $what, bool $ok, $detail = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ').$what.($ok ? '' : '  '.json_encode($detail))."\n"; if (!$ok) { ++$fails; } }

function site(bool $withPrefixSuffix, array $roots, array $pages): PagesController {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $extra = $withPrefixSuffix ? ", urlPrefix varchar(128) NOT NULL default '', urlSuffix varchar(16) NOT NULL default '.html'" : '';
    $db->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, pid int NOT NULL default 0, type varchar(32) NOT NULL default 'regular', alias varchar(255) NOT NULL default '',
        dns varchar(255) NOT NULL default '', pageTitle varchar(255) NOT NULL default '', description text NULL $extra)");
    foreach ($roots as $r) { // [id, dns, prefix, suffix]
        $db->insert('tl_page', ['id' => $r[0], 'pid' => 0, 'type' => 'root', 'alias' => 'root-'.$r[0], 'dns' => $r[1]] + ($withPrefixSuffix ? ['urlPrefix' => $r[2] ?? '', 'urlSuffix' => $r[3] ?? '.html'] : []));
    }
    foreach ($pages as $p) { // [id, pid, alias]
        $db->insert('tl_page', ['id' => $p[0], 'pid' => $p[1], 'alias' => $p[2], 'pageTitle' => 'Title '.$p[0]]);
    }
    return new PagesController(new PageMetaWriter($db, new Contao\CoreBundle\Framework\ContaoFramework(), null), hash('sha256', 'tok'));
}
function get(PagesController $c, string $url): array {
    $r = new Request(['url' => $url]);
    $r->headers->set('Authorization', 'Bearer tok');
    $res = $c->show($r);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
}

foreach ([true, false] as $v5) {
    $tag = $v5 ? 'with prefix/suffix columns' : 'without prefix/suffix columns';

    // The live shape: two roots, each with a home page, different dns. The host picks the root.
    $c = site($v5, [[1, 'www.sjhallplant.com'], [2, 'other.example.com']], [[3, 1, 'index'], [161, 2, 'index'], [4, 1, 'about'], [5, 2, 'about']]);
    [$st, $a] = get($c, 'https://www.sjhallplant.com/');
    check("[$tag] two roots, different dns: home of the matching host (id 3)", 200 === $st && 3 === $a['id'], [$st, $a]);
    [$st, $a] = get($c, 'https://other.example.com/');
    check("[$tag] two roots, different dns: the other host gets its own home (id 161)", 200 === $st && 161 === $a['id'], [$st, $a]);
    [$st, $a] = get($c, 'https://sjhallplant.com/about.html');
    check("[$tag] apex host reaches the www root, suffix taken off (id 4)", 200 === $st && 4 === $a['id'], [$st, $a]);
    [$st, $a] = get($c, 'https://unknown.example.org/about');
    check("[$tag] a host no root answers is 404", 404 === $st, [$st, $a]);

    // One root with an empty dns, one with a matching dns: the matching dns wins for its host, the empty one answers every other host.
    $c = site($v5, [[1, ''], [2, 'www.sjhallplant.com']], [[3, 1, 'index'], [161, 2, 'index']]);
    [$st, $a] = get($c, 'https://www.sjhallplant.com/');
    check("[$tag] empty-dns root and matching-dns root: the matching one wins (id 161)", 200 === $st && 161 === $a['id'], [$st, $a]);
    [$st, $a] = get($c, 'https://staging.example.net/');
    check("[$tag] empty-dns root answers any other host (id 3)", 200 === $st && 3 === $a['id'], [$st, $a]);

    // A genuine duplicate inside one root stays a 409.
    $c = site($v5, [[1, 'www.sjhallplant.com']], [[3, 1, 'index'], [161, 1, 'index'], [4, 3, 'x']]);
    [$st, $a] = get($c, 'https://www.sjhallplant.com/');
    check("[$tag] two home pages inside one root: still 409, ids named", 409 === $st && str_contains($a['error'], '3, 161'), [$st, $a]);

    // Nested pages belong to the root found by walking up.
    $c = site($v5, [[1, 'a.example.com'], [2, 'b.example.com']], [[10, 1, 'services'], [11, 10, 'services/design'], [20, 2, 'services'], [21, 20, 'services/design']]);
    [$st, $a] = get($c, 'https://b.example.com/services/design');
    check("[$tag] nested page resolves under its own root (id 21)", 200 === $st && 21 === $a['id'], [$st, $a]);

    // A bare path has no host to filter by, so the old behaviour holds: two roots sharing the alias is a 409.
    [$st, $a] = get($c, '/services');
    check("[$tag] bare path across two roots is 409", 409 === $st, [$st, $a]);
    check("[$tag] no token is 401", 401 === (new PagesController(new PageMetaWriter(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new Contao\CoreBundle\Framework\ContaoFramework(), null), hash('sha256', 'tok')))->show(new Request(['url' => 'x']))->getStatusCode());
}

// Prefix and suffix, only on the shape that has the columns.
$c = site(true, [[1, 'www.example.com', 'en', '.html'], [2, 'www.example.com', 'de', '.html'], [3, 'www.example.com', 'fr', '']], [[10, 1, 'index'], [20, 2, 'index'], [30, 3, 'index'], [11, 1, 'about'], [31, 3, 'about']]);
[$st, $a] = get($c, 'https://www.example.com/en/');
check('prefix: /en/ is the English home (id 10)', 200 === $st && 10 === $a['id'], [$st, $a]);
[$st, $a] = get($c, 'https://www.example.com/de');
check('prefix: /de is the German home (id 20)', 200 === $st && 20 === $a['id'], [$st, $a]);
[$st, $a] = get($c, 'https://www.example.com/en/about.html');
check('prefix and suffix: /en/about.html is id 11', 200 === $st && 11 === $a['id'], [$st, $a]);
[$st, $a] = get($c, 'https://www.example.com/fr/about');
check('prefix, empty suffix: /fr/about is id 31', 200 === $st && 31 === $a['id'], [$st, $a]);
[$st, $a] = get($c, 'https://www.example.com/es/about');
check('prefix: a language no root has is 404', 404 === $st, [$st, $a]);

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
}
