<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

/**
 * v0.7. Everything ImageMetaWriter needs from the site, behind one small interface so the writer's decisions (the expected-value refusal, the
 * placement rules, what is invalidated and logged) can be proved without Contao or a database. ContaoImageMetaGateway is the real one.
 */
interface ImageMetaGateway
{
    /** The site's fallback root language (tl_page, type root, fallback set), or null when none can be worked out. */
    public function fallbackLanguage(): ?string;

    /** @return array{id:int,meta:mixed}|null the tl_files row of an image file by its uuid (lower-case hex), or null */
    public function file(string $uuidHex): ?array;

    /** Saves the file's meta (an already serialised value) through Contao's FilesModel. */
    public function saveFileMeta(string $uuidHex, string $serialised): void;

    /** @return list<string> the column names of the table, empty when it does not exist or is not a tl_ table */
    public function columns(string $table): array;

    /** The type of one column in lower case (binary(16), blob, ...), or null when the table or column does not exist. */
    public function columnType(string $table, string $column): ?string;

    /** @return array<string, mixed>|null the placement row */
    public function row(string $table, int $id): ?array;

    /** Updates the given columns of one placement row (the writer only ever passes alt, imageTitle and tstamp). */
    public function saveRow(string $table, int $id, array $values): void;

    /**
     * Cache tags for the pages a placement row appears on: its parents up to the article and page (or news entry).
     *
     * @return list<string>
     */
    public function parentTags(string $table, array $row): array;

    /**
     * v0.7.1. Cache tags for an image file as a whole: its own tag and, for every row that references it (any table, any column, galleries
     * included), that row's tag and its parents' (CacheTags::forImage).
     *
     * @return list<string>
     */
    public function referenceTags(string $uuidHex, int $fileId): array;
}
