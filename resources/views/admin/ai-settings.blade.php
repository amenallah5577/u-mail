@extends('layouts.app')
@section('title', 'Local Agent Engine')
@section('content')
@php($aiOn = $setting->enabled && $setting->provider === 'local')
<section class="admin-page">
    <div class="page-heading">
        <div><p class="eyebrow">PRIVATE LOCAL ENGINE</p><h1>Local agent engine</h1></div>
        <span class="page-count"><b>{{ $aiOn ? 'ON' : 'OFF' }}</b> for everyone</span>
    </div>

    @if($connectionStatus)
        <div class="ai-status-banner {{ $connectionStatus['ok'] ? 'ready' : 'needs-attention' }}">
            <x-icon name="sparkles" />
            <span>{{ $connectionStatus['message'] }}</span>
        </div>
    @endif

    <div class="panel ai-settings-panel">
        <div class="ai-settings-intro">
            <h2>Turn on U-Assist</h2>
            <p>U-Mail can use a free local Llama 3.2 model running on this computer or server. Employees use U-Assist in mailbox pages, and the agent can summarize mail, search authorized messages, review drafts, and prepare replies for confirmation.</p>
        </div>

        <form method="POST" action="{{ route('admin.ai-settings.update') }}">
            @csrf @method('PATCH')
            <label class="ai-toggle-card">
                <input type="checkbox" name="enabled" value="1" @checked($aiOn)>
                <span>
                    <strong>Enable local agent engine</strong>
                    <small>Enable U-Assist after the local model is ready.</small>
                </span>
            </label>

            <div class="ai-friendly-grid">
                <label>Local model address
                    <input name="local_endpoint" value="{{ old('local_endpoint', $setting->local_endpoint ?: config('ai.local_endpoint')) }}" placeholder="http://127.0.0.1:11434">
                    <small>Keep the default when the free local model runs on this same machine.</small>
                </label>
                <label>Model
                    <input name="local_model" value="{{ old('local_model', $setting->local_model ?: config('ai.local_model')) }}" placeholder="llama3.2">
                    <small>The model must already be available locally.</small>
                </label>
            </div>

            <div class="ai-settings-actions">
                <button class="primary-button small">Save agent settings</button>
                <a class="soft-button small" href="{{ route('admin.ai-settings', ['check' => 1]) }}">Check engine</a>
            </div>
        </form>
    </div>

    <div class="panel ai-settings-panel">
        <div class="ai-settings-intro">
            <h2>What users will see</h2>
            <p>Users see U-Assist in their mailbox workflow. The agent answers from their own mailbox only and can prepare drafts or reviewed action plans. Users must confirm any prepared work before it is saved or applied.</p>
        </div>
    </div>
</section>
@endsection
