<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_created_at')->nullable();
            $table->timestamp('invitation_revoked_at')->nullable();
            $table->foreignId('source_event_id')->nullable()->index();
            $table->timestamp('source_sync_stopped_at')->nullable();
            $table->unique(['source_event_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropUnique(['source_event_id', 'owner_id']);
            $table->dropUnique(['invitation_token_hash']);
            $table->dropIndex(['source_event_id']);
            $table->dropColumn([
                'invitation_token_hash',
                'invitation_created_at',
                'invitation_revoked_at',
                'source_event_id',
                'source_sync_stopped_at',
            ]);
        });
    }
};
