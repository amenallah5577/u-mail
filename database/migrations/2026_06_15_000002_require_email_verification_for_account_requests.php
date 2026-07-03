<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('status', 'requested')
            ->whereNull('email_verified_at')
            ->update([
                'status' => 'email_verification',
                'registration_requested_at' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('status', 'email_verification')
            ->update([
                'status' => 'requested',
                'registration_requested_at' => now(),
            ]);
    }
};
