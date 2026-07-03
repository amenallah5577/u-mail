<?php

namespace App\Http\Controllers;

use App\Models\MailLabel;
use App\Models\MailboxEntry;
use Illuminate\Http\Request;

class MailLabelController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->labelData($request);
        $request->user()->mailLabels()->firstOrCreate(
            ['name' => $data['name']],
            ['color' => $data['color']],
        );

        return back()->with('status', 'Label saved.');
    }

    public function update(Request $request, MailLabel $mailLabel)
    {
        abort_unless($mailLabel->user_id === $request->user()->id, 403);
        $mailLabel->update($this->labelData($request));

        return back()->with('status', 'Label updated.');
    }

    public function destroy(Request $request, MailLabel $mailLabel)
    {
        abort_unless($mailLabel->user_id === $request->user()->id, 403);
        $mailLabel->delete();

        return redirect()->route('mailbox')->with('status', 'Label deleted.');
    }

    public function apply(Request $request, MailboxEntry $entry)
    {
        abort_unless($entry->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'label_id' => ['required', 'integer', 'exists:mail_labels,id'],
        ]);
        $label = MailLabel::where('user_id', $request->user()->id)->findOrFail($data['label_id']);
        $entry->labels()->syncWithoutDetaching([$label->id]);

        return back()->with('status', 'Label applied.');
    }

    public function remove(Request $request, MailboxEntry $entry, MailLabel $mailLabel)
    {
        abort_unless($entry->user_id === $request->user()->id && $mailLabel->user_id === $request->user()->id, 403);
        $entry->labels()->detach($mailLabel->id);

        return back()->with('status', 'Label removed.');
    }

    private function labelData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:24'],
        ]);
        $data['name'] = trim($data['name']);
        $data['color'] = $data['color'] ?: '#d97a07';

        return $data;
    }
}
