<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Controller;

use Doublespark\ContaoAtlasBundle\Service\ImageInventory;
use Doublespark\ContaoAtlasBundle\Service\ImageMetaRefused;
use Doublespark\ContaoAtlasBundle\Service\ImageMetaWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.5, read-only. GET /atlas-api/images?page=N&per_page=M lists the site's image files with their meta and placements. GET
 * /atlas-api/images/resolve?url[]=...&url[]=... maps rendered image addresses back to their source files. Bearer token, 401 without it.
 *
 * v0.7 adds the one write: POST /atlas-api/images/meta sets the alt and title of a file (one language) or of a placement's override, refusing with
 * 409 and the live values when the site no longer holds what the caller expects (ImageMetaWriter). The list answer says so in `capabilities`.
 */
class ImagesController
{
    use BearerAuth;

    /** Addresses per resolve call. GET keeps it read-only; 20 keeps the request line well under nginx's default 8 KB header buffer. */
    public const MAX_RESOLVE = 20;

    public function __construct(private readonly ImageInventory $inventory, private readonly string $tokenHash, private readonly ?ImageMetaWriter $writer = null)
    {
    }

    public function meta(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }
        if (null === $this->writer) {
            return $this->error('This site\'s bundle was installed without the image meta writer.', 501);
        }

        try {
            $body = json_decode((string) $request->getContent(), true);
            if (!\is_array($body) || array_is_list($body)) {
                return $this->error('Send a JSON object: uuid, language, target, alt and/or title, and expected_alt / expected_title.', 400);
            }

            return new JsonResponse($this->writer->write($body), 200);
        } catch (ImageMetaRefused $e) {
            return new JsonResponse($e->body, $e->status);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'The image meta could not be written.', 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 1000)], 500);
        }
    }

    /** v0.7.1. POST /atlas-api/images/invalidate {uuid}: clears the cached pages of one image without changing any data. */
    public function invalidate(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }
        if (null === $this->writer) {
            return $this->error('This site\'s bundle was installed without the image meta writer.', 501);
        }

        try {
            $body = json_decode((string) $request->getContent(), true);
            if (!\is_array($body) || array_is_list($body)) {
                return $this->error('Send a JSON object: {"uuid": "<32 hex characters>"}.', 400);
            }

            return new JsonResponse($this->writer->invalidate($body), 200);
        } catch (ImageMetaRefused $e) {
            return new JsonResponse($e->body, $e->status);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'The cache could not be cleared.', 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 1000)], 500);
        }
    }

    public function list(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            $page = (string) $request->query->get('page', '1');
            $perPage = (string) $request->query->get('per_page', '100');
            if (!ctype_digit($page) || !ctype_digit($perPage) || (int) $page < 1 || (int) $perPage < 1 || (int) $perPage > ImageInventory::MAX_PER_PAGE) {
                return $this->error('page must be a whole number from 1, per_page a whole number from 1 to '.ImageInventory::MAX_PER_PAGE.'.', 400);
            }

            return new JsonResponse($this->inventory->listImages((int) $page, (int) $perPage), 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function resolve(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            $urls = $request->query->all()['url'] ?? [];
            $urls = \is_string($urls) ? [$urls] : $urls;
            if (!\is_array($urls) || [] === $urls || \count($urls) > self::MAX_RESOLVE) {
                return $this->error('Send 1 to '.self::MAX_RESOLVE.' addresses as url[]=...', 400);
            }
            $results = [];
            foreach ($urls as $url) {
                if (!\is_string($url) || '' === trim($url)) {
                    return $this->error('Every url must be a non-empty string.', 400);
                }
                $results[] = $this->inventory->resolve(trim($url));
            }

            return new JsonResponse(['results' => $results], 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /** 500 body for a request that carried the valid token: the exception class and message, never a trace. */
    private function failure(\Throwable $e): JsonResponse
    {
        return new JsonResponse(['error' => 'The images could not be read.', 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 1000)], 500);
    }
}
