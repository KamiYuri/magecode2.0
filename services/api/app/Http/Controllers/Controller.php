<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /** Every domain endpoint gates itself through a policy (B5 carry-over). */
    use AuthorizesRequests;
}
