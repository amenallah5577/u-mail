<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_entry_ai_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('smart_snippet', 240)->nullable();
            $table->string('action_hint', 80)->nullable();
            $table->string('priority', 24)->nullable();
            $table->string('language', 24)->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_entry_ai_notes');
    }
};
