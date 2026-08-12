<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationRole: string
{
    case Admin = 'admin';
    case Instructor = 'instructor';
}
