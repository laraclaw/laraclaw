<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;
use Override;

/**
 * Eloquent model linking a user to an external channel account identifier.
 */
class UserAccount extends Model
{
    protected $table = Tables::ACCOUNTS;

    protected $fillable = ['user_id', 'channel', 'account'];

    /**
     * Filter to accounts matching the given identifier and channel.
     */
    #[Scope]
    public function forChannel(Builder $query, string $account, ChannelType $channel): void
    {
        $query->where('channel', $channel->value)->where('account', $account);
    }

    /**
     * The user who owns this channel account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
        ];
    }
}
