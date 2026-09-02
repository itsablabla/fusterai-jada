<?php

namespace App\Console\Commands;

use App\Services\AiSettingsService;
use Illuminate\Console\Command;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class DebugEmbeddings extends Command
{
    protected $signature = 'debug:embeddings {workspace=1}';

    protected $description = 'Test embeddings generation for a workspace and dump the config path Prism will use';

    public function handle(AiSettingsService $s): int
    {
        $wid = (int) $this->argument('workspace');

        // 1) show what resolveCredentials returns
        $ref = new \ReflectionClass($s);
        $m = $ref->getMethod('resolveCredentials');
        $m->setAccessible(true);
        $creds = $m->invoke($s, $wid);
        $this->info('resolveCredentials:');
        $this->line(json_encode([
            'provider' => $creds['provider'],
            'lab' => $creds['lab']->value,
            'model' => $creds['model'],
            'base_url' => $creds['base_url'],
            'key_prefix' => $creds['key'] ? substr($creds['key'], 0, 8).'...(len '.strlen($creds['key']).')' : null,
        ], JSON_PRETTY_PRINT));

        // 2) show current ai.providers.openai config BEFORE the swap
        $this->info('Before swap: ai.providers.openai');
        $this->line(json_encode([
            'key_prefix' => is_string(config('ai.providers.openai.key')) ? substr((string) config('ai.providers.openai.key'), 0, 8).'...' : null,
            'url' => config('ai.providers.openai.url'),
        ], JSON_PRETTY_PRINT));

        // 3) inside the callback, show config that Prism will use
        try {
            $s->withWorkspaceCredentials($wid, function (Lab $lab, ?string $model) {
                $this->info('Inside callback: ai.providers.openai');
                $this->line(json_encode([
                    'key_prefix' => is_string(config('ai.providers.openai.key')) ? substr((string) config('ai.providers.openai.key'), 0, 8).'...' : null,
                    'url' => config('ai.providers.openai.url'),
                ], JSON_PRETTY_PRINT));

                $embeddingsLab = $lab === Lab::Anthropic ? Lab::OpenAI : $lab;
                $this->info("Calling Embeddings on lab={$embeddingsLab->value}…");
                $resp = Embeddings::for(['hello world'])->generate($embeddingsLab);
                $vec = $resp->first();
                $this->info('Embedding dim: '.count($vec));
            });
        } catch (\Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
