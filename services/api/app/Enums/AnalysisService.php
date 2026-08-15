<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three batch analysis services (D-17: no prefix, self-explanatory names).
 *
 * The values are the queue names and the strings stored in
 * `analysis_problems.services`, so they cross into `shared/schemas` — SIM, AID
 * and VUL read them back.
 */
enum AnalysisService: string
{
    case PlagiarismChecker = 'plagiarism-checker';
    case AiDetector = 'ai-detector';
    case VulnScanner = 'vuln-scanner';

    /**
     * D-24 / technical-design §9.3: SIM and AID are checked by default, VUL is
     * not — not every course needs SAST.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::PlagiarismChecker, self::AiDetector];
    }
}
