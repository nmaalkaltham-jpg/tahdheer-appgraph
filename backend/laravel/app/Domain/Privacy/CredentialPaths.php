<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * Paths whose segments are bearer credentials — and the one way to make them safe to store.
 *
 * `/orders/{token}` is the only door a guest has to their order, their payment retry and
 * their downloads; `orders` keeps only its sha256 for that reason. A plaintext copy in any
 * table a wider audience reads (analytics, the SEO 404 report) undoes that. So every sink
 * that stores a visitor-supplied path or URL passes it through here first, and query
 * strings are dropped wholesale: `/account/orders?token=…` carries the same credential.
 *
 * Mirrored in apps/storefront/lib/private-paths.ts, which stops the browser from sending it;
 * this class is the authoritative boundary because the browser cannot be trusted to.
 */
final class CredentialPaths
{
    public const PLACEHOLDER = '[token]';

    /**
     * A segment that follows `orders` anywhere (locale prefixes, checkout retry), after one or
     * more separators — `//` and `\\` included, so a crafted link is caught like a real one. It
     * stops at quotes, whitespace and backslashes too, so it can run over provider evidence.
     */
    private const SEGMENT = '#(^|[/\\\\])(orders)[/\\\\]+([^/?\#"\s\\\\]+)#i';

    /** The `token` query parameter of /account/orders — and only there (Tap's pay URL has its own `token=`). */
    private const QUERY = '#/account/orders/?\?(?:[^\#"\s]*&)?token=([^&\#"\s]+)#i';

    /**
     * The token's own shape: 43 base64url characters mixing upper and lower case (HMAC-SHA256).
     * Catches crafted spellings the segment rule cannot (`/orders;x/…`, `/orders/./…`); slugs are
     * lowercase, so none matches. Used on paths and free text — never on provider evidence.
     */
    private const SHAPE = '/(?<![A-Za-z0-9_-])(?=[A-Za-z0-9_-]*[A-Z])(?=[A-Za-z0-9_-]*[a-z])[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/';

    public static function redactPath(string $path): string
    {
        $path = self::withoutQuery($path);

        /*
         * `/orders%2F<token>` 404s, but the literal still holds the token. Matching the
         * decoded form catches it; an ordinary path is returned exactly as received.
         */
        $decoded = self::decoded($path);

        return preg_match(self::SEGMENT, $decoded) === 1 || preg_match(self::SHAPE, $decoded) === 1
            ? self::byShape(self::bySegment($decoded))
            : $path;
    }

    /** Origin plus redacted path: no user-info, no query, no fragment. Null when not an http(s) URL or path. */
    public static function redactUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return self::redactPath($url);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        $origin = $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.self::redactPath((string) ($parts['path'] ?? ''));
    }

    /**
     * Every string at any depth with its credential segments replaced in place — query and
     * the rest of the string kept. For structured evidence (provider payloads), not paths.
     */
    public static function redactDeep(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::redactDeep(...), $value),
            is_string($value) => self::redactText($value, byShape: false),
            default => $value,
        };
    }

    /** @return list<string> the raw credentials anywhere in a decoded structure, one string at a time. */
    public static function tokensInDeep(mixed $value): array
    {
        return match (true) {
            is_array($value) => array_values(array_unique(array_merge([], ...array_map(self::tokensInDeep(...), array_values($value))))),
            is_string($value) => self::structuralTokens($value),
            default => [],
        };
    }

    /**
     * The raw credentials a stored value exposes — used to account for, and rotate, what already leaked.
     *
     * @return list<string>
     */
    public static function tokensIn(string $value): array
    {
        $found = self::structuralTokens($value);
        foreach (array_unique([$value, self::decoded($value)]) as $form) {
            preg_match_all(self::SHAPE, $form, $shapes);
            array_push($found, ...$shapes[0]);
        }

        return array_values(array_unique($found));
    }

    /**
     * In place when the link is plain; when it is only visible percent-encoded or JSON-escaped
     * (a return URL inside a query, an embedded JSON string), the decoded form is stored instead.
     * A string without a credential is returned byte-identical.
     */
    public static function redactText(string $text, bool $byShape = true): string
    {
        $redacted = self::bySegment($text);

        if (self::structuralTokens($redacted) !== []) {
            $redacted = self::bySegment(self::decoded($redacted));
        }

        return $byShape ? self::byShape($redacted) : $redacted;
    }

    /** @return list<string> tokens found by where they sit (`orders/…`, `?token=`), not by shape */
    private static function structuralTokens(string $value): array
    {
        $found = [];
        foreach (array_unique([$value, self::decoded($value)]) as $form) {
            preg_match_all(self::SEGMENT, $form, $segments);
            preg_match_all(self::QUERY, $form, $query);
            array_push($found, ...$segments[3], ...$query[1]);
        }

        return array_values(array_unique(array_filter(
            $found,
            static fn (string $token): bool => $token !== self::PLACEHOLDER,
        )));
    }

    private static function bySegment(string $value): string
    {
        return (string) preg_replace(self::SEGMENT, '$1$2/'.self::PLACEHOLDER, $value);
    }

    private static function byShape(string $value): string
    {
        return (string) preg_replace(self::SHAPE, self::PLACEHOLDER, $value);
    }

    /** Decoded until stable (`%252F` twice), JSON-escaped slashes included; bounded. */
    private static function decoded(string $value): string
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $next = rawurldecode(str_replace('\\/', '/', $value));
            if ($next === $value) {
                break;
            }
            $value = $next;
        }

        return $value;
    }

    private static function withoutQuery(string $path): string
    {
        return (string) preg_replace('/[?#].*$/s', '', trim($path));
    }
}
