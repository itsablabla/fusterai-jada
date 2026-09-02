<?php
/**
 * Report the workspace AI configuration at boot, and optionally enable every AI
 * feature (FUSTERAI_AI_ENABLE_ALL=true). Never prints the API key.
 *
 * If ANTHROPIC_API_KEY / OPENAI_API_KEY / OPENROUTER_API_KEY is present in the
 * environment and no key is stored in the workspace yet, it is copied (encrypted)
 * into the workspace settings so the in-app config and env stay consistent.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

$features = ['reply_suggestions', 'auto_categorization', 'summarization'];

foreach (\App\Models\Workspace::query()->orderBy('id')->get() as $ws) {
    $s = $ws->settings ?? [];
    $changed = false;

    // Seed key from env if the workspace has none yet
    if (empty($s['ai_api_key'])) {
        foreach (['anthropic' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_API_KEY', 'openrouter' => 'OPENROUTER_API_KEY'] as $prov => $env) {
            if ($v = getenv($env)) {
                $s['ai_api_key'] = Crypt::encryptString($v);
                $s['ai_provider'] = $s['ai_provider'] ?? $prov;
                $changed = true;
                echo "[ai] workspace {$ws->id}: seeded API key from env {$env} (provider {$prov})".PHP_EOL;
                break;
            }
        }
    }

    if ((getenv('FUSTERAI_AI_ENABLE_ALL') ?: 'false') === 'true') {
        $s['ai_features'] = array_fill_keys($features, true);
        $s['ai_rag'] = $s['ai_rag'] ?? ['top_k' => 5, 'min_score' => 0.7];
        $changed = true;
    }

    if ($changed) {
        $ws->settings = $s;
        $ws->save();
        Cache::forget("workspace.ai_settings.{$ws->id}");
    }

    $key = $s['ai_api_key'] ?? null;
    $keyInfo = 'none';
    if ($key) {
        try {
            $plain = Crypt::decryptString($key);
            $keyInfo = sprintf('set (%s…%s, len %d)', substr($plain, 0, 7), substr($plain, -4), strlen($plain));
        } catch (\Throwable) {
            $keyInfo = 'set (undecryptable!)';
        }
    }
    $feat = $s['ai_features'] ?? ['(defaults: all on)' => true];
    printf("[ai] workspace %d '%s': provider=%s model=%s base_url=%s key=%s features=%s rag=%s\n",
        $ws->id, $ws->name,
        $s['ai_provider'] ?? 'anthropic (default)',
        $s['ai_model'] ?? '(provider default)',
        $s['ai_base_url'] ?? '-',
        $keyInfo,
        json_encode($feat),
        json_encode($s['ai_rag'] ?? ['top_k' => 5, 'min_score' => 0.7]),
    );
}
