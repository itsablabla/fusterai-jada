<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\KbDocument;
use App\Services\AiSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use App\Models\Workspace;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider as PrismProvider;

class IndexKbDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly KbDocument $document,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        // For long documents, embed the head; chunked child documents cover the rest.
        // Chunks are dispatched by KbSeeder / import commands; each chunk arrives here
        // as its own KbDocument (parent_id in meta) and just gets its own embedding.
        $text = $this->document->title."\n\n".$this->document->content;

        // Approximate token budget. text-embedding-3-small handles 8192 tokens (~32k chars)
        // but shorter inputs give tighter cosine matches for short-form Q&A retrieval.
        $maxChars = (int) config('ai.embeddings.max_chars', 6000);
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars);
        }

        $workspaceId = $this->document->knowledgeBase->workspace_id;
        $creds = $this->workspaceEmbeddingsCredentials($workspaceId);

        // Call Prism directly with explicit provider config, bypassing the cached
        // Laravel\Ai manager. When the workspace uses an OpenAI-compatible gateway
        // (9router, LiteLLM, Groq, OpenRouter, Ollama), the manager's cached driver
        // instance can still hold the empty OPENAI_API_KEY fallback even after
        // withWorkspaceCredentials mutates config — which surfaces as a 400
        // "No credentials for provider: openai". Direct Prism calls sidestep that.
        $providerConfig = array_filter([
            'api_key' => $creds['key'],
            'url' => $creds['url'],
        ]);

        $model = $creds['embed_model'];

        $response = app(Prism::class)->embeddings()
            ->using(PrismProvider::OpenAI, $model, $providerConfig)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = collect($response->embeddings)->map->embedding->first();

        $this->document->embedding = $vector;
        $this->document->indexed_at = now();

        // Clear any previous index error
        $meta = $this->document->meta ?? [];
        unset($meta['index_error']);
        $this->document->meta = $meta;
        $this->document->save();
    }

    /**
     * Resolve workspace embeddings credentials directly from settings.
     *
     * @return array{key: string|null, url: string|null, embed_model: string}
     */
    protected function workspaceEmbeddingsCredentials(int $workspaceId): array
    {
        $settings = Cache::remember(
            "workspace.ai_settings.{$workspaceId}",
            now()->addMinutes(5),
            fn () => Workspace::findOrFail($workspaceId)->settings ?? [],
        );

        // Dedicated embeddings credentials come first — many chat gateways
        // (9router, some LiteLLM/Groq setups) don't proxy /embeddings, so the
        // operator can point embeddings at OpenAI, Voyage, Jina, etc. directly.
        $embedKey = env('FUSTERAI_EMBED_API_KEY')
            ?: ($settings['ai_embed_api_key'] ?? null);
        if ($embedKey && isset($settings['ai_embed_api_key']) && $embedKey === $settings['ai_embed_api_key']) {
            try {
                $embedKey = Crypt::decryptString($embedKey);
            } catch (\Throwable) {
                // Assume plaintext (env-provided).
            }
        }
        $embedUrl = env('FUSTERAI_EMBED_BASE_URL')
            ?: ($settings['ai_embed_base_url'] ?? null);

        if ($embedKey) {
            $embedModel = env('FUSTERAI_EMBED_MODEL')
                ?? ($settings['ai_embed_model'] ?? 'text-embedding-3-small');

            return [
                'key' => $embedKey,
                'url' => $embedUrl ?: null,
                'embed_model' => $embedModel,
            ];
        }

        // Fallback: try to reuse the workspace chat credentials. This works for
        // real OpenAI / OpenRouter / Ollama; it will fail on chat-only gateways.
        $provider = $settings['ai_provider'] ?? 'anthropic';
        $baseUrl = $settings['ai_base_url'] ?? null;

        $key = null;
        if (! empty($settings['ai_api_key'])) {
            try {
                $key = Crypt::decryptString($settings['ai_api_key']);
            } catch (\Throwable) {
                $key = null;
            }
        }

        // Anthropic has no embeddings API. Fall back to OpenAI proper via env.
        if ($provider === 'anthropic' || ! $key) {
            $key = config('ai.providers.openai.key') ?: env('OPENAI_API_KEY');
            $baseUrl = config('ai.providers.openai.url') ?: env('OPENAI_BASE_URL');
        }

        $embedModel = env('FUSTERAI_EMBED_MODEL')
            ?? ($settings['ai_embed_model'] ?? 'text-embedding-3-small');

        return [
            'key' => $key,
            'url' => $baseUrl ?: null,
            'embed_model' => $embedModel,
        ];
    }

    public function failed(\Throwable $e): void
    {
        $meta = $this->document->meta ?? [];
        $meta['index_error'] = $e->getMessage();
        $this->document->update(['meta' => $meta]);
    }
}
