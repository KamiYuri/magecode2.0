<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The languages Dolos can parse, as `job.plagiarism-checker.v1.schema.json`
 * spells them.
 *
 * This is transcribed from the queue schema (SoT #1), not from
 * `database-schema.md` §10: `programming_languages.dolos_language` is a
 * free-form `varchar(30)`, so a seed row can name anything. `tryFrom()` on that
 * column is what keeps an unknown value out of a message SIM would reject at
 * the consumer — the submission parks as `not_applicable` instead.
 */
enum DolosLanguage: string
{
    case Python = 'python';
    case Java = 'java';
    case C = 'c';
    case Cpp = 'cpp';
}
