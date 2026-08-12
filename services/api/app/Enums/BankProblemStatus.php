<?php

declare(strict_types=1);

namespace App\Enums;

/** Bank approval workflow (D-25). */
enum BankProblemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
