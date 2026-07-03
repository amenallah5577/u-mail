<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->string('local_model', 120)->nullable()->after('local_endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('local_model');
        });
    }
};
