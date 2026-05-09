<?php

namespace LaraClaw\Gateway\Anthropic;

use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Override;

/**
 * Mark the system prompt and last tool with cache_control so Anthropic caches them across requests.
 */
class CachedAnthropicGateway extends AnthropicGateway
{
    #[Override]
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        if (isset($body['system'])) {
            $body['system'] = [[
                'type' => 'text',
                'text' => $body['system'],
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        if (! empty($body['tools'])) {
            $body['tools'][array_key_last($body['tools'])]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $body;
    }
}
