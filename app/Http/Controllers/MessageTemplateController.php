<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function index(Request $request)
    {
        return view('mailbox.templates', [
            'templates' => $request->user()->messageTemplates()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, HtmlSanitizer $sanitizer)
    {
        $request->user()->messageTemplates()->create($this->templateData($request, $sanitizer));

        return back()->with('status', 'Template saved.');
    }

    public function update(Request $request, MessageTemplate $messageTemplate, HtmlSanitizer $sanitizer)
    {
        abort_unless($messageTemplate->user_id === $request->user()->id, 403);
        $messageTemplate->update($this->templateData($request, $sanitizer));

        return back()->with('status', 'Template updated.');
    }

    public function destroy(Request $request, MessageTemplate $messageTemplate)
    {
        abort_unless($messageTemplate->user_id === $request->user()->id, 403);
        $messageTemplate->delete();

        return back()->with('status', 'Template deleted.');
    }

    private function templateData(Request $request, HtmlSanitizer $sanitizer): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:100000'],
        ]);
        $data['body_html'] = $sanitizer->sanitize($data['body_html']);

        return $data;
    }
}
