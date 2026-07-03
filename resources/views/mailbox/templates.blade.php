@extends('layouts.app')
@section('title', 'Templates')
@section('content')
<section class="templates-page">
    <div class="page-heading">
        <div><p class="eyebrow">COMPOSE TOOLS</p><h1>Message templates</h1></div>
        <span class="page-count"><b>{{ $templates->count() }}</b> saved</span>
    </div>
    <div class="templates-grid">
        <aside class="panel">
            <p class="eyebrow">NEW TEMPLATE</p><h2>Create a reusable reply</h2>
            <form class="template-form" method="POST" action="{{ route('templates.store') }}">
                @csrf
                <label>Name<input name="name" maxlength="120" required></label>
                <label>Subject<input name="subject" maxlength="255"></label>
                <label>Body<textarea name="body_html" rows="8" required></textarea></label>
                <button class="primary-button small">Save template</button>
            </form>
        </aside>
        <div class="panel">
            <p class="eyebrow">SAVED</p><h2>Your templates</h2>
            @forelse($templates as $template)
                <details class="template-card">
                    <summary><strong>{{ $template->name }}</strong><span>{{ $template->subject ?: 'No subject preset' }}</span></summary>
                    <form class="template-form" method="POST" action="{{ route('templates.update', $template) }}">
                        @csrf @method('PATCH')
                        <label>Name<input name="name" value="{{ $template->name }}" maxlength="120" required></label>
                        <label>Subject<input name="subject" value="{{ $template->subject }}" maxlength="255"></label>
                        <label>Body<textarea name="body_html" rows="7" required>{{ $template->body_html }}</textarea></label>
                        <button class="soft-button">Update</button>
                    </form>
                    <form method="POST" action="{{ route('templates.destroy', $template) }}">
                        @csrf @method('DELETE')
                        <button class="soft-button danger-button" data-confirm="Delete this template?">Delete</button>
                    </form>
                </details>
            @empty
                <div class="empty-state compact-empty"><h2>No templates yet</h2><p>Create one for common replies or announcements.</p></div>
            @endforelse
        </div>
    </div>
</section>
@endsection
