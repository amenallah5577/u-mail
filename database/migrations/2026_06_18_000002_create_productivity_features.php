<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 24)->default('#d97a07');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('mail_label_mailbox_entry', function (Blueprint $table): void {
            $table->foreignId('mail_label_id')->constrained('mail_labels')->cascadeOnDelete();
            $table->foreignId('mailbox_entry_id')->constrained('mailbox_entries')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['mail_label_id', 'mailbox_entry_id'], 'mail_label_entry_primary');
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('subject', 255)->nullable();
            $table->longText('body_html');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 24)->default('none');
            $table->string('local_endpoint')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_type', 80);
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('type', 80);
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'context_type', 'context_id']);
        });

        Schema::table('mailbox_entries', function (Blueprint $table): void {
            $table->timestamp('snoozed_until')->nullable()->index();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('scheduled_send_at')->nullable()->index();
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reminder_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn('reminder_minutes');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('scheduled_send_at');
        });

        Schema::table('mailbox_entries', function (Blueprint $table): void {
            $table->dropColumn('snoozed_until');
        });

        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_settings');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('mail_label_mailbox_entry');
        Schema::dropIfExists('mail_labels');
    }
};
