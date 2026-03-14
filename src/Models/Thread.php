<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use LaraClaw\Connectors\Connector;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Tables;
use Override;

/**
 * Eloquent model representing a persistent conversation thread record for any connector.
 */
class Thread extends Model
{
    protected $table = Tables::THREADS;

    protected $fillable = ['connector', 'key', 'conversation_id', 'is_direct_message', 'persona'];

    private ?Connector $resolvedConnector = null;

    /**
     * Find or create the thread record for the given incoming message.
     */
    public static function forMessage(IncomingMessage $message): self
    {
        return static::firstOrCreate(
            ['connector' => $message->connector, 'key' => $message->key],
            ['is_direct_message' => $message->isDirectMessage],
        );
    }

    /**
     * Instantiate the outbound connector for this thread.
     */
    public function connector(): Connector
    {
        return $this->resolvedConnector ??= $this->connector->forKey($this->key);
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
                ->forConnector($this->key, $this->connector)
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
            'connector' => ConnectorType::class,
            'is_direct_message' => 'boolean',
        ];
    }
}
