<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Service;

/**
 * v0.7. The shape of tl_files.meta and nothing else, so it can be proved without a database: a serialised array of language => fields (alt, title,
 * link, caption, license, ...). Reading never throws and never guesses: a value that is not a serialised array is reported as unreadable, and the
 * writer then refuses rather than overwrite something it could not read. Writing changes ONLY the alt and title of ONE language and carries every
 * other field of that language, and every other language, through untouched.
 */
final class ImageMeta
{
    /**
     * @return array<string, array<string, mixed>>|null the meta, [] for an empty or missing value, null when the value is not a serialised array
     */
    public static function parse(mixed $raw): ?array
    {
        if (null === $raw || '' === $raw || false === $raw) {
            return [];
        }
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw)) {
            return null;
        }
        $meta = @unserialize($raw, ['allowed_classes' => false]);
        if (!\is_array($meta)) {
            return 'a:0:{}' === $raw ? [] : null;
        }

        return $meta;
    }

    /**
     * The alt and title this language holds now. A language the file has no meta for reads as two empty strings.
     *
     * @param array<string, array<string, mixed>> $meta
     *
     * @return array{alt:string,title:string}
     */
    public static function current(array $meta, string $language): array
    {
        $fields = \is_array($meta[$language] ?? null) ? $meta[$language] : [];

        return ['alt' => self::text($fields['alt'] ?? ''), 'title' => self::text($fields['title'] ?? '')];
    }

    /**
     * The meta with the given fields set for one language. Everything else (caption, link, license, any other key of that language, every other
     * language) is carried through as it was, in its original order. A language the file had no entry for is added at the end.
     *
     * @param array<string, array<string, mixed>> $meta
     * @param array{alt?:string,title?:string}    $set
     *
     * @return array<string, array<string, mixed>>
     */
    public static function apply(array $meta, string $language, array $set): array
    {
        $fields = \is_array($meta[$language] ?? null) ? $meta[$language] : [];
        foreach (['alt', 'title'] as $name) {
            if (\array_key_exists($name, $set)) {
                $fields[$name] = $set[$name];
            }
        }
        $meta[$language] = $fields;

        return $meta;
    }

    /** @param array<string, array<string, mixed>> $meta */
    public static function serialise(array $meta): string
    {
        return serialize($meta);
    }

    /** A stored value as text: null is the empty string, so "no meta at all" and "set but empty" compare equal to an expected empty value. */
    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
