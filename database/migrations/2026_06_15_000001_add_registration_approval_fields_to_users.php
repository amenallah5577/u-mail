<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->timestamp('registration_requested_at')->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('registration_requested_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->index(['status', 'registration_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'registration_requested_at']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['registration_requested_at', 'approved_at', 'approved_by', 'rejected_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
