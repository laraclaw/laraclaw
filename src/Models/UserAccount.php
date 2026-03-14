<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ConnectorType;
use Override;

/**
 * Eloquent model linking a user to an external connector account identifier.
 */
class UserAccount extends Model
{
    const string TABLE = 'laraclaw_accounts';

    protected $table = self::TABLE;

    protected $fillable = ['user_id', 'connector', 'account'];

    /**
     * Filter to accounts matching the given identifier and connector.
     */
    #[Scope]
    public function forConnector(Builder $query, string $account, ConnectorType $connector): void
    {
        $query->where('connector', $connector->value)->where('account', $account);
    }

    /**
     * The user who owns this connector account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'connector' => ConnectorType::class,
        ];
    }
}
