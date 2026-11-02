<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The one bearer-token check every Atlas API controller uses. The using class holds the sha256 of the token as $tokenHash. */
trait BearerAuth
{
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

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
