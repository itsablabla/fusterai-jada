<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * imap_config / smtp_config are encrypted by the Mailbox model
     * (Crypt::encryptString) so they must be stored as text, not jsonb.
     * Mirrors 2026_04_08_030803_change_channels_config_to_text for channels.
     */
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->text('imap_config')->nullable()->change();
            $table->text('smtp_config')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->jsonb('imap_config')->nullable()->change();
            $table->jsonb('smtp_config')->nullable()->change();
        });
    }
};
