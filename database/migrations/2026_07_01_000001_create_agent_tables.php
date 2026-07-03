<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('context_type', 40)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->json('result')->nullable();
            $table->text('error_text')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('agent_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24);
            $table->longText('content');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['agent_run_id', 'role']);
        });

        Schema::create('agent_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('status', 24)->default('completed');
            $table->timestamps();
            $table->index(['agent_run_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_runs');
    }
};
