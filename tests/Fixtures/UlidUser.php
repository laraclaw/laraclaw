<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User;

/**
 * Stands in for an application whose user model is keyed by a ULID.
 */
class UlidUser extends User
{
    use HasUlids;

    protected $guarded = [];
}
