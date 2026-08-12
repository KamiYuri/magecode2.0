<?php

declare(strict_types=1);

namespace App\Enums;

/** Section is the isolation boundary (D-04); these roles drive every scoped
 *  authorization check. */
enum SectionRole: string
{
    case Instructor = 'instructor';
    case Ta = 'ta';
    case Student = 'student';
}
