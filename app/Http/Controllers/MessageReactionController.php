<?php

namespace App\Http\Controllers;

use App\Models\MailboxEntry;
use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageReactionController extends Controller
{
    public function toggle(Request $request, Message $message)
    {
        abort_unless(
            MailboxEntry::where('message_id', $message->id)->where('user_id', $request->user()->id)->exists(),
            403,
        );

        $data = $request->validate([
            'emoji' => ['required', 'string', Rule::in(config('mailbox.emojis'))],
        ]);

        $reaction = MessageReaction::where([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
            'emoji' => $data['emoji'],
        ])->first();

        if ($reaction) {
            $reaction->delete();
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $request->user()->id,
                'emoji' => $data['emoji'],
            ]);
        }

        return back()->with('status', $reaction ? 'Reaction removed.' : 'Reaction added.');
    }
}
