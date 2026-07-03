@extends('layouts.app')
@section('title', ucfirst($folder))
@section('content')
<section class="mail-page" data-agent-context data-agent-context-type="{{ $activeFilters ? 'search' : $folder }}">
    <div class="page-heading">
        <div><p class="eyebrow">{{ $activeFilters ? 'FILTERED MAIL' : 'UTICA JENDOUBA MAILBOX' }}</p><h1>{{ $activeFilters ? 'Filtered '.ucfirst($folder) : ucfirst($folder) }}</h1></div>
        <span class="page-count"><b>{{ $entries->total() }}</b> messages</span>
    </div>
    <div class="u-assist-bar" aria-label="U-Assist mailbox actions">
        <span><x-icon name="sparkles" /> U-Assist</span>
        @if($activeFilters)
            <button type="button" data-agent-action data-agent-context-type="search" data-agent-prompt="Explain these search results and identify the best matching messages.">Explain results</button>
            <button type="button" data-agent-action data-agent-context-type="search" data-agent-prompt="Find related messages from my authorized mailbox and explain why they are related.">Find related</button>
        @elseif($folder === 'inbox')
            <button type="button" data-agent-action data-agent-context-type="inbox" data-agent-prompt="Summarize my unread inbox messages and group them by what needs attention.">Summarize unread</button>
            <button type="button" data-agent-action data-agent-context-type="inbox" data-agent-prompt="Which inbox messages likely need a reply? Explain the reason for each one.">Needs reply</button>
            <button type="button" data-agent-action data-agent-context-type="inbox" data-agent-prompt="Show only urgent or high-priority inbox messages and explain why they matter.">Urgent only</button>
        @elseif($folder === 'drafts' || $folder === 'scheduled')
            <button type="button" data-agent-action data-agent-context-type="{{ $folder }}" data-agent-prompt="Review my unfinished messages and identify drafts that need cleanup before sending.">Review drafts</button>
            <button type="button" data-agent-action data-agent-context-type="{{ $folder }}" data-agent-prompt="Which drafts are missing a recipient, subject, clear closing, or mentioned attachment?">Check before sending</button>
        @elseif($folder === 'sent')
            <button type="button" data-agent-action data-agent-context-type="sent" data-agent-prompt="Review my sent messages and identify items that may need follow-up.">Find follow-ups</button>
            <button type="button" data-agent-action data-agent-context-type="sent" data-agent-prompt="Summarize my recent sent messages.">Summarize sent</button>
        @else
            <button type="button" data-agent-action data-agent-context-type="{{ $folder }}" data-agent-prompt="Summarize this mailbox folder and identify anything that needs attention.">Summarize folder</button>
            <button type="button" data-agent-action data-agent-context-type="{{ $folder }}" data-agent-prompt="Find important messages in this folder and explain why they matter.">Important items</button>
        @endif
    </div>
    @if($activeFilters)
        <div class="filter-chip-row">
            @foreach($activeFilters as $filter)
                <a href="{{ $filter['url'] }}">{{ $filter['label'] }} <span>×</span></a>
            @endforeach
            <a class="clear-filter-chip" href="{{ route('mailbox.folder', $folder) }}">Clear filters</a>
        </div>
    @endif
    <details class="mail-productivity-panel">
        <summary><x-icon name="tag" /> Labels</summary>
        <form method="POST" action="{{ route('labels.store') }}">
            @csrf
            <input name="name" placeholder="New label name" maxlength="80" required>
            <input type="color" name="color" value="#d97a07" title="Label color">
            <button class="soft-button small">Create label</button>
        </form>
    </details>
    <div class="mail-list">
        @forelse($entries as $entry)
            @php
                $message = $entry->message;
                $quickAction = $folder === 'trash' ? 'restore' : ($folder === 'inbox' ? 'archive' : 'trash');
                $quickTitle = $folder === 'trash' ? 'Restore' : ($folder === 'inbox' ? 'Archive' : 'Move to Trash');
                $quickIcon = $folder === 'trash' ? '↥' : ($folder === 'inbox' ? '▣' : '⌫');
                $threadContext = array_filter(array_merge([
                    'thread' => $message->thread_id,
                    'folder' => $folder,
                ], $filterQuery ?? [], [
                    'page' => request('page'),
                ]));
            @endphp
            <article class="mail-row {{ !$entry->is_read ? 'unread' : '' }}">
                <form method="POST" action="{{ route('mailbox.update', $entry) }}">@csrf
                    <input type="hidden" name="action" value="{{ $entry->is_starred ? 'unstar' : 'star' }}">
                    <button class="star" title="Star">{{ $entry->is_starred ? '★' : '☆' }}</button>
                </form>
                <a class="mail-content" href="{{ in_array($message->status, ['draft', 'scheduled'], true) ? '#' : route('threads.show', $threadContext) }}"
                   @if(in_array($message->status, ['draft', 'scheduled'], true)) data-open-draft data-draft-id="{{ $message->id }}" data-draft-url="{{ route('messages.draft.show', $message) }}" @endif>
                    <span class="sender">{{ $entry->folder === 'sent' || $entry->folder === 'drafts' ? 'To: '.$message->recipients->where('type', 'to')->pluck('display_name')->join(', ') : $message->senderDisplayName() }}</span>
                    <span class="subject">{{ $message->subject }}</span>
                    <span class="snippet">- {{ \Illuminate\Support\Str::limit($message->body_text, 90) }} @if($entry->folder === 'sent' && $message->externalDelivery) · {{ $message->externalDelivery->userLabel() }} @endif @if($message->status === 'scheduled') · Scheduled {{ $message->scheduledLabel() }} @endif @if($entry->snoozed_until && $entry->snoozed_until->isFuture()) · Snoozed until {{ $entry->snoozed_until->format('M j, H:i') }} @endif</span>
                    @if($entry->labels->isNotEmpty())
                        <span class="mail-label-chip-row">
                            @foreach($entry->labels as $label)
                                <b style="--label-color: {{ $label->color }}">{{ $label->name }}</b>
                            @endforeach
                        </span>
                    @endif
                    @if($message->attachments->isNotEmpty())<span class="paperclip">⌕</span>@endif
                    <time>{{ ($message->sent_at ?? $message->updated_at)->isToday() ? ($message->sent_at ?? $message->updated_at)->format('H:i') : ($message->sent_at ?? $message->updated_at)->format('M j') }}</time>
                </a>
                <form class="row-action" method="POST" action="{{ route('mailbox.update', $entry) }}">@csrf
                    <input type="hidden" name="action" value="{{ $quickAction }}">
                    <button title="{{ $quickTitle }}">{{ $quickIcon }}</button>
                </form>
                @if($folder === 'trash')
                    <form class="row-action permanent" method="POST" action="{{ route('mailbox.update', $entry) }}">@csrf
                        <input type="hidden" name="action" value="delete">
                        <button title="Delete permanently" data-confirm="Permanently delete this mailbox copy?">×</button>
                    </form>
                @endif
                @if($folder !== 'trash')
                    <details class="row-tools">
                        <summary title="More tools"><x-icon name="more" /></summary>
                        <div>
                            @if(in_array($message->status, ['draft', 'scheduled'], true))
                                <button type="button" class="agent-row-button" data-agent-action data-agent-context-type="draft" data-agent-context-id="{{ $message->id }}" data-agent-prompt="Improve this draft, make it formal and precise, and preserve the recipient, subject, names, dates, and meaning."><x-icon name="sparkles" /> Improve draft</button>
                            @endif
                            @if(($mailLabels ?? collect())->isNotEmpty())
                                <form method="POST" action="{{ route('mailbox.labels.apply', $entry) }}">
                                    @csrf
                                    <select name="label_id">
                                        @foreach($mailLabels as $label)
                                            <option value="{{ $label->id }}">{{ $label->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="soft-button small">Apply label</button>
                                </form>
                            @endif
                            @include('mailbox.partials.snooze-controls', ['entry' => $entry, 'action' => route('mailbox.update', $entry)])
                        </div>
                    </details>
                @endif
            </article>
        @empty
            <div class="empty-state"><span>✉</span><h2>Nothing here yet</h2><p>This mailbox folder is ready for your next conversation.</p></div>
        @endforelse
    </div>
    <div class="pagination">{{ $entries->links() }}</div>
</section>
@endsection
