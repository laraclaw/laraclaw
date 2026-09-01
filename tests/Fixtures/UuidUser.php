<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;

/**
 * Stands in for an application whose user model is keyed by a UUID and lives in
 * a table Laravel would never guess from the user_id column name.
 */
class UuidUser extends User
{
    use HasUuids;

    protected $table = 'members';

    protected $primaryKey = 'uuid';

    protected $guarded = [];
}
