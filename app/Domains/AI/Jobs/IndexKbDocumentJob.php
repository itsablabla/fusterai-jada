<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\KbDocument;
use App\Services\AiSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

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

        app(AiSettingsService::class)->withWorkspaceCredentials(
            $this->document->knowledgeBase->workspace_id,
            function (Lab $lab) use ($text): void {
                // Anthropic does not provide an embeddings API; fall back to OpenAI.
                $embeddingsLab = ($lab === Lab::Anthropic) ? Lab::OpenAI : $lab;

                $response = Embeddings::for([$text])->generate($embeddingsLab);

                $this->document->embedding = $response->first();
                $this->document->indexed_at = now();
            }
        );

        // Clear any previous index error
        $meta = $this->document->meta ?? [];
        unset($meta['index_error']);
        $this->document->meta = $meta;
        $this->document->save();
    }

    public function failed(\Throwable $e): void
    {
        $meta = $this->document->meta ?? [];
        $meta['index_error'] = $e->getMessage();
        $this->document->update(['meta' => $meta]);
    }
}
