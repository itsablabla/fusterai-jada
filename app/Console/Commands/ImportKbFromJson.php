<?php

namespace App\Console\Commands;

use App\Domains\AI\Jobs\IndexKbDocumentJob;
use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Import a JSON file of KB documents into a workspace's active knowledge base.
 *
 * Expected JSON structure: [{ title, content, source_url?, meta? }, ...]
 *
 * With --chunk, long documents are split into ~1500-char chunks with 200-char
 * overlap so each chunk carries its own embedding.
 */
class ImportKbFromJson extends Command
{
    protected $signature = 'kb:import
        {file : Path to the JSON seed file (relative to storage/app)}
        {--workspace=1 : Target workspace id}
        {--kb= : Knowledge base name (created if missing)}
        {--chunk : Split long documents into overlapping chunks}
        {--chunk-size=1500 : Chunk size in characters}
        {--chunk-overlap=200 : Chunk overlap in characters}
        {--replace : Delete existing docs in this KB before importing}
        {--index : Dispatch IndexKbDocumentJob for each imported doc}';

    protected $description = 'Import KB documents from a JSON file and (optionally) index them';

    public function handle(): int
    {
        $path = storage_path('app/'.$this->argument('file'));
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $workspaceId = (int) $this->option('workspace');
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            $this->error("Workspace {$workspaceId} not found");

            return self::FAILURE;
        }

        $kbName = $this->option('kb') ?: 'Nomad Support Playbook';
        $kb = KnowledgeBase::firstOrCreate(
            ['workspace_id' => $workspaceId, 'name' => $kbName],
            ['active' => true, 'description' => 'Seeded via kb:import']
        );

        if ($this->option('replace')) {
            $count = KbDocument::where('kb_id', $kb->id)->delete();
            $this->warn("Removed {$count} existing documents from '{$kbName}'.");
        }

        $docs = json_decode(file_get_contents($path), true);
        if (! is_array($docs)) {
            $this->error('Invalid JSON');

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk-size');
        $overlap = (int) $this->option('chunk-overlap');
        $shouldChunk = $this->option('chunk');
        $shouldIndex = $this->option('index');

        $created = 0;
        $indexed = [];

        foreach ($docs as $doc) {
            $title = $doc['title'] ?? '';
            $content = $doc['content'] ?? '';
            if ($title === '' || $content === '') {
                continue;
            }

            $pieces = $shouldChunk ? $this->chunk($content, $chunkSize, $overlap) : [$content];
            $total = count($pieces);

            foreach ($pieces as $idx => $piece) {
                $chunkTitle = $total > 1 ? "{$title} (part ".($idx + 1)."/{$total})" : $title;
                $meta = $doc['meta'] ?? [];
                if ($total > 1) {
                    $meta['chunk_index'] = $idx;
                    $meta['chunk_total'] = $total;
                    $meta['parent_title'] = $title;
                }

                $model = KbDocument::create([
                    'kb_id' => $kb->id,
                    'title' => $chunkTitle,
                    'content' => $piece,
                    'source_url' => $doc['source_url'] ?? null,
                    'meta' => $meta,
                ]);
                $created++;
                $indexed[] = $model;
            }
        }

        $this->info("Imported {$created} documents into '{$kbName}' (workspace {$workspaceId}).");

        if ($shouldIndex) {
            foreach (array_chunk($indexed, 20) as $batch) {
                Bus::batch(array_map(fn ($d) => new IndexKbDocumentJob($d), $batch))
                    ->onQueue('ai')
                    ->dispatch();
            }
            $this->info("Dispatched {$created} indexing jobs on the 'ai' queue.");
        }

        return self::SUCCESS;
    }

    /**
     * Split text into overlapping chunks. Tries to break on paragraph boundaries.
     *
     * @return array<int, string>
     */
    protected function chunk(string $text, int $size, int $overlap): array
    {
        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $chunks = [];
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [$text];
        $buffer = '';

        foreach ($paragraphs as $p) {
            if (mb_strlen($buffer) + mb_strlen($p) + 2 <= $size) {
                $buffer .= ($buffer === '' ? '' : "\n\n").$p;

                continue;
            }
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $tail = mb_substr($buffer, max(0, mb_strlen($buffer) - $overlap));
                $buffer = $tail."\n\n".$p;
            } else {
                // A single paragraph is longer than the chunk size — hard-slice it.
                for ($i = 0; $i < mb_strlen($p); $i += ($size - $overlap)) {
                    $chunks[] = mb_substr($p, $i, $size);
                }
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
