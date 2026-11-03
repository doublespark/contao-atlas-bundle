<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

/**
 * v0.7. Writes ONE image's alt and title to the site, for Image Optimisation Phase 3. Two targets:
 *
 *  - "file": tl_files.meta for one language, through Contao's FilesModel (ContaoImageMetaGateway). Only the alt and title of that language change.
 *    The caption, link, license and every other language are carried through as they were (ImageMeta::apply).
 *  - a placement {table, id, column}: the content element's own override, its `alt` and `imageTitle` columns, and only while the element has
 *    overwriteMeta set (otherwise the page shows the file's meta and the override is not in use, so writing it would change nothing visible).
 *
 * NOTHING IS OVERWRITTEN THAT CHANGED SINCE THE INVENTORY. The caller sends the values it believes are live (expected_alt, expected_title for each
 * field it sends). If the live value differs, the answer is 409 with the live values and nothing is written. An unreadable meta value is refused
 * too, never replaced.
 *
 * After a save the HTTP cache is invalidated by tag, the way Contao does on a backend save: contao.db.tl_files.<id> for a file, and
 * contao.db.<table>.<id> plus the parents' tags (article, page, news entry) for a placement, through contao.cache.tag_manager where the site has
 * one (Contao 4.13 sites may not, and the answer then says cache_cleared false). A system log entry naming Atlas is written to Contao's log.
 */
class ImageMetaWriter
{
    public const MAX_LENGTH = 255;

    /** The placement columns the override lives in. alt and imageTitle are core columns of tl_content and are the ones the inventory reads. */
    public const PLACEMENT_FIELDS = ['alt' => 'alt', 'title' => 'imageTitle'];

    public function __construct(
        private readonly ImageMetaGateway $gateway,
        private readonly ?object $tagManager,
        private readonly ?object $logger,
        private readonly ?\Closure $now = null,
    ) {
    }

    /**
     * @param array<string, mixed> $request uuid, language, target, alt and/or title, expected_alt / expected_title for each one sent
     *
     * @return array<string, mixed>
     *
     * @throws ImageMetaRefused
     */
    public function write(array $request): array
    {
        $uuid = $this->uuid($request['uuid'] ?? null);
        $set = $this->fields($request);
        $target = $request['target'] ?? null;

        if ('file' === $target) {
            return $this->writeFile($uuid, $this->language($request['language'] ?? null), $set, $request);
        }
        if (\is_array($target)) {
            return $this->writePlacement($uuid, $target, $set, $request);
        }

        throw new ImageMetaRefused(400, ['error' => 'target must be "file" or a placement {table, id, column}.']);
    }

    /** @return array<string, mixed> */
    private function writeFile(string $uuid, string $language, array $set, array $request): array
    {
        $file = $this->gateway->file($uuid);
        if (null === $file) {
            throw new ImageMetaRefused(404, ['error' => 'Contao has no image file with that uuid.', 'uuid' => $uuid]);
        }
        $meta = ImageMeta::parse($file['meta']);
        if (null === $meta) {
            throw new ImageMetaRefused(422, ['error' => 'The file\'s meta could not be read as a serialised list, so it was not changed.', 'uuid' => $uuid]);
        }

        $before = ImageMeta::current($meta, $language);
        $this->expect($before, $set, $request, ['language' => $language]);

        $after = $before;
        foreach ($set as $name => $value) {
            $after[$name] = $value;
        }
        $changed = $after !== $before;
        if ($changed) {
            $this->gateway->saveFileMeta($uuid, ImageMeta::serialise(ImageMeta::apply($meta, $language, $set)));
        }
        $tags = $changed ? ['contao.db.tl_files.'.$file['id']] : [];
        $cleared = $this->invalidate($tags);
        $logged = $changed && $this->log(sprintf('Atlas set the alt and title of image file ID %d (%s): %s', $file['id'], $language, $this->describe($before, $after)));

        return ['target' => 'file', 'uuid' => $uuid, 'language' => $language, 'before' => $before, 'after' => $after, 'changed' => $changed,
            'cache_tags' => $tags, 'cache_cleared' => $cleared, 'logged' => $logged];
    }

    /** @return array<string, mixed> */
    private function writePlacement(string $uuid, array $target, array $set, array $request): array
    {
        $table = $target['table'] ?? null;
        $id = $target['id'] ?? null;
        $column = $target['column'] ?? null;
        if (!\is_string($table) || !preg_match('/^tl_[a-z0-9_]+$/i', $table) || !\is_int($id) || $id < 1 || !\is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new ImageMetaRefused(400, ['error' => 'A placement target is {"table":"tl_content","id":12,"column":"singleSRC"}: a tl_ table, a row id and the image column.']);
        }
        $columns = $this->gateway->columns($table);
        foreach (['alt', 'imageTitle', 'overwriteMeta'] as $needed) {
            if (!\in_array($needed, $columns, true)) {
                throw new ImageMetaRefused(422, ['error' => "$table has no $needed column, so it carries no alt and title override.", 'table' => $table]);
            }
        }
        if (!\in_array($column, $columns, true)) {
            throw new ImageMetaRefused(422, ['error' => "$table has no $column column.", 'table' => $table]);
        }
        $type = (string) $this->gateway->columnType($table, $column);
        if (!str_starts_with($type, 'binary')) {
            // a gallery (multiSRC and the like): one alt and title cannot belong to one picture of the list
            throw new ImageMetaRefused(422, ['error' => "$table.$column holds a list of files, not one image, so it has no per-image override.", 'table' => $table, 'column' => $column]);
        }
        $row = $this->gateway->row($table, $id);
        if (null === $row) {
            throw new ImageMetaRefused(404, ['error' => "$table has no row $id.", 'table' => $table, 'id' => $id]);
        }
        if (!self::sameUuid($row[$column] ?? null, $uuid)) {
            throw new ImageMetaRefused(422, ['error' => "$table row $id does not place that image in $column, so nothing was changed.", 'table' => $table, 'id' => $id]);
        }
        $overwrite = $row['overwriteMeta'] ?? null;
        if (null === $overwrite || '' === $overwrite || '0' === (string) $overwrite || false === $overwrite) {
            throw new ImageMetaRefused(422, ['error' => "$table row $id does not override the file's alt and title (overwriteMeta is off), so nothing was changed.", 'table' => $table, 'id' => $id]);
        }

        $before = ['alt' => ImageMeta::text($row['alt'] ?? ''), 'title' => ImageMeta::text($row['imageTitle'] ?? '')];
        $this->expect($before, $set, $request, ['table' => $table, 'id' => $id]);

        $after = $before;
        foreach ($set as $name => $value) {
            $after[$name] = $value;
        }
        $changed = $after !== $before;
        $tags = [];
        if ($changed) {
            $values = [];
            foreach ($set as $name => $value) {
                $values[self::PLACEMENT_FIELDS[$name]] = $value;
            }
            if (\in_array('tstamp', $columns, true)) {
                $values['tstamp'] = ($this->now ?? static fn (): int => time())();
            }
            $this->gateway->saveRow($table, $id, $values);
            $tags = array_values(array_unique(array_merge(["contao.db.$table.$id"], $this->gateway->parentTags($table, $row))));
        }
        $cleared = $this->invalidate($tags);
        $logged = $changed && $this->log(sprintf('Atlas set the alt and title override of %s ID %d: %s', $table, $id, $this->describe($before, $after)));

        return ['target' => 'placement', 'uuid' => $uuid, 'table' => $table, 'id' => $id, 'column' => $column, 'fields' => self::PLACEMENT_FIELDS,
            'before' => $before, 'after' => $after, 'changed' => $changed, 'cache_tags' => $tags, 'cache_cleared' => $cleared, 'logged' => $logged];
    }

    /**
     * The 409: every field the caller is about to change must still hold the value it expects. Compared as exact text, null and '' being the same.
     *
     * @param array{alt:string,title:string} $live
     * @param array<string, string>          $set
     * @param array<string, mixed>           $where
     */
    private function expect(array $live, array $set, array $request, array $where): void
    {
        $mismatch = [];
        foreach ($set as $name => $_value) {
            $key = 'expected_'.$name;
            if (!\array_key_exists($key, $request) || !(\is_string($request[$key]) || null === $request[$key])) {
                throw new ImageMetaRefused(400, ['error' => "Send $key (a string, \"\" when the field is empty) with $name: the value Atlas believes is live."]);
            }
            if (ImageMeta::text($request[$key]) !== $live[$name]) {
                $mismatch[$name] = ['expected' => ImageMeta::text($request[$key]), 'live' => $live[$name]];
            }
        }
        if ([] !== $mismatch) {
            throw new ImageMetaRefused(409, ['error' => 'The site has changed since the inventory, so nothing was written.', 'live' => $live, 'mismatch' => $mismatch] + $where);
        }
    }

    /** @return array<string, string> only the fields sent */
    private function fields(array $request): array
    {
        $set = [];
        foreach (['alt', 'title'] as $name) {
            if (!\array_key_exists($name, $request)) {
                continue;
            }
            $value = $request[$name];
            if (!\is_string($value) || mb_strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                throw new ImageMetaRefused(400, ['error' => "$name must be text of ".self::MAX_LENGTH.' characters at most, with no control characters.']);
            }
            $set[$name] = $value;
        }
        if ([] === $set) {
            throw new ImageMetaRefused(400, ['error' => 'Send alt and/or title.']);
        }

        return $set;
    }

    private function uuid(mixed $value): string
    {
        $hex = \is_string($value) ? strtolower(str_replace('-', '', $value)) : '';
        if (!preg_match('/^[0-9a-f]{32}$/', $hex)) {
            throw new ImageMetaRefused(400, ['error' => 'uuid must be the file\'s uuid as 32 hex characters.']);
        }

        return $hex;
    }

    private function language(mixed $value): string
    {
        if (null === $value || '' === $value) {
            $value = $this->gateway->fallbackLanguage();
            if (null === $value || '' === $value) {
                throw new ImageMetaRefused(422, ['error' => 'No language was sent and the site has no fallback root page to take one from.']);
            }
        }
        if (!\is_string($value) || !preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $value)) {
            throw new ImageMetaRefused(400, ['error' => 'language must be a language code such as en.']);
        }

        return $value;
    }

    /** True when a placement column value (raw 16 bytes, or hex) is this uuid. */
    private static function sameUuid(mixed $stored, string $uuidHex): bool
    {
        if (!\is_string($stored)) {
            return false;
        }

        return 16 === \strlen($stored) ? bin2hex($stored) === $uuidHex : strtolower(str_replace('-', '', $stored)) === $uuidHex;
    }

    /** @param list<string> $tags */
    private function invalidate(array $tags): bool
    {
        if ([] === $tags || null === $this->tagManager || !method_exists($this->tagManager, 'invalidateTags')) {
            return false;
        }
        $this->tagManager->invalidateTags($tags);

        return true;
    }

    private function log(string $text): bool
    {
        if (null === $this->logger || !method_exists($this->logger, 'info')) {
            return false;
        }
        $context = [];
        if (class_exists(\Contao\CoreBundle\Monolog\ContaoContext::class)) {
            $context['contao'] = new \Contao\CoreBundle\Monolog\ContaoContext(__METHOD__, \Contao\CoreBundle\Monolog\ContaoContext::GENERAL);
        }
        $this->logger->info($text, $context);

        return true;
    }

    /** @param array{alt:string,title:string} $before @param array{alt:string,title:string} $after */
    private function describe(array $before, array $after): string
    {
        return sprintf('alt "%s" -> "%s", title "%s" -> "%s"', $before['alt'], $after['alt'], $before['title'], $after['title']);
    }
}
