<?php

declare(strict_types=1);

namespace App\Enums;

/** Also used for lock_mode and the per-problem overrides (D-16). */
enum PublishMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
