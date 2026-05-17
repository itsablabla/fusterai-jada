<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add integer column alongside the existing enum
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->tinyInteger('rating_new')->unsigned()->after('rating');
        });

        // Map existing good/bad → 5/1
        DB::table('survey_responses')->where('rating', 'good')->update(['rating_new' => 5]);
        DB::table('survey_responses')->where('rating', 'bad')->update(['rating_new' => 1]);

        // Swap: drop old enum, rename new integer column
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('rating');
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->renameColumn('rating_new', 'rating');
        });

        // Add check constraint (supported in MySQL 8.0.16+ and PostgreSQL)
        // Skipped here — validation is enforced at the application layer instead.
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->string('rating_old', 10)->after('rating');
        });

        // Map back: 4-5 → good, 1-3 → bad
        DB::table('survey_responses')->where('rating', '>=', 4)->update(['rating_old' => 'good']);
        DB::table('survey_responses')->where('rating', '<', 4)->update(['rating_old' => 'bad']);

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('rating');
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->renameColumn('rating_old', 'rating');
        });
    }
};
