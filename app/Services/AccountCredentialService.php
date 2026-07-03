<?php

namespace App\Services;

use App\Models\AccountCredential;
use App\Models\User;

class AccountCredentialService
{
    public function store(User $user, string $plainPassword): AccountCredential
    {
        return AccountCredential::updateOrCreate(
            ['user_id' => $user->id],
            ['password_encrypted' => $plainPassword],
        );
    }
}
