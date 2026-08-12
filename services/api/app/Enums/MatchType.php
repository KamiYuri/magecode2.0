<?php

declare(strict_types=1);

namespace App\Enums;

/** Assigned by api from the section membership of both submissions; drives
 *  two-tier redaction (D-05/D-06). */
enum MatchType: string
{
    case WithinSection = 'WITHIN_SECTION';
    case CrossSection = 'CROSS_SECTION';
}
