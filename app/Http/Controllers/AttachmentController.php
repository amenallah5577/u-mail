<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\MailboxEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function download(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function preview(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);
        abort_unless(in_array($attachment->mime_type, ['application/pdf', 'image/gif', 'image/jpeg', 'image/png', 'image/webp'], true), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAttachment(Request $request, Attachment $attachment): void
    {
        $allowed = MailboxEntry::where('message_id', $attachment->message_id)
            ->where('user_id', $request->user()->id)
            ->exists();
        abort_unless($allowed, 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
    }
}
