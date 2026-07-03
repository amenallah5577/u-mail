<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'used_at']);
        });

        Schema::create('mail_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject');
            $table->timestamp('latest_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->string('status')->default('draft')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE messages ADD FULLTEXT messages_search_fulltext (subject, body_text)');
        }

        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->timestamps();
            $table->unique(['message_id', 'user_id', 'type']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('mailbox_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('folder')->index();
            $table->boolean('is_read')->default(false)->index();
            $table->boolean('is_starred')->default(false)->index();
            $table->timestamp('trashed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'folder', 'updated_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('mailbox_entries');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('mail_threads');
        Schema::dropIfExists('account_tokens');
    }
};
