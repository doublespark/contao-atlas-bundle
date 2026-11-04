<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;

/**
 * v0.6: stores the image Atlas sends with a news entry. The file is written under <upload path>/atlas-news/ (normally files/atlas-news/), named
 * after the entry's atlas_ref and the file name sent, then registered in Contao's file database (tl_files) through Contao's own Dbafs::addResource,
 * which adds the missing parent folders and returns the file's uuid. The writer puts that uuid in tl_news.singleSRC.
 *
 * Re-sending the same file name overwrites the file and Dbafs updates its hash, so a re-send never piles up copies. A different file name is a new
 * file; the old one is left in the file manager (it may be used elsewhere by then), never deleted by the API.
 *
 * v0.9: the image may name its `folder`, a path inside the upload path that the site already uses (Doublespark: files/blog-news/2026/news/banner).
 * Atlas resolves it from the client's own setting; the bundle only checks it (folderProblem: inside the upload path, no `..`, no absolute path, no
 * backslash, letters, digits, hyphens, underscores and dots only), creates what is missing and registers it through Dbafs as before. With a folder
 * the file is named after the file name sent alone (the post's slug), with no atlas_ref in front. A file of that name that is NOT the entry's own
 * image (its tl_files uuid is not the entry's singleSRC) is never overwritten: the next free `<slug>-2`, `<slug>-3`... is used instead. The entry's
 * own file is overwritten, as before. With no folder sent, everything is exactly as v0.6, so an older Atlas keeps working.
 *
 * Only JPEG, PNG and WebP, at most 5 MB decoded, and the type is read from the bytes, not trusted from the file name.
 */
class NewsImageStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const FOLDER = 'atlas-news';
    /** The most `-N` names tried in one folder before giving up. */
    private const MAX_SUFFIX = 500;
    private const TYPES = [\IMAGETYPE_JPEG => 'jpg', \IMAGETYPE_PNG => 'png', \IMAGETYPE_WEBP => 'webp'];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * Why these bytes cannot be stored, or null. Checked before anything is written.
     *
     * @param array{filename:string,data_base64:string,alt:string,folder?:string} $image
     */
    public function problem(array $image): ?string
    {
        $bytes = base64_decode($image['data_base64'], true);
        if (false === $bytes || '' === $bytes) {
            return 'image.data_base64 is not valid base64. Nothing was saved.';
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            return 'image is larger than 5 MB. Nothing was saved.';
        }
        if (null === $this->extension($bytes)) {
            return 'image must be a JPEG, PNG or WebP file. Nothing was saved.';
        }

        return null;
    }

    /**
     * v0.9. Why `folder` cannot be used, or null. A string of 255 characters at most, relative, forward slashes only, every part made of letters,
     * digits, hyphens, underscores and dots (never `.` or `..` on its own), and inside the upload path (it starts with it, e.g. files/...).
     */
    public function folderProblem(mixed $folder): ?string
    {
        $upload = $this->uploadFolder();
        $rule = 'image.folder must be a folder inside '.$upload.'/, for example '.$upload.'/blog-news/2026/news/banner: forward slashes, letters, digits, hyphens,'
            .' underscores and dots only, no ".." and no absolute path. Nothing was saved.';
        if (!\is_string($folder) || '' === $folder || \strlen($folder) > 255) {
            return $rule;
        }
        if (str_contains($folder, '\\') || str_starts_with($folder, '/') || preg_match('/^[A-Za-z]:/', $folder)) {
            return $rule;
        }
        $parts = explode('/', rtrim($folder, '/'));
        foreach ($parts as $part) {
            if ('' === $part || '.' === $part || '..' === $part || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                return $rule;
            }
        }
        $uploadParts = explode('/', $upload);
        if (\count($parts) <= \count($uploadParts) || \array_slice($parts, 0, \count($uploadParts)) !== $uploadParts) {
            return $rule;
        }

        return null;
    }

    /**
     * Writes the file and registers it. Only called after problem() (and folderProblem() for a folder) has returned null.
     *
     * @param array{filename:string,data_base64:string,alt:string,folder?:string} $image
     * @param string|null $currentUuid the entry's singleSRC as stored (16 raw bytes), null on create: the one file a folder write may overwrite
     * @return array{uuid:string,path:string} uuid as Contao stores it (16 raw bytes); path relative to the project, e.g. files/atlas-news/x.jpg
     */
    public function store(string $atlasRef, array $image, ?string $currentUuid = null): array
    {
        $bytes = (string) base64_decode($image['data_base64'], true);
        $extension = (string) $this->extension($bytes);
        $slug = $this->slug(pathinfo($image['filename'], \PATHINFO_FILENAME));
        $refSlug = $this->slug($atlasRef);

        if (isset($image['folder'])) {
            $folder = rtrim($image['folder'], '/');
            $this->makeFolder($folder);
            $path = $this->freePath($folder, rtrim(substr('' === $slug ? $refSlug : $slug, 0, 150), '-'), $extension, $currentUuid);
        } else {
            $folder = $this->uploadFolder().'/'.self::FOLDER;
            $this->makeFolder($folder);
            $path = $folder.'/'.substr($refSlug.('' === $slug ? '' : '-'.$slug), 0, 150).'.'.$extension;
        }
        if (false === file_put_contents($this->absolute($path), $bytes)) {
            throw new \RuntimeException('Could not write '.$path.'.');
        }

        return ['uuid' => $this->register($path), 'path' => $path];
    }

    /** Adds the file (and any missing parent folders) to tl_files and answers its uuid. Separate so the tests can stand in for Contao. */
    protected function register(string $path): string
    {
        $this->framework->initialize();
        $model = \Contao\Dbafs::addResource($path);

        return (string) $model->uuid;
    }

    /** v0.9. The tl_files uuid of a path (16 raw bytes), or null when the file manager does not know it. Separate so the tests can stand in for Contao. */
    protected function uuidOf(string $path): ?string
    {
        $this->framework->initialize();
        $model = \Contao\FilesModel::findByPath($path);

        return null === $model ? null : (string) $model->uuid;
    }

    /**
     * v0.9. `<name>.<ext>` in the folder unless a file of that name is there and is not the entry's own image, then `<name>-2.<ext>`, `<name>-3.<ext>`...
     * A file on disk that tl_files does not know is someone's too, so it is never overwritten either.
     */
    private function freePath(string $folder, string $name, string $extension, ?string $currentUuid): string
    {
        for ($n = 1; $n <= self::MAX_SUFFIX; ++$n) {
            $path = $folder.'/'.$name.(1 === $n ? '' : '-'.$n).'.'.$extension;
            if (!file_exists($this->absolute($path))) {
                return $path;
            }
            if (null !== $currentUuid && '' !== $currentUuid && $this->uuidOf($path) === $currentUuid) {
                return $path; // the entry's own file: a re-send overwrites it
            }
        }

        throw new \RuntimeException('Every name from '.$name.'.'.$extension.' to '.$name.'-'.self::MAX_SUFFIX.'.'.$extension.' is taken in '.$folder.'. Nothing was saved.');
    }

    private function makeFolder(string $folder): void
    {
        $absolute = $this->absolute($folder);
        if (!is_dir($absolute) && !mkdir($absolute, 0775, true) && !is_dir($absolute)) {
            throw new \RuntimeException('Could not create the folder '.$folder.'.');
        }
    }

    private function uploadFolder(): string
    {
        return trim(str_replace('\\', '/', $this->uploadPath), '/');
    }

    private function absolute(string $path): string
    {
        return rtrim($this->projectDir, '/').'/'.$path;
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }

    private function extension(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        return false === $info ? null : (self::TYPES[$info[2]] ?? null);
    }
}
