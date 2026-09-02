<?php

namespace App\Console\Commands;

use App\Ai\Tools\SearchKnowledgeBase;
use Illuminate\Console\Command;
use Laravel\Ai\Tools\Request as ToolRequest;

class DebugRag extends Command
{
    protected $signature = 'debug:rag {workspace=1} {query=return label missing}';
    protected $description = 'Run SearchKnowledgeBase against a workspace and dump raw results';

    public function handle(): int
    {
        $wid = (int) $this->argument('workspace');
        $q = $this->argument('query');
        $tool = new SearchKnowledgeBase($wid);
        // Build a minimal Request the tool expects
        $req = new ToolRequest(['query' => $q]);
        $this->info("query: {$q}");
        $result = $tool->handle($req);
        $this->line($result);
        return self::SUCCESS;
    }
}
