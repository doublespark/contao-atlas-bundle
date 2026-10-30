<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Controller;

use Doublespark\ContaoAtlasBundle\Service\NewsWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /atlas-api/news creates; POST or PUT /atlas-api/news/{id} updates (POST because some servers refuse PUT). Bearer token on both; 401 without it, 404 for an id this API did not create.
 * 422 when the request is well formed but the site cannot do what it asks: category_ids on a site with no news categories mechanism,
 * a category id the site does not have, or an author_id that is not a back-end user. Categories are never silently dropped, or (category_mode column) a category_ids that is not exactly one whole number. Categories are never silently dropped.
 */
class NewsController
{
    private const FIELDS = ['archive_id', 'headline', 'teaser', 'body_html', 'alias', 'meta_title', 'meta_description', 'author_id', 'publish_at', 'published', 'atlas_ref', 'category_ids'];

    public function __construct(private readonly NewsWriter $writer, private readonly string $tokenHash)
    {
    }

    public function create(Request $request): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            return $this->doCreate($request);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private function doCreate(Request $request): Response
    {
        if (!\is_array($in = $this->input($request))) {
            return $this->error('Body must be a JSON object.', 400);
        }

        $problem = $this->problem($in, true);
        if (null !== $problem) {
            return $this->error($problem, 400);
        }

        if (null !== $existing = $this->writer->findByRef((string) $in['atlas_ref'])) {
            return new JsonResponse($existing, 200); // duplicate guard: nothing is created
        }
        if (!$this->writer->archiveExists((int) $in['archive_id'])) {
            return $this->error('archive_id does not name a news archive on this site.', 400);
        }

        if (null !== $unprocessable = $this->writer->unprocessable($in)) {
            return $this->error($unprocessable, 422);
        }

        return new JsonResponse($this->writer->create($in), 201);
    }

    public function update(Request $request, int $id): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            return $this->doUpdate($request, $id);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private function doUpdate(Request $request, int $id): Response
    {
        if (!$this->writer->created($id)) {
            return $this->error('Not found.', 404);
        }
        if (!\is_array($in = $this->input($request))) {
            return $this->error('Body must be a JSON object.', 400);
        }

        unset($in['atlas_ref']); // the reference is fixed when the entry is created
        $problem = $this->problem($in, false);
        if (null !== $problem) {
            return $this->error($problem, 400);
        }
        if (isset($in['archive_id']) && !$this->writer->archiveExists((int) $in['archive_id'])) {
            return $this->error('archive_id does not name a news archive on this site.', 400);
        }

        if (null !== $unprocessable = $this->writer->unprocessable($in)) {
            return $this->error($unprocessable, 422);
        }

        return new JsonResponse($this->writer->update($id, $in), 200);
    }

    /** A response when the caller is not allowed in, else null. Constant-time comparison of the token's sha256. */
    private function authenticate(Request $request): ?Response
    {
        $header = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if ('' === $this->tokenHash || '' === $token || !hash_equals($this->tokenHash, hash('sha256', $token))) {
            return $this->error('Unauthorised.', 401);
        }

        return null;
    }

    private function input(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) && !array_is_list($data) ? array_intersect_key($data, array_flip(self::FIELDS)) : (\is_array($data) && [] === $data ? [] : null);
    }

    private function problem(array $in, bool $creating): ?string
    {
        if ($creating) {
            foreach (['archive_id', 'headline', 'atlas_ref'] as $required) {
                if (!isset($in[$required]) || '' === trim((string) $in[$required])) {
                    return "$required is required.";
                }
            }
        }
        foreach (['archive_id', 'author_id'] as $int) {
            if (isset($in[$int]) && (!\is_int($in[$int]) || $in[$int] < 1)) {
                return "$int must be a positive whole number.";
            }
        }
        if (array_key_exists('category_ids', $in)) {
            $ids = $in['category_ids'];
            if (!\is_array($ids) || !array_is_list($ids) || \count($ids) > 200) {
                return 'category_ids must be a list of positive whole numbers (200 at most).';
            }
            foreach ($ids as $categoryId) {
                if (!\is_int($categoryId) || $categoryId < 1) {
                    return 'category_ids must be a list of positive whole numbers (200 at most).';
                }
            }
        }
        foreach (['headline', 'teaser', 'body_html', 'alias', 'meta_title', 'meta_description', 'atlas_ref', 'publish_at'] as $text) {
            if (isset($in[$text]) && !\is_string($in[$text])) {
                return "$text must be a string.";
            }
        }
        if (isset($in['atlas_ref']) && \strlen($in['atlas_ref']) > 190) {
            return 'atlas_ref must be 190 characters or fewer.';
        }
        if (array_key_exists('published', $in) && !\is_bool($in['published'])) {
            return 'published must be true or false.';
        }
        if (isset($in['publish_at'])) {
            try {
                $this->writer->whenFrom($in['publish_at']);
            } catch (\Exception) {
                return 'publish_at is not a date and time. Use YYYY-MM-DD HH:MM (Europe/London) or an ISO 8601 value.';
            }
        }

        return null;
    }

    /**
     * 500 body for a request that carried the valid token (the only caller that reaches here): the exception class and
     * message so the cause is visible when prod mode would hide it. Never a trace, never configuration. An unauthenticated
     * request is refused with 401 before any of this runs.
     */
    private function failure(\Throwable $e): JsonResponse
    {
        return new JsonResponse([
            'error' => 'The entry could not be saved.',
            'exception' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 1000),
        ], 500);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
