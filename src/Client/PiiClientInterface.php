<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Client;

interface PiiClientInterface
{
    /**
     * Sanitize every string value inside $payload (recursively). Keys and non-string scalars are preserved.
     *
     * Returns the same type it was given (string in, string out; array in, array out).
     *
     * @param string|array<mixed> $payload
     *
     * @return string|array<mixed>
     *
     * @throws PiiClientException when the engine is unreachable, times out, or returns an error
     */
    public function sanitize(string|array $payload, ?string $strategy = null): string|array;
}
