<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch the KB embedding column from vector(1536) (OpenAI text-embedding-3-small)
 * to vector(1024) so we can use Voyage / Jina embeddings, which speak the same
 * OpenAI-compatible API but return 1024 dimensions.
 *
 * Any previously-stored vectors are wiped because they're not comparable across
 * dimensions. The KB seed just gets re-embedded on the next kb:import run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // Drop and recreate — Postgres pgvector doesn't support ALTER on the
        // dimension in a single statement and existing vectors are now invalid.
        DB::statement('UPDATE kb_documents SET embedding = NULL, indexed_at = NULL');

        Schema::table('kb_documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        Schema::table('kb_documents', function (Blueprint $table) {
            $table->vector('embedding', dimensions: 1024)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('UPDATE kb_documents SET embedding = NULL, indexed_at = NULL');

        Schema::table('kb_documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        Schema::table('kb_documents', function (Blueprint $table) {
            $table->vector('embedding', dimensions: 1536)->nullable()->after('content');
        });
    }
};
