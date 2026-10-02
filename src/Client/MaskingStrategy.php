<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Client;

/**
 * How the engine replaces detected PII. The values match the engine's "strategy" field.
 */
enum MaskingStrategy: string
{
    /** Categorical tag, e.g. "[PRIVATE_PERSON]". */
    case Tag = 'tag';

    /** Short salted hash, e.g. "[PRIVATE_PERSON_a8f1]": the same value always gets the same tag. */
    case Hash = 'hash';

    /** Same-length mask, e.g. "********". */
    case Asterisk = 'asterisk';
}
