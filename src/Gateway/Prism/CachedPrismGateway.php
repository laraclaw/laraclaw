<?php

namespace LaraClaw\Gateway\Prism;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Prism\PrismCitations;
use Laravel\Ai\Gateway\Prism\PrismException;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Gateway\Prism\PrismTool;
use Laravel\Ai\Gateway\Prism\PrismUsage;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Override;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException as PrismVendorException;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * Extends PrismGateway to enable Anthropic prompt caching.
 *
 * Anthropic charges full input token price for every request, including
 * tool definitions and system prompts that never change between calls.
 * By marking these blocks with cache_control, Anthropic caches them
 * server side and subsequent requests pay only 10% of the input price
 * for cache reads instead of the full rate.
 *
 * The cache breakpoint is placed on the last tool and the system prompt
 * because Anthropic caches everything up to and including the block
 * that carries the cache_control marker.
 */
class CachedPrismGateway extends PrismGateway
{
    /**
     * Wrap generateText to convert the system prompt into a SystemMessage
     * with cache_control so Anthropic caches it across requests.
     *
     * Only applied for the Anthropic provider. Other providers pass through
     * to the parent unchanged.
     */
    #[Override]
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        if (! $provider instanceof AnthropicProvider || in_array($instructions, [null, '', '0'], true)) {
            return parent::generateText($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        }

        // Build the request without passing instructions to the parent,
        // so we can attach cache_control to the system prompt ourselves.
        [$request, $structured] = [
            $this->createPrismTextRequest($provider, $model, $schema, $options, $timeout),
            $schema !== null && $schema !== [],
        ];

        $systemMessage = new SystemMessage($instructions)
            ->withProviderOptions(['cacheType' => AnthropicCacheType::Ephemeral]);

        $request->withSystemPrompt($systemMessage);

        if (count($tools) > 0) {
            $this->addTools($request, $tools, $options);
            $this->addProviderTools($provider, $request, $tools);
        }

        try {
            $response = $request
                ->withMessages($this->toPrismMessages($messages))
                ->{$structured ? 'asStructured' : 'asText'}();
        } catch (PrismVendorException $e) {
            throw PrismException::toAiException($e, $provider, $model);
        }

        $citations = PrismCitations::toLaravelCitations(
            new Collection($response->additionalContent['citations'] ?? [])
        );

        return $structured
            ? (new StructuredTextResponse(
                $response->structured,
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model, $citations),
            ))
            : (new TextResponse(
                $response->text,
                PrismUsage::toLaravelUsage($response->usage),
                new Meta($provider->name(), $response->meta->model, $citations),
            ));
    }

    /**
     * Mark the last tool with cache_control so Anthropic caches every tool
     * definition (descriptions, JSON schemas) that precedes it.
     *
     * Tool definitions are identical across requests but can easily consume
     * 10,000+ input tokens. Caching them drops that cost by 90%.
     */
    #[Override]
    protected function addTools($request, array $tools, ?TextGenerationOptions $options = null)
    {
        $prismTools = new Collection($tools)->map(fn ($tool): ?PrismTool => $tool instanceof ProviderTool ? null : $this->createPrismTool($tool))->filter()->values();

        // Place the cache breakpoint on the last tool so all tool
        // definitions before it are included in the cached prefix.
        if ($prismTools->isNotEmpty()) {
            $prismTools->last()->withProviderOptions([
                'cacheType' => AnthropicCacheType::Ephemeral,
            ]);
        }

        return $request
            ->withTools($prismTools->all())
            ->withToolChoice(ToolChoice::Auto)
            ->withMaxSteps(
                $options?->maxSteps ?? round(count($tools) * 1.5)
            );
    }
}
