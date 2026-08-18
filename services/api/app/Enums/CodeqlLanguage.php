<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The languages a VUL job may name, as `job.vuln-scanner.v1.schema.json`
 * spells them.
 *
 * One member shorter than AID's list: CodeQL analyses C through its `cpp`
 * pack, so there is no `c`. The mapping is data, not code —
 * `programming_languages.codeql_language` already reads `cpp` for the C row —
 * and a null there means CodeQL has no analyser at all, which the roadmap
 * makes an explicit gate: api does not publish and the submission parks as
 * `not_applicable`.
 */
enum CodeqlLanguage: string
{
    case Python = 'python';
    case Java = 'java';
    case Cpp = 'cpp';
}
