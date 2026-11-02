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
 * Only JPEG, PNG and WebP, at most 5 MB decoded, and the type is read from the bytes, not trusted from the file name.
 */
class NewsImageStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const FOLDER = 'atlas-news';
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
     * @param array{filename:string,data_base64:string,alt:string} $image
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
     * Writes the file and registers it. Only called after problem() has returned null.
     *
     * @param array{filename:string,data_base64:string,alt:string} $image
     * @return array{uuid:string,path:string} uuid as Contao stores it (16 raw bytes); path relative to the project, e.g. files/atlas-news/x.jpg
     */
    public function store(string $atlasRef, array $image): array
    {
        $bytes = (string) base64_decode($image['data_base64'], true);
        $base = pathinfo($image['filename'], \PATHINFO_FILENAME);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-');
        $refSlug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($atlasRef)), '-');
        $name = substr($refSlug.('' === $slug ? '' : '-'.$slug), 0, 150).'.'.$this->extension($bytes);

        $folder = trim(str_replace('\\', '/', $this->uploadPath), '/').'/'.self::FOLDER;
        $absoluteFolder = rtrim($this->projectDir, '/').'/'.$folder;
        if (!is_dir($absoluteFolder) && !mkdir($absoluteFolder, 0775, true) && !is_dir($absoluteFolder)) {
            throw new \RuntimeException('Could not create the folder '.$folder.'.');
        }
        $path = $folder.'/'.$name;
        if (false === file_put_contents(rtrim($this->projectDir, '/').'/'.$path, $bytes)) {
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

    private function extension(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        return false === $info ? null : (self::TYPES[$info[2]] ?? null);
    }
}
