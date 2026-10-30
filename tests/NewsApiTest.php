<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Tests;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doublespark\ContaoAtlasBundle\Controller\NewsController;
use Doublespark\ContaoAtlasBundle\Service\NewsWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hermetic: an in-memory SQLite database stands in for the site's, no Contao kernel, no network. The controller and the writer
 * are the real classes. The schema below is the minimum the writer reads; optional tl_news columns are added per test.
 */
final class NewsApiTest extends TestCase
{
    private const TOKEN = 'test-token';

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY)',
            'CREATE TABLE tl_user (id INTEGER PRIMARY KEY)',
            'CREATE TABLE tl_news (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, headline TEXT NOT NULL DEFAULT \'\', alias TEXT NOT NULL DEFAULT \'\', author INTEGER NOT NULL DEFAULT 0, date INTEGER NOT NULL DEFAULT 0, time INTEGER NOT NULL DEFAULT 0, teaser TEXT NULL, source TEXT NOT NULL DEFAULT \'\', published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT \'\', stop TEXT NOT NULL DEFAULT \'\', pageTitle TEXT NULL, description TEXT NULL)',
            'CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT \'\', sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT \'\', headline TEXT NOT NULL DEFAULT \'\', text TEXT NULL, cssID TEXT NULL)',
            'CREATE TABLE tl_atlas_news_ref (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, atlas_ref TEXT NOT NULL, news_id INTEGER NOT NULL, content_id INTEGER NOT NULL DEFAULT 0)',
            'INSERT INTO tl_news_archive (id) VALUES (2)',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
    }

    private function controller(): NewsController
    {
        $writer = new NewsWriter($this->db, $this->createStub(ContaoFramework::class), null, 'Europe/London');

        return new NewsController($writer, hash('sha256', self::TOKEN));
    }

    /** @return array{0:int,1:array<string,mixed>} status and decoded answer */
    private function send(string $method, string $uri, array $body): array
    {
        $request = Request::create($uri, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN], json_encode($body, JSON_THROW_ON_ERROR));
        $controller = $this->controller();
        $response = preg_match('#/news/(\d+)$#', $uri, $m) ? $controller->update($request, (int) $m[1]) : $controller->create($request);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function base(array $extra = []): array
    {
        return $extra + ['archive_id' => 2, 'headline' => 'A post', 'atlas_ref' => 'atlas-piece-1', 'published' => false];
    }

    private function threeElements(): array
    {
        return [
            ['key' => 'tldr', 'section_id' => 'tldr', 'headline' => '', 'html' => '<p>TL;DR</p>'],
            ['key' => 'at_a_glance', 'section_id' => 'at-a-glance', 'headline' => 'At A Glance', 'html' => '<ul><li>a</li></ul>'],
            ['key' => 'body', 'section_id' => '', 'headline' => '', 'html' => '<h2>Body</h2><p>Text</p>'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function content(int $newsId): array
    {
        return $this->db->fetchAllAssociative("SELECT * FROM tl_content WHERE ptable = 'tl_news' AND pid = ? ORDER BY sorting ASC", [$newsId]);
    }

    public function testElementListWritesOneElementPerEntryInOrder(): void
    {
        [$status, $out] = $this->send('POST', '/atlas-api/news', $this->base(['elements' => $this->threeElements()]));

        self::assertSame(201, $status);
        self::assertSame(3, $out['elements_count']);
        $rows = $this->content($out['id']);
        self::assertCount(3, $rows);
        self::assertSame(['<p>TL;DR</p>', '<ul><li>a</li></ul>', '<h2>Body</h2><p>Text</p>'], array_column($rows, 'text'));
        self::assertSame(['text', 'text', 'text'], array_column($rows, 'type'));
        self::assertTrue($rows[0]['sorting'] < $rows[1]['sorting'] && $rows[1]['sorting'] < $rows[2]['sorting']);
        // headline: empty means none, else Contao's serialised h2
        self::assertSame('', $rows[0]['headline']);
        self::assertSame(['unit' => 'h2', 'value' => 'At A Glance'], unserialize($rows[1]['headline']));
        // cssID: the id part is section_id, empty when there is none
        self::assertSame(['tldr', ''], unserialize($rows[0]['cssID']));
        self::assertSame(['at-a-glance', ''], unserialize($rows[1]['cssID']));
        self::assertSame(['', ''], unserialize($rows[2]['cssID']));
    }

    public function testBodyHtmlAloneIsUnchanged(): void
    {
        [$status, $out] = $this->send('POST', '/atlas-api/news', $this->base(['body_html' => '<p>One body</p>']));

        self::assertSame(201, $status);
        self::assertSame(1, $out['elements_count']);
        $rows = $this->content($out['id']);
        self::assertCount(1, $rows);
        self::assertSame('<p>One body</p>', $rows[0]['text']);
        self::assertSame('', $rows[0]['headline']);
        self::assertNull($rows[0]['cssID']);

        [$status, $again] = $this->send('POST', '/atlas-api/news/'.$out['id'], ['body_html' => '<p>Changed</p>']);
        self::assertSame(200, $status);
        self::assertSame(1, $again['elements_count']);
        self::assertSame(['<p>Changed</p>'], array_column($this->content($out['id']), 'text'));
    }

    public function testUpdateReplacesTheElementList(): void
    {
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['elements' => $this->threeElements()]));
        [$status, $answer] = $this->send('PUT', '/atlas-api/news/'.$out['id'], ['elements' => [['key' => 'body', 'section_id' => '', 'headline' => '', 'html' => '<p>Only</p>']]]);

        self::assertSame(200, $status);
        self::assertSame(1, $answer['elements_count']);
        self::assertSame(['<p>Only</p>'], array_column($this->content($out['id']), 'text'));
    }

    public function testUpdateFailureLeavesTheOldElementsIntact(): void
    {
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['elements' => $this->threeElements()]));
        $before = $this->content($out['id']);
        // The second insert fails, after the old elements were deleted inside the transaction.
        $this->db->executeStatement("CREATE TRIGGER fail_on_boom BEFORE INSERT ON tl_content WHEN NEW.text = '<p>BOOM</p>' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");

        [$status] = $this->send('PUT', '/atlas-api/news/'.$out['id'], ['elements' => [
            ['key' => 'a', 'section_id' => '', 'headline' => '', 'html' => '<p>New first</p>'],
            ['key' => 'b', 'section_id' => '', 'headline' => '', 'html' => '<p>BOOM</p>'],
        ]]);

        self::assertSame(500, $status);
        self::assertSame($before, $this->content($out['id']));
    }

    public function testExtraFieldsLandInAppliedOrUnsupportedByTheSchema(): void
    {
        $this->db->executeStatement('ALTER TABLE tl_news ADD COLUMN featured INTEGER NOT NULL DEFAULT 0');
        $this->db->executeStatement('ALTER TABLE tl_news ADD COLUMN og_title TEXT NULL');
        // no link_text and no og_description column on this site
        [$status, $out] = $this->send('POST', '/atlas-api/news', $this->base([
            'body_html' => '<p>x</p>', 'featured' => true, 'og_title' => 'Share title', 'link_text' => 'Read it', 'og_description' => 'Share text',
        ]));

        self::assertSame(201, $status);
        self::assertSame(['featured', 'og_title'], $out['fields_applied']);
        self::assertSame(['link_text', 'og_description'], $out['fields_unsupported']);
        $row = $this->db->fetchAssociative('SELECT featured, og_title FROM tl_news WHERE id = ?', [$out['id']]);
        self::assertSame(1, (int) $row['featured']);
        self::assertSame('Share title', $row['og_title']);
    }

    public function testFieldsNotSentAreNotListed(): void
    {
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['body_html' => '<p>x</p>']));

        self::assertSame([], $out['fields_applied']);
        self::assertSame([], $out['fields_unsupported']);
        self::assertSame([], $out['category_ids']);
        self::assertSame(['id', 'url', 'author_id', 'category_ids', 'elements_count', 'fields_applied', 'fields_unsupported'], array_keys($out));
    }

    public function testPublishedIsUntouchedByAnUpdateThatDoesNotSendIt(): void
    {
        $this->db->executeStatement('ALTER TABLE tl_news ADD COLUMN featured INTEGER NOT NULL DEFAULT 0');
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['body_html' => '<p>x</p>', 'published' => true]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT published FROM tl_news WHERE id = ?', [$out['id']]));

        $this->send('PUT', '/atlas-api/news/'.$out['id'], ['elements' => $this->threeElements(), 'featured' => true, 'headline' => 'Retitled']);

        self::assertSame(1, (int) $this->db->fetchOne('SELECT published FROM tl_news WHERE id = ?', [$out['id']]));
    }

    public function testBothBodiesAreRefusedAndNothingIsWritten(): void
    {
        [$status, $out] = $this->send('POST', '/atlas-api/news', $this->base(['body_html' => '<p>x</p>', 'elements' => $this->threeElements()]));

        self::assertSame(422, $status);
        self::assertArrayHasKey('error', $out);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_news'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_content'));
    }

    #[DataProvider('badLists')]
    public function testMalformedListsAreRefusedBeforeAnythingIsWritten(array $elements): void
    {
        [$status, $out] = $this->send('POST', '/atlas-api/news', $this->base(['elements' => $elements]));

        self::assertSame(422, $status);
        self::assertIsString($out['error']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_news'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_content'));
    }

    public static function badLists(): iterable
    {
        yield 'missing html' => [[['key' => 'a', 'section_id' => '', 'headline' => '']]];
        yield 'empty html' => [[['key' => 'a', 'section_id' => '', 'headline' => '', 'html' => '  ']]];
        yield 'section id with a space' => [[['key' => 'a', 'section_id' => 'at a glance', 'headline' => '', 'html' => '<p>x</p>']]];
        yield 'section id starting with a digit' => [[['key' => 'a', 'section_id' => '1st', 'headline' => '', 'html' => '<p>x</p>']]];
        yield 'second entry bad' => [[['key' => 'a', 'section_id' => 'ok', 'headline' => '', 'html' => '<p>x</p>'], ['key' => 'b', 'section_id' => '#x', 'headline' => '', 'html' => '<p>y</p>']]];
        yield 'empty list' => [[]];
    }

    public function testBadListOnUpdateLeavesTheOldBody(): void
    {
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['elements' => $this->threeElements()]));
        $before = $this->content($out['id']);

        [$status] = $this->send('PUT', '/atlas-api/news/'.$out['id'], ['elements' => [['key' => 'a', 'section_id' => 'bad id', 'headline' => '', 'html' => '<p>x</p>']]]);

        self::assertSame(422, $status);
        self::assertSame($before, $this->content($out['id']));
    }

    public function testRefusalTextDoesNotTriggerAtlasElementListDetection(): void
    {
        [, $out] = $this->send('POST', '/atlas-api/news', $this->base(['body_html' => '<p>x</p>', 'elements' => $this->threeElements()]));

        // web/lib/contaoPublish.ts treats any 4xx whose text contains "elements" or "element list" as "bundle cannot take a list".
        self::assertStringNotContainsStringIgnoringCase('element', $out['error']);
    }
}
