<?php

namespace App\Http\Controllers;

use App\Models\MailboxEntry;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function index(Request $request)
    {
        return view('account.notifications', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        $enabled = (bool) $data['enabled'];
        $user->update(['mail_notifications_enabled' => $enabled]);

        return response()->json([
            'enabled' => $enabled,
            'cursor' => $this->latestInboxEntryId($user->id),
        ]);
    }

    private function latestInboxEntryId(int $userId): int
    {
        return (int) MailboxEntry::where('user_id', $userId)
            ->where('folder', 'inbox')
            ->max('id');
    }
}
