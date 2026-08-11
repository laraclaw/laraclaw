<?php

namespace Laraclaw\Models;

use Illuminate\Database\Eloquent\Model;
use Laraclaw\Connectors\Connector;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Override;

/**
 * Eloquent model representing a persistent conversation thread record for any connector.
 */
class Thread extends Model
{
    protected $table = 'laraclaw_threads';

    protected $fillable = ['connector', 'key', 'conversation_id', 'is_direct_message', 'persona', 'pending_approvals'];

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
        if ($this->connector === ConnectorType::Api) {
            return request()->user();
        }

        if ($this->is_direct_message) {
            return Account::with('user')
                ->forConnector($this->key, $this->connector)
                ->firstOrFail()
                ->user;
        }

        $userModel = config('laraclaw.auth.user_model');

        return $userModel::find(config('laraclaw.auth.admin_user_id'));
    }

    /**
     * Return true when the agent paused on this thread and is waiting for the user to approve a tool call.
     */
    public function isAwaitingApproval(): bool
    {
        return filled($this->pending_approvals);
    }

    /**
     * Cast the connector enum, the direct message flag, and the pending approvals payload.
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'connector' => ConnectorType::class,
            'is_direct_message' => 'boolean',
            'pending_approvals' => 'array',
        ];
    }
}
