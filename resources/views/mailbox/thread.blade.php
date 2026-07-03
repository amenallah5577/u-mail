@extends('layouts.app')
@section('title', $thread->subject)
@section('content')
@php
    $lastMessage = $messages->last();
    $contextQuery = array_filter(['folder' => $folder, 'q' => request('q'), 'page' => request('page')]);
    $returnTo = parse_url($backUrl, PHP_URL_PATH).(parse_url($backUrl, PHP_URL_QUERY) ? '?'.parse_url($backUrl, PHP_URL_QUERY) : '');
    $selfAddresses = array_filter([strtolower(auth()->user()->email), strtolower(auth()->user()->public_email)]);
    $replySubject = str_starts_with($thread->subject, 'Re:') ? $thread->subject : 'Re: '.$thread->subject;
@endphp
<section class="thread-page gmail-reader" data-thread-reader data-thread-id="{{ $thread->id }}" data-previous-url="{{ $previousUrl }}" data-next-url="{{ $nextUrl }}">
    <nav class="reader-toolbar" aria-label="Conversation actions">
        <a href="{{ $backUrl }}" class="reader-icon-button" title="Back to {{ $folder }}"><x-icon name="back" /></a>
        <span class="toolbar-divider"></span>
        @foreach([
            ['archive', 'archive', 'Archive conversation'],
            ['unread', 'mail', 'Mark as unread'],
            [$threadTrashed ? 'restore' : 'trash', $threadTrashed ? 'inbox' : 'trash', $threadTrashed ? 'Restore conversation' : 'Move conversation to Trash'],
        ] as [$action, $icon, $label])
            <form method="POST" action="{{ route('threads.mailbox.update', $thread) }}">@csrf
                <input type="hidden" name="action" value="{{ $action }}">
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <button class="reader-icon-button" title="{{ $label }}" @if($action === 'trash') data-confirm="Move this conversation to Trash?" @endif><x-icon :name="$icon" /></button>
            </form>
        @endforeach
        <details class="reader-more">
            <summary class="reader-icon-button" title="More actions"><x-icon name="more" /></summary>
            <div class="reader-menu">
                <form method="POST" action="{{ route('threads.mailbox.update', $thread) }}">@csrf
                    <input type="hidden" name="action" value="{{ $threadStarred ? 'unstar' : 'star' }}">
                    <button><x-icon name="star" /> {{ $threadStarred ? 'Remove star' : 'Star conversation' }}</button>
                </form>
                <button type="button" data-print-thread><x-icon name="print" /> Print conversation</button>
                @if($threadTrashed)
                    <form method="POST" action="{{ route('threads.mailbox.update', $thread) }}">@csrf
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="return_to" value="{{ $returnTo }}">
                        <button class="danger-button" data-confirm="Permanently delete your copies of this conversation?"><x-icon name="delete" /> Delete permanently</button>
                    </form>
                @endif
            </div>
        </details>
        <div class="reader-position">{{ $messages->count() }} {{ \Illuminate\Support\Str::plural('message', $messages->count()) }}</div>
        <a href="{{ $previousUrl ?: '#' }}" class="reader-icon-button {{ $previousUrl ? '' : 'disabled' }}" title="Newer conversation"><x-icon name="chevron-left" /></a>
        <a href="{{ $nextUrl ?: '#' }}" class="reader-icon-button {{ $nextUrl ? '' : 'disabled' }}" title="Older conversation"><x-icon name="chevron-right" /></a>
    </nav>

    <header class="reader-subject">
        <h1>{{ $thread->subject }}</h1>
        <span>{{ ucfirst($folder) }}</span>
        <button type="button" class="reader-icon-button subject-print" data-print-thread title="Print conversation"><x-icon name="print" /></button>
    </header>
    <div class="u-assist-bar thread-assist" aria-label="U-Assist thread actions">
        <span><x-icon name="sparkles" /> U-Assist</span>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Summarize this thread clearly, including the latest message and the current status.">Summarize</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Draft a precise formal reply to this conversation.">Draft reply</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Explain the latest message in this thread and what I should do next.">Latest</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Extract action items, owners, and deadlines from this thread.">Action items</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Find all dates, deadlines, and time-sensitive requests in this thread.">Deadlines</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Translate the latest message in this thread to French and summarize the key point.">Translate</button>
        <button type="button" data-agent-action data-agent-context-type="thread" data-agent-context-id="{{ $thread->id }}" data-agent-prompt="Find related messages from my authorized mailbox for this thread.">Find related</button>
    </div>
    <div class="thread-stack gmail-thread-stack">
        @foreach($messages as $message)
            @php
                $entry = $message->mailboxEntries->first();
                $isLatest = $message->is($lastMessage);
                $toRecipients = $message->recipients->where('type', 'to');
                $ccRecipients = $message->recipients->where('type', 'cc');
                $bccRecipients = $message->recipients->where('type', 'bcc');
                $replyData = [
                    'mode' => 'Reply',
                    'to' => $message->senderDisplayEmail(),
                    'cc' => '',
                    'bcc' => '',
                    'subject' => $replySubject,
                    'thread_id' => $thread->id,
                    'parent_id' => $message->id,
                    'body' => '',
                ];
                $replyAllData = [
                    'mode' => 'Reply all',
                    'to' => collect([$message->senderDisplayEmail()])
                        ->concat($toRecipients->pluck('email'))
                        ->reject(fn ($email) => in_array(strtolower($email), $selfAddresses, true))
                        ->unique()
                        ->join(', '),
                    'cc' => $ccRecipients->pluck('email')
                        ->reject(fn ($email) => in_array(strtolower($email), $selfAddresses, true))
                        ->unique()
                        ->join(', '),
                    'bcc' => '',
                    'subject' => $replySubject,
                    'thread_id' => $thread->id,
                    'parent_id' => $message->id,
                    'body' => '',
                ];
                $forwardData = [
                    'mode' => 'Forward',
                    'to' => '',
                    'cc' => '',
                    'bcc' => '',
                    'subject' => str_starts_with($thread->subject, 'Fwd:') ? $thread->subject : 'Fwd: '.$thread->subject,
                    'thread_id' => '',
                    'parent_id' => '',
                    'body' => '<br><br><blockquote><strong>Forwarded message</strong><br>From: '.e($message->senderDisplayName()).' &lt;'.e($message->senderDisplayEmail()).'&gt;<br>Date: '.$message->sent_at->format('M j, Y \a\t H:i').'<br>Subject: '.e($message->subject).'<br><br>'.$message->body_html.'</blockquote>',
                ];
                $reactionGroups = $message->reactions->groupBy('emoji');
            @endphp
            <article class="gmail-message {{ $isLatest ? 'expanded' : 'collapsed' }}" data-message-card data-message-id="{{ $message->id }}">
                <header class="gmail-sender-row">
                    <button type="button" class="message-toggle" data-message-toggle aria-expanded="{{ $isLatest ? 'true' : 'false' }}" title="{{ $isLatest ? 'Collapse message' : 'Expand message' }}">
                        <x-user-avatar :user="$message->sender" />
                        <span class="sender-identity">
                            <strong>{{ $message->senderDisplayName() }} @if($message->source === 'external')<span class="outside-badge">Outside sender</span>@endif</strong>
                            <small>&lt;{{ $message->senderDisplayEmail() }}&gt;</small>
                        </span>
                        <span class="collapsed-snippet">{{ \Illuminate\Support\Str::limit($message->body_text, 100) }}</span>
                    </button>
                    <div class="message-meta">
                        <time title="{{ $message->sent_at->format('M j, Y \a\t H:i:s') }}">{{ $message->sent_at->isToday() ? $message->sent_at->format('H:i') : $message->sent_at->format('M j, Y, H:i') }}</time>
                        <form method="POST" action="{{ route('mailbox.update', $entry) }}">@csrf
                            <input type="hidden" name="action" value="{{ $entry->is_starred ? 'unstar' : 'star' }}">
                            <button class="reader-icon-button {{ $entry->is_starred ? 'active' : '' }}" title="{{ $entry->is_starred ? 'Remove star' : 'Star message' }}"><x-icon name="star" /></button>
                        </form>
                        <button type="button" class="reader-icon-button" data-reaction-trigger data-reaction-url="{{ route('messages.reactions.toggle', $message) }}" title="Add reaction"><x-icon name="smile" /></button>
                        <button type="button" class="reader-icon-button" data-inline-action="{{ json_encode($replyData) }}" title="Reply"><x-icon name="reply" /></button>
                        <details class="reader-more message-more">
                            <summary class="reader-icon-button" title="More message actions"><x-icon name="more" /></summary>
                            <div class="reader-menu">
                                <button type="button" data-inline-action="{{ json_encode($replyData) }}"><x-icon name="reply" /> Reply</button>
                                <button type="button" data-inline-action="{{ json_encode($replyAllData) }}"><x-icon name="reply-all" /> Reply all</button>
                                <button type="button" data-inline-action="{{ json_encode($forwardData) }}"><x-icon name="forward" /> Forward</button>
                                <button type="button" data-print-message="{{ $message->id }}"><x-icon name="print" /> Print message</button>
                                @if(($mailLabels ?? collect())->isNotEmpty())
                                    <form method="POST" action="{{ route('mailbox.labels.apply', $entry) }}">@csrf
                                        <select name="label_id">
                                            @foreach($mailLabels as $label)
                                                <option value="{{ $label->id }}">{{ $label->name }}</option>
                                            @endforeach
                                        </select>
                                        <button><x-icon name="tag" /> Apply label</button>
                                    </form>
                                @endif
                                @if($folder !== 'trash')
                                    @include('mailbox.partials.snooze-controls', ['entry' => $entry, 'action' => route('mailbox.update', $entry)])
                                @endif
                                <form method="POST" action="{{ route('mailbox.update', $entry) }}">@csrf
                                    <input type="hidden" name="action" value="trash">
                                    <button data-confirm="Move this message to Trash?"><x-icon name="trash" /> Move to Trash</button>
                                </form>
                            </div>
                        </details>
                    </div>
                </header>

                <div class="message-expanded-content">
                    <details class="recipient-details">
                        <summary>to {{ $toRecipients->pluck('display_name')->join(', ') ?: 'me' }} <x-icon name="chevron-down" /></summary>
                        <div>
                            <p><b>From</b><span>{{ $message->senderDisplayName() }} &lt;{{ $message->senderDisplayEmail() }}&gt;</span></p>
                            <p><b>To</b><span>{{ $toRecipients->pluck('display_name')->join(', ') }}</span></p>
                            @if($ccRecipients->isNotEmpty())<p><b>Cc</b><span>{{ $ccRecipients->pluck('display_name')->join(', ') }}</span></p>@endif
                            @if($message->sender_id === auth()->id() && $bccRecipients->isNotEmpty())<p><b>Bcc</b><span>{{ $bccRecipients->pluck('display_name')->join(', ') }}</span></p>@endif
                            <p><b>Date</b><span>{{ $message->sent_at->format('M j, Y \a\t H:i:s') }}</span></p>
                            <p><b>Subject</b><span>{{ $message->subject }}</span></p>
                        </div>
                    </details>
                    @if($message->sender_id === auth()->id() && $message->externalDelivery)
                        <div class="delivery-status {{ $message->externalDelivery->status }}">{{ $message->externalDelivery->userLabel() }}</div>
                    @endif
                    @if($entry->labels->isNotEmpty())
                        <div class="thread-label-row">
                            @foreach($entry->labels as $label)
                                <form method="POST" action="{{ route('mailbox.labels.remove', [$entry, $label]) }}">
                                    @csrf @method('DELETE')
                                    <button style="--label-color: {{ $label->color }}" title="Remove {{ $label->name }}"><x-icon name="tag" /> {{ $label->name }} ×</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                    <div class="gmail-message-body">{!! $message->body_html !!}</div>

                    @if($message->attachments->isNotEmpty())
                        <div class="gmail-attachments">
                            @foreach($message->attachments as $attachment)
                                @php($previewable = in_array($attachment->mime_type, ['application/pdf', 'image/gif', 'image/jpeg', 'image/png', 'image/webp'], true))
                                <article class="attachment-card">
                                    @if(str_starts_with($attachment->mime_type, 'image/'))
                                        <a href="{{ route('attachments.preview', $attachment) }}" target="_blank" class="attachment-image"><img src="{{ route('attachments.preview', $attachment) }}" alt="{{ $attachment->original_name }}"></a>
                                    @else
                                        <span class="attachment-file-icon"><x-icon name="file" /></span>
                                    @endif
                                    <div><strong>{{ $attachment->original_name }}</strong><small>{{ number_format($attachment->size / 1024, 0) }} KB</small></div>
                                    <span class="attachment-actions">
                                        @if($previewable)<a href="{{ route('attachments.preview', $attachment) }}" target="_blank" title="Preview"><x-icon name="eye" /></a>@endif
                                        <a href="{{ route('attachments.download', $attachment) }}" title="Download"><x-icon name="download" /></a>
                                    </span>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    @if($reactionGroups->isNotEmpty())
                        <div class="message-reactions">
                            @foreach($reactionGroups as $emoji => $reactions)
                                <form method="POST" action="{{ route('messages.reactions.toggle', $message) }}">@csrf
                                    <input type="hidden" name="emoji" value="{{ $emoji }}">
                                    <button class="{{ $reactions->contains('user_id', auth()->id()) ? 'mine' : '' }}" title="{{ $reactions->pluck('user.name')->filter()->join(', ') }}">{{ $emoji }} <span>{{ $reactions->count() }}</span></button>
                                </form>
                            @endforeach
                        </div>
                    @endif

                    <footer class="gmail-message-actions" data-tour-target="opened-mail">
                        <button type="button" class="gmail-action-button" data-inline-action="{{ json_encode($replyData) }}"><x-icon name="reply" /> Reply</button>
                        <button type="button" class="gmail-action-button" data-inline-action="{{ json_encode($forwardData) }}"><x-icon name="forward" /> Forward</button>
                        <button type="button" class="gmail-action-button icon-only" data-reaction-trigger data-reaction-url="{{ route('messages.reactions.toggle', $message) }}" title="Add reaction"><x-icon name="smile" /></button>
                    </footer>
                </div>
            </article>
        @endforeach
    </div>

    <section class="inline-reply-card" id="inlineReplyCard" hidden>
        <form method="POST" action="{{ route('messages.send') }}" enctype="multipart/form-data" id="inlineReplyForm">
            @csrf
            <input type="hidden" name="draft_id" id="inlineDraftId">
            <input type="hidden" name="thread_id" id="inlineThreadId">
            <input type="hidden" name="parent_id" id="inlineParentId">
            <input type="hidden" name="subject" id="inlineSubject">
            <input type="hidden" name="body_html" id="inlineBodyHtml">
            <header>
                <strong id="inlineReplyMode">Reply</strong>
                <button type="button" class="reader-icon-button" data-close-inline-reply title="Close reply"><x-icon name="close" /></button>
            </header>
            <div class="inline-recipient-fields">
                <label><span>To</span><input name="to" id="inlineTo" required autocomplete="off"></label>
                <label><span>Cc</span><input name="cc" id="inlineCc"></label>
                <label><span>Bcc</span><input name="bcc" id="inlineBcc"></label>
            </div>
            <div id="inlineRecipientSuggestions" class="recipient-suggestions inline-suggestions"></div>
            <div class="inline-editor" id="inlineEditor" contenteditable="true" data-placeholder="Write a reply..."></div>
            <div class="inline-reply-toolbar">
                <button type="button" data-inline-format="bold" title="Bold"><b>B</b></button>
                <button type="button" data-inline-format="italic" title="Italic"><i>I</i></button>
                <button type="button" data-inline-format="underline" title="Underline"><u>U</u></button>
                <button type="button" data-emoji-trigger data-emoji-target="#inlineEditor" title="Insert emoji"><x-icon name="smile" /></button>
                <label title="Attach files"><x-icon name="paperclip" /><input type="file" name="attachments[]" multiple hidden></label>
            </div>
            <footer>
                <button class="send-button" type="submit"><x-icon name="send" /> Send</button>
                <span id="inlineDraftStatus">Replies remain in this conversation.</span>
                <button type="button" class="discard-button" data-discard-inline><x-icon name="delete" /> Discard</button>
            </footer>
        </form>
    </section>
</section>

@endsection
