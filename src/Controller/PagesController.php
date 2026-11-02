<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Controller;

use Doublespark\ContaoAtlasBundle\Service\PageMetaWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.4. GET /atlas-api/pages?url=<page address or path> returns {id, alias, pageTitle, description} for the page with that alias (409 when several site
 * roots share it, 404 when none). POST or PUT /atlas-api/pages/{id} sets pageTitle and description (either or both) and answers with the new values and
 * the previous ones; POST is accepted because some servers' nginx refuses PUT (Urban Square, 405). Bearer token on both, 401 without it.
 */
class PagesController
{
    use BearerAuth;

    private const MAX_LENGTH = 255;

    public function __construct(private readonly PageMetaWriter $writer, private readonly string $tokenHash)
    {
    }

    public function show(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            $url = trim((string) $request->query->get('url', ''));
            if ('' === $url) {
                return $this->error('url is required.', 400);
            }
            $pages = $this->writer->findByUrl($url);
            if ([] === $pages) {
                return $this->error('No page on this site has that address.', 404);
            }
            if (\count($pages) > 1) {
                return $this->error('More than one page has that address (ids '.implode(', ', array_column($pages, 'id')).'), so none was chosen.', 409);
            }

            return new JsonResponse($pages[0], 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function update(Request $request, int $id): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            if (null === $this->writer->find($id)) {
                return $this->error('Not found.', 404);
            }
            $data = json_decode($request->getContent(), true);
            if (!\is_array($data) || array_is_list($data)) {
                return $this->error('Body must be a JSON object.', 400);
            }
            $fields = array_intersect_key($data, array_flip(['pageTitle', 'description']));
            if ([] === $fields) {
                return $this->error('Send pageTitle, description or both.', 400);
            }
            foreach ($fields as $name => $value) {
                if (!\is_string($value) || mb_strlen($value) > self::MAX_LENGTH) {
                    return $this->error("$name must be a string of ".self::MAX_LENGTH.' characters or fewer.', 400);
                }
            }

            return new JsonResponse($this->writer->save($id, $fields), 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /** 500 body for a request that carried the valid token: the exception class and message, never a trace. */
    private function failure(\Throwable $e): JsonResponse
    {
        return new JsonResponse(['error' => 'The page could not be read or saved.', 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 1000)], 500);
    }
}
