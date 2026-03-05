<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use LaraClaw\Channels\Channel;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;
use Override;

/**
 * Eloquent model representing a persistent conversation thread record for any channel.
 */
class Thread extends Model
{
    protected $table = Tables::THREADS;

    protected $fillable = ['channel', 'key', 'conversation_id', 'is_direct_message', 'persona'];

    private ?Channel $resolvedChannel = null;

    /**
     * Find or create the thread record for the given incoming message.
     */
    public static function forMessage(IncomingMessage $message): self
    {
        return static::firstOrCreate(
            ['channel' => $message->channel, 'key' => $message->key],
            ['is_direct_message' => $message->isDirectMessage],
        );
    }

    /**
     * Instantiate the outbound channel for this thread.
     */
    public function channel(): Channel
    {
        return $this->resolvedChannel ??= $this->channel->forKey($this->key);
    }

    /**
     * Return the user associated with this thread.
     *
     * For DMs, this is the registered account owner.
     * For group chats, this is always the configured admin user.
     */
    public function user(): mixed
    {
        if ($this->is_direct_message) {
            return UserAccount::with('user')
                ->forChannel($this->key, $this->channel)
                ->firstOrFail()
                ->user;
        }

        $userModel = config('laraclaw.auth.user_model');

        return $userModel::find(config('laraclaw.auth.admin_user_id'));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'is_direct_message' => 'boolean',
        ];
    }
}
