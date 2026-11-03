<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Doctrine\DBAL\Connection;

/**
 * v0.7. The real ImageMetaGateway. The file's meta is saved through FilesModel (Contao's own model, so tstamp-style bookkeeping and the model
 * registry stay right) and never by raw SQL. A placement row has no model that fits every table a site adds its own image columns to
 * (Doublespark: dsImg_*, tl_ds_portfolio), so it is updated through DBAL with the three columns the writer passes and no others. Reads are SELECTs.
 * v0.7.1: the cache tags come from ImageInventory's placement scan (the same rows the inventory reports) through CacheTags.
 * Not run against a live Contao here: the Atlas dry run and verification on the site are the proof.
 */
class ContaoImageMetaGateway implements ImageMetaGateway
{
    /** @var array<string, array<string, string>>|null */
    private ?array $schema = null;

    public function __construct(private readonly Connection $db, private readonly ContaoFramework $framework, private readonly ImageInventory $inventory)
    {
    }

    public function fallbackLanguage(): ?string
    {
        foreach ($this->db->fetchAllAssociative("SELECT language, fallback FROM tl_page WHERE type = 'root' ORDER BY sorting, id") as $root) {
            $fallback = $root['fallback'] ?? null;
            if (null !== $fallback && '' !== $fallback && '0' !== (string) $fallback && '' !== (string) $root['language']) {
                return (string) $root['language'];
            }
        }

        return null;
    }

    public function file(string $uuidHex): ?array
    {
        $row = $this->db->fetchAssociative("SELECT id, meta FROM tl_files WHERE uuid = UNHEX(?) AND type = 'file'", [$uuidHex]);

        return false === $row ? null : ['id' => (int) $row['id'], 'meta' => $row['meta']];
    }

    public function saveFileMeta(string $uuidHex, string $serialised): void
    {
        $this->framework->initialize();
        $model = $this->framework->getAdapter(FilesModel::class)->findByUuid(hex2bin($uuidHex));
        if (null === $model) {
            throw new \RuntimeException('Contao has no file '.$uuidHex.'.');
        }
        $model->meta = $serialised;
        $model->tstamp = time();
        $model->save();
    }

    public function columns(string $table): array
    {
        return array_keys($this->schema()[$table] ?? []);
    }

    public function columnType(string $table, string $column): ?string
    {
        return $this->schema()[$table][$column] ?? null;
    }

    public function row(string $table, int $id): ?array
    {
        $this->assertKnown($table);
        $row = $this->db->fetchAssociative('SELECT * FROM '.$this->db->quoteIdentifier($table).' WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    public function saveRow(string $table, int $id, array $values): void
    {
        $this->assertKnown($table);
        $allowed = ['alt', 'imageTitle', 'tstamp'];
        foreach (array_keys($values) as $column) {
            if (!\in_array($column, $allowed, true)) {
                throw new \LogicException('Only alt, imageTitle and tstamp are written to a placement, not '.$column.'.');
            }
        }
        $this->db->update($this->db->quoteIdentifier($table), $values, ['id' => $id]);
    }

    public function parentTags(string $table, array $row): array
    {
        $this->assertKnown($table);

        return CacheTags::forPlacement($this->inventory->placementOfRow($table, $row));
    }

    public function referenceTags(string $uuidHex, int $fileId): array
    {
        return CacheTags::forImage($fileId, $this->inventory->placementsOfUuid($uuidHex));
    }

    /** Only a table the schema scan found is ever put into SQL. */
    private function assertKnown(string $table): void
    {
        if (!isset($this->schema()[$table])) {
            throw new \InvalidArgumentException('Unknown table '.$table.'.');
        }
    }

    /** @return array<string, array<string, string>> table => column => column type (lower case) */
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
}
