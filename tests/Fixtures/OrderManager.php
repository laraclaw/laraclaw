<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\Tools\BaseTool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The shape a consumer writes in their own app and names in config.
 */
class OrderManager extends BaseTool
{
    protected array $requiresApproval = [
        'refund' => 'Refund order {order_id}?',
    ];

    public function description(): Stringable|string
    {
        return 'Look up and refund customer orders. Operations: find, refund.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('Operation: find or refund'),
            'order_id' => $schema->string()->required()->description('The order ID'),
        ];
    }

    protected function operations(): array
    {
        return ['find', 'refund'];
    }

    protected function find(Request $request): string
    {
        return "Order {$request['order_id']}: shipped";
    }

    protected function refund(Request $request): string
    {
        return "Order {$request['order_id']} refunded.";
    }
}
