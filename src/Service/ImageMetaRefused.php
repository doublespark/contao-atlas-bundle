<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

/** v0.7. A write that was refused, with the HTTP status and the JSON body to answer. Nothing was changed when this is thrown. */
final class ImageMetaRefused extends \RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        parent::__construct((string) ($body['error'] ?? 'Refused.'));
    }
}
