<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Controller;

use Doublespark\ContaoAtlasBundle\Service\NewsWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /atlas-api/news creates; POST or PUT /atlas-api/news/{id} updates (POST because some servers refuse PUT); POST /atlas-api/news/{id}/delete deletes
 * an entry this API created, when the body repeats its atlas_ref. Bearer token on all three; 401 without it, 404 for an id this API did not create.
 * v0.3: `elements` (a list of blocks, one text element each, in order) instead of body_html, and the optional fields featured, link_text,
 * og_title and og_description, each stored only where the site has the column; the answer reports elements_count, fields_applied and fields_unsupported.
 * 422 when the request is well formed but the site cannot do what it asks: category_ids on a site with no news categories mechanism,
 * a category id the site does not have, or an author_id that is not a back-end user. Categories are never silently dropped, or (category_mode column) a category_ids that is not exactly one whole number. Categories are never silently dropped.
 */
class NewsController
{
    private const FIELDS = ['archive_id', 'headline', 'teaser', 'body_html', 'alias', 'meta_title', 'meta_description', 'author_id', 'publish_at', 'published', 'atlas_ref', 'category_ids',
        'elements', 'featured', 'link_text', 'og_title', 'og_description'];

    /** Most blocks one entry may be sent as. Doublespark's own format has eight. */
    private const MAX_ELEMENTS = 50;

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
        $in = $this->normalised($in);

        if (null !== $existing = $this->writer->findByRef((string) $in['atlas_ref'])) {
            return new JsonResponse($existing, 200); // duplicate guard: nothing is created
        }
        if (!$this->writer->archiveExists((int) $in['archive_id'])) {
            return $this->error('archive_id does not name a news archive on this site.', 400);
        }

        if (null !== $unprocessable = $this->writer->unprocessable($in)) {
            return $this->error($unprocessable, 422);
        }

        $out = $this->writer->create($in);

        return new JsonResponse($this->report($out, $in), 201);
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
        $in = $this->normalised($in);
        if (isset($in['archive_id']) && !$this->writer->archiveExists((int) $in['archive_id'])) {
            return $this->error('archive_id does not name a news archive on this site.', 400);
        }

        if (null !== $unprocessable = $this->writer->unprocessable($in)) {
            return $this->error($unprocessable, 422);
        }

        $out = $this->writer->update($id, $in);

        return new JsonResponse($this->report($out, $in), 200);
    }

    public function delete(Request $request, int $id): Response
    {
        if (null !== $denied = $this->authenticate($request)) {
            return $denied;
        }

        try {
            $ref = $this->writer->refFor($id);
            if (null === $ref) {
                return $this->error('Not found.', 404);
            }
            $in = json_decode($request->getContent(), true);
            if (!\is_array($in) || !isset($in['atlas_ref']) || !\is_string($in['atlas_ref']) || !hash_equals($ref, $in['atlas_ref'])) {
                // The caller must name the entry twice (id and atlas_ref): a wrong or stale id can never delete someone else's post.
                return $this->error('atlas_ref must be sent and must be the one this entry was created with. Nothing was deleted.', 409);
            }
            $this->writer->delete($id);

            return new JsonResponse(['id' => $id, 'deleted' => true], 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * The writer's answer plus, when asked for: elements_count (the elements now stored, whenever `elements` was sent) and fields_applied /
     * fields_unsupported (whenever any optional field or category_ids was sent).
     */
    private function report(array $out, array $in): array
    {
        if (isset($in['elements'])) {
            $out['elements_count'] = $this->writer->elementCount((int) $out['id']);
        }
        [$applied, $unsupported] = $this->writer->optionalReport($in);
        if ([] !== $applied || [] !== $unsupported) {
            $out['fields_applied'] = $applied;
            $out['fields_unsupported'] = $unsupported;
        }

        return $out;
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
        if (array_key_exists('featured', $in) && !\is_bool($in['featured'])) {
            return 'featured must be true or false.';
        }
        foreach (['link_text', 'og_title', 'og_description'] as $text) {
            if (array_key_exists($text, $in) && (!\is_string($in[$text]) || mb_strlen($in[$text]) > 255)) {
                return "$text must be a string of 255 characters or fewer.";
            }
        }
        if (array_key_exists('elements', $in)) {
            if (array_key_exists('body_html', $in)) {
                return 'Send either elements or body_html, not both.';
            }
            if (null !== $bad = $this->elementsProblem($in['elements'])) {
                return $bad;
            }
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

    /** Every element with all three keys, so the writer never guesses. Only called after problem() has passed. */
    private function normalised(array $in): array
    {
        if (isset($in['elements'])) {
            $in['elements'] = array_map(static fn (array $e): array => [
                'section_id' => (string) ($e['section_id'] ?? ''), 'headline' => (string) ($e['headline'] ?? ''), 'html' => $e['html'],
            ], $in['elements']);
        }

        return $in;
    }

    /** Why an element list cannot be stored, or null. Each element: html (a string), section_id ('' or a CSS id), headline ('' or text). */
    private function elementsProblem(mixed $elements): ?string
    {
        if (!\is_array($elements) || !array_is_list($elements) || [] === $elements || \count($elements) > self::MAX_ELEMENTS) {
            return 'elements must be a list of 1 to '.self::MAX_ELEMENTS.' blocks.';
        }
        foreach ($elements as $n => $element) {
            $at = 'elements['.$n.']';
            if (!\is_array($element) || array_is_list($element)) {
                return "$at must be an object.";
            }
            if (!isset($element['html']) || !\is_string($element['html'])) {
                return "$at.html must be a string.";
            }
            foreach (['section_id', 'headline'] as $key) {
                if (isset($element[$key]) && !\is_string($element[$key])) {
                    return "$at.$key must be a string.";
                }
            }
            if ('' !== ($element['section_id'] ?? '') && !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $element['section_id'])) {
                return "$at.section_id must be empty or a CSS id (a letter, then letters, digits, hyphens or underscores, 64 at most).";
            }
            if (mb_strlen($element['headline'] ?? '') > 255) {
                return "$at.headline must be 255 characters or fewer.";
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
