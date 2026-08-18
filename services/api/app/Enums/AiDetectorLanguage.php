<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The languages an AID job may name, as `job.ai-detector.v1.schema.json`
 * spells them.
 *
 * Read from `programming_languages.monaco_language` (v3 §7, 2026-08-18): no
 * column was ever designated for AID, and `monaco_language` is the only
 * NOT NULL language slug in the table. `dolos_language` was the alternative
 * and is worse — it is nullable, so a language Dolos cannot parse would
 * silently disable AI detection for it as well.
 *
 * The model routing behind this is AID's business (E4/E5): the schema's own
 * note says a language it does not support comes back `not_applicable`, so
 * this enum only decides what may be published, not what will be analysed.
 */
enum AiDetectorLanguage: string
{
    case Python = 'python';
    case Java = 'java';
    case C = 'c';
    case Cpp = 'cpp';
}
