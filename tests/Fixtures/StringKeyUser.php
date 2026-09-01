<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Stands in for an application whose user model has a string key that is neither
 * a UUID nor a ULID, such as a username or an external identifier.
 */
class StringKeyUser extends User
{
    public $incrementing = false;

    protected $table = 'accounts_people';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    protected $guarded = [];
}
