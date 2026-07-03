<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('onboarding_tour_completed_at')->nullable()->after('ai_assistance_enabled');
            $table->unsignedSmallInteger('onboarding_tour_version')->default(0)->after('onboarding_tour_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_tour_completed_at', 'onboarding_tour_version']);
        });
    }
};
