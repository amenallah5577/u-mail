@php
    $onboardingTourVersion = \App\Http\Controllers\OnboardingTutorialController::CURRENT_VERSION;
    $onboardingTourUser = auth()->user();
    $onboardingTourAvailable = $onboardingTourUser?->isActive() && ! $onboardingTourUser->isAdmin();
    $onboardingTourAutoStart = $onboardingTourAvailable && (int) ($onboardingTourUser->onboarding_tour_version ?? 0) < $onboardingTourVersion;
@endphp
<!DOCTYPE html>
<html lang="en" data-user-id="{{ auth()->id() }}" data-theme-preference="{{ auth()->check() ? (auth()->user()->theme_preference ?: 'light') : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="auth-login-url" content="{{ auth()->user()->isAdmin() ? route('admin.login') : route('login') }}">
    <meta name="auth-session-check-url" content="{{ route('auth.session') }}">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>@yield('title', 'Mailbox') - U-Mail</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <script src="/js/history-guard.js"></script>
    <script src="/js/sidebar-preload.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-user-id="{{ auth()->id() }}" data-login-url="{{ auth()->user()->isAdmin() ? route('admin.login') : route('login') }}" data-session-check-url="{{ route('auth.session') }}" data-mail-notifications-enabled="{{ auth()->user()->mail_notifications_enabled ? 'true' : 'false' }}" data-agent-run-url="{{ route('agent.runs.store') }}" data-onboarding-tour-available="{{ $onboardingTourAvailable ? 'true' : 'false' }}" data-onboarding-tour-auto-start="{{ $onboardingTourAutoStart ? 'true' : 'false' }}" data-onboarding-tour-version="{{ $onboardingTourVersion }}" data-onboarding-tour-complete-url="{{ route('tutorial.onboarding.complete') }}">
<div class="app-shell">
    <aside class="sidebar {{ auth()->user()->isOwner() ? 'owner-sidebar' : '' }}" id="sidebar">
        <div class="sidebar-brand-row">
            <a class="brand" href="{{ route('mailbox') }}">
                <img class="brand-logo" src="/images/utica-jendouba-logo.png" alt="UTICA">
                <span><strong>U-Mail</strong><small>Jendouba workspace</small></span>
            </a>
            <button class="sidebar-collapse-button" type="button" data-sidebar-collapse aria-label="Reduce sidebar" title="Reduce sidebar"><x-icon name="sidebar" /></button>
        </div>
        <div class="workspace-chip"><span></span> UTICA email workspace</div>
        <button class="compose-button" type="button" data-open-compose data-tour-target="compose" title="Compose"><x-icon name="plus" /> <span>Compose</span></button>
        <button class="ask-mail-button" type="button" data-agent-toggle title="U-Assist"><x-icon name="sparkles" /> <span>U-Assist</span></button>
        <nav class="mail-nav" data-tour-target="folders">
            @foreach([
                'inbox' => ['Inbox', $mailCounts['unread'] ?? 0],
                'starred' => ['Starred', $mailCounts['starred'] ?? 0],
                'sent' => ['Sent', $mailCounts['sent'] ?? 0],
                'drafts' => ['Drafts', $mailCounts['drafts'] ?? 0],
                'scheduled' => ['Scheduled', $mailCounts['scheduled'] ?? 0],
                'archive' => ['Archive', $mailCounts['archive'] ?? 0],
                'trash' => ['Trash', $mailCounts['trash'] ?? 0],
            ] as $key => [$label, $count])
                <a class="{{ ($folder ?? '') === $key ? 'active' : '' }}" href="{{ route('mailbox.folder', $key) }}" title="{{ $label }}" @if($key === 'inbox') data-tour-target="inbox" @endif>
                    <span class="nav-icon"><x-icon :name="['inbox'=>'inbox','starred'=>'star','sent'=>'send','drafts'=>'draft','scheduled'=>'clock','archive'=>'archive','trash'=>'trash'][$key]" /></span>
                    <span>{{ $label }}</span><b data-count="{{ $key }}">{{ $count ?: '' }}</b>
                </a>
            @endforeach
        </nav>
        @if(($mailLabels ?? collect())->isNotEmpty())
            <div class="nav-section">Labels</div>
            <nav class="mail-nav label-nav">
                @foreach($mailLabels as $label)
                    <a href="{{ route('mailbox.folder', ['folder' => 'inbox', 'label' => $label->id]) }}" title="{{ $label->name }}">
                        <span class="nav-icon label-dot" style="--label-color: {{ $label->color }}"><x-icon name="tag" /></span><span>{{ $label->name }}</span><b>{{ $label->mailbox_entries_count ?: '' }}</b>
                    </a>
                @endforeach
            </nav>
        @endif
        <div class="nav-section">Workspace</div>
        <nav class="mail-nav">
            <a href="{{ route('calendar.index') }}" class="{{ request()->routeIs('calendar.*') ? 'active' : '' }}" title="Calendar">
                <span class="nav-icon"><x-icon name="calendar" /></span><span>Calendar</span>
            </a>
            <a href="{{ route('directory') }}" class="{{ request()->routeIs('directory') ? 'active' : '' }}" title="Directory">
                <span class="nav-icon"><x-icon name="users" /></span><span>Directory</span>
            </a>
            <a href="{{ route('templates.index') }}" class="{{ request()->routeIs('templates.*') ? 'active' : '' }}" title="Templates">
                <span class="nav-icon"><x-icon name="draft" /></span><span>Templates</span>
            </a>
        </nav>
        @if(auth()->user()->isAdmin())
            <div class="nav-section">Administration</div>
            <nav class="mail-nav">
                <a href="{{ route('admin.employees') }}" class="{{ request()->routeIs('admin.employees*') ? 'active' : '' }}" title="Employees">
                    <span class="nav-icon"><x-icon name="users" /></span><span>Employees</span>
                </a>
                <a href="{{ route('admin.ai-settings') }}" class="{{ request()->routeIs('admin.ai-settings*') ? 'active' : '' }}" title="Local agent engine">
                    <span class="nav-icon"><x-icon name="sparkles" /></span><span>Agent engine</span>
                </a>
            </nav>
        @endif
        @if(auth()->user()->isOwner())
            <div class="nav-section">Owner</div>
            <nav class="mail-nav">
                <a href="{{ route('owner.credentials') }}" class="{{ request()->routeIs('owner.credentials*') ? 'active' : '' }}" title="Credentials">
                    <span class="nav-icon"><x-icon name="key" /></span><span>Credentials</span>
                </a>
                <a href="{{ route('owner.security-events') }}" class="{{ request()->routeIs('owner.security-events') ? 'active' : '' }}" title="Security events">
                    <span class="nav-icon"><x-icon name="activity" /></span><span>Security events</span>
                </a>
            </nav>
        @endif
        <div class="sidebar-account" data-profile-menu-wrap>
            <div class="profile-menu" data-profile-menu aria-hidden="true" inert>
                <div class="profile-menu-head">
                    <x-user-avatar :user="auth()->user()" />
                    <span><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->mailAddress() }}</small></span>
                </div>
                <a href="{{ route('profile.show') }}"><x-icon name="user" /><b>Show profile</b></a>
                <a href="{{ route('account.settings') }}" data-tour-target="account-settings"><x-icon name="settings" /><b>Account settings</b></a>
                <a href="{{ route('security.settings') }}"><x-icon name="shield" /><b>Security</b></a>
                <form method="POST" action="{{ route('logout') }}" data-logout-form>@csrf<button><x-icon name="logout" /><b>Sign out</b></button></form>
            </div>
            <button class="sidebar-user" type="button" data-profile-menu-button data-tour-target="profile-menu" aria-expanded="false">
                <x-user-avatar :user="auth()->user()" />
                <span><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->mailAddress() }}</small></span>
                <x-icon name="chevron-up" class="profile-chevron" />
            </button>
        </div>
    </aside>

    <main class="main-panel">
        <header class="topbar">
            <button class="menu-button" type="button" data-menu aria-label="Open navigation"><x-icon name="menu" /></button>
            @if(request()->routeIs('admin.employees'))
                <div class="topbar-context"><x-icon name="users" /><span><strong>Employee management</strong><small>Account directory and approvals</small></span></div>
            @elseif(request()->routeIs('owner.credentials'))
                <div class="topbar-context"><x-icon name="key" /><span><strong>Credential registry</strong><small>Owner-only account access</small></span></div>
            @elseif(request()->routeIs('calendar.*'))
                <div class="topbar-context"><x-icon name="calendar" /><span><strong>Calendar</strong><small>Shared and personal events</small></span></div>
            @elseif(request()->routeIs('directory'))
                <div class="topbar-context"><x-icon name="users" /><span><strong>Directory</strong><small>Active U-Mail users</small></span></div>
            @elseif(request()->routeIs('templates.*'))
                <div class="topbar-context"><x-icon name="draft" /><span><strong>Templates</strong><small>Saved message snippets</small></span></div>
            @elseif(request()->routeIs('admin.ai-settings*'))
                <div class="topbar-context"><x-icon name="sparkles" /><span><strong>Local agent engine</strong><small>Private U-Assist support</small></span></div>
            @else
                @php($layoutFilters = $mailFilters ?? [
                    'q' => request('q', ''),
                    'from' => request('from', ''),
                    'to' => request('to', ''),
                    'subject' => request('subject', ''),
                    'exact' => request('exact', ''),
                    'exclude' => request('exclude', ''),
                    'read_status' => request('read_status', 'any'),
                    'starred' => request('starred', 'any'),
                    'attachments' => request('attachments', 'any'),
                    'date_from' => request('date_from', ''),
                    'date_to' => request('date_to', ''),
                    'size_operator' => request('size_operator', 'none'),
                    'size_value' => request('size_value', ''),
                    'size_unit' => request('size_unit', 'kb'),
                ])
                <form class="search-form advanced-search-form" action="{{ route('mailbox.folder', $folder ?? 'inbox') }}" data-search-form data-tour-target="search">
                    <span><x-icon name="search" /></span>
                    <input name="q" value="{{ $layoutFilters['q'] ?? '' }}" placeholder="Search mail, people, or subjects">
                    <button class="search-filter-button" type="button" data-search-filter-toggle aria-expanded="{{ ($activeFilters ?? []) ? 'true' : 'false' }}" title="Search filters"><x-icon name="sliders" /><span>Filters</span></button>
                    <button class="search-submit-button" type="submit" title="Search"><x-icon name="search" /></button>
                    <div class="search-filter-panel {{ ($activeFilters ?? []) ? 'open' : '' }}" data-search-filter-panel>
                        <label>From<input name="from" value="{{ $layoutFilters['from'] ?? '' }}" placeholder="Sender name or address"></label>
                        <label>To<input name="to" value="{{ $layoutFilters['to'] ?? '' }}" placeholder="Recipient name or address"></label>
                        <label>Subject<input name="subject" value="{{ $layoutFilters['subject'] ?? '' }}" placeholder="Words in the subject"></label>
                        <label>Exact words<input name="exact" value="{{ $layoutFilters['exact'] ?? '' }}" placeholder="Exact phrase"></label>
                        <label>Does not include<input name="exclude" value="{{ $layoutFilters['exclude'] ?? '' }}" placeholder="Words to exclude"></label>
                        <label>Status
                            <select name="read_status">
                                @foreach(['any' => 'Any', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($layoutFilters['read_status'] ?? 'any') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Star
                            <select name="starred">
                                @foreach(['any' => 'Any', 'starred' => 'Starred', 'unstarred' => 'Not starred'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($layoutFilters['starred'] ?? 'any') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Attachments
                            <select name="attachments">
                                @foreach(['any' => 'Any', 'yes' => 'Has attachments', 'no' => 'No attachments'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($layoutFilters['attachments'] ?? 'any') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Date from<input type="date" name="date_from" value="{{ $layoutFilters['date_from'] ?? '' }}"></label>
                        <label>Date to<input type="date" name="date_to" value="{{ $layoutFilters['date_to'] ?? '' }}"></label>
                        <label>Size
                            <span class="size-filter-row">
                                <select name="size_operator">
                                    @foreach(['none' => 'Any', 'larger' => 'Larger than', 'smaller' => 'Smaller than'] as $value => $label)
                                        <option value="{{ $value }}" @selected(($layoutFilters['size_operator'] ?? 'none') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="number" min="1" max="100000" name="size_value" value="{{ $layoutFilters['size_value'] ?? '' }}" placeholder="Size">
                                <select name="size_unit">
                                    <option value="kb" @selected(($layoutFilters['size_unit'] ?? 'kb') === 'kb')>KB</option>
                                    <option value="mb" @selected(($layoutFilters['size_unit'] ?? 'kb') === 'mb')>MB</option>
                                </select>
                            </span>
                        </label>
                        <footer>
                            <a class="soft-button" href="{{ route('mailbox.folder', $folder ?? 'inbox') }}">Clear filters</a>
                            <button class="primary-button small" type="submit">Search</button>
                        </footer>
                    </div>
                </form>
            @endif
            <div class="topbar-actions"><strong>UTICA Jendouba</strong><span><i class="online-dot"></i> Secure email</span></div>
        </header>

        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</div>

<dialog class="compose-dialog" id="composeDialog">
    <form method="POST" action="{{ route('messages.send') }}" enctype="multipart/form-data" id="composeForm">
        @csrf
        <input type="hidden" name="draft_id" id="draftId">
        <input type="hidden" name="thread_id" id="threadId">
        <input type="hidden" name="parent_id" id="parentId">
        <div class="compose-head">
            <span><img src="/images/utica-jendouba-logo.png" alt=""><span><strong>New message</strong><small>U-Mail · UTICA Jendouba</small></span></span>
            <button type="button" data-close-compose aria-label="Close composer"><x-icon name="close" /></button>
        </div>
        <div class="compose-workspace">
            <div class="compose-primary">
                <div class="compose-fields">
                    <div class="recipient-line">
                        <label for="composeTo">To</label><input name="to" id="composeTo" autocomplete="off" placeholder="U-Mail or any email address">
                        <button type="button" data-toggle-copy aria-expanded="false">Cc · Bcc</button>
                    </div>
                    <div class="copy-fields" id="copyFields">
                        <div class="recipient-line"><label for="composeCc">Cc</label><input name="cc" id="composeCc" placeholder="Add Cc recipients"></div>
                        <div class="recipient-line"><label for="composeBcc">Bcc</label><input name="bcc" id="composeBcc" placeholder="Add Bcc recipients"></div>
                    </div>
                    <input class="compose-subject" name="subject" id="composeSubject" placeholder="Subject">
                    <div class="compose-productivity-row">
                        <label>Template
                            <select data-template-picker>
                                <option value="">Choose saved template</option>
                                @foreach($messageTemplates ?? [] as $template)
                                    <option value="{{ $template->id }}" data-subject="{{ e($template->subject) }}" data-body="{{ e($template->body_html) }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Schedule send<input type="datetime-local" name="scheduled_at"></label>
                    </div>
                </div>
                <div id="recipientSuggestions" class="recipient-suggestions"></div>
                <div class="composer-toolbar">
                    <span>Formatting</span>
                    <button type="button" data-format="bold" title="Bold"><b>B</b></button>
                    <button type="button" data-format="italic" title="Italic"><i>I</i></button>
                    <button type="button" data-format="underline" title="Underline"><u>U</u></button>
                    <button type="button" data-format="insertUnorderedList" title="Bulleted list"><x-icon name="list" /></button>
                    <button type="button" data-emoji-trigger data-emoji-target="#composeBody" title="Insert emoji"><x-icon name="smile" /></button>
                    <button type="button" class="agent-formalize-button" data-agent-compose-prompt="Improve this email and make it clearer while preserving the meaning. Return the complete improved draft." title="Improve this draft"><x-icon name="sparkles" /> Improve</button>
                    <button type="button" class="agent-formalize-button" data-agent-formalize-compose title="Make this draft formal"><x-icon name="sparkles" /> Formal</button>
                    <button type="button" class="agent-formalize-button" data-agent-compose-prompt="Shorten this email while keeping it professional, clear, and complete. Return the complete shortened draft." title="Shorten this draft"><x-icon name="sparkles" /> Shorten</button>
                    <button type="button" class="agent-formalize-button" data-agent-compose-prompt="Check this email before sending and correct wording, tone, structure, and clarity problems directly in a complete ready-to-send draft. Also preserve recipient, subject, names, dates, and meaning." title="Check and correct before sending"><x-icon name="shield" /> Check</button>
                </div>
                <div class="compose-body" id="composeBody" contenteditable="true" data-placeholder="Write a message..."></div>
                <textarea name="body_html" id="bodyHtml" hidden></textarea>
                <div class="attachment-summary" data-attachment-summary hidden><x-icon name="paperclip" /><span data-attachment-text></span></div>
                <div class="compose-foot">
                    <button class="send-button" type="submit"><x-icon name="send" /> Send</button>
                    <label class="attach-button" title="Attach files"><x-icon name="paperclip" /><span>Attach</span><input type="file" name="attachments[]" multiple hidden data-compose-attachments></label>
                    <span id="draftStatus">Not saved</span>
                    <button class="discard-button" type="button" data-discard title="Discard draft"><x-icon name="delete" /><span>Discard</span></button>
                </div>
            </div>
        </div>
    </form>
</dialog>

<aside class="agent-panel" data-agent-panel hidden aria-label="U-Assist panel">
    <header>
        <span><x-icon name="sparkles" /><span><strong>U-Assist</strong><small>Replies, deadlines, follow-ups, and mailbox search</small></span></span>
        <button type="button" data-agent-close aria-label="Close U-Assist"><x-icon name="close" /></button>
    </header>
    <form data-agent-form>
        <label for="agentPrompt">What do you need?</label>
        <textarea id="agentPrompt" name="prompt" rows="4" maxlength="2000" placeholder="Example: What unread messages need a reply?"></textarea>
        <div class="agent-examples">
            <button type="button" data-agent-example="What unread messages need a reply?">Unread needing reply</button>
            <button type="button" data-agent-example="Find the invoice email from last week.">Find a message</button>
            <button type="button" data-agent-example="Summarize this thread.">Summarize thread</button>
            <button type="button" data-agent-example="Draft a formal reply to this conversation.">Draft reply</button>
        </div>
        <button class="primary-button small" type="submit">Ask</button>
    </form>
    <div class="agent-status" data-agent-status>Ask for a summary, reply draft, deadline list, follow-up plan, or message search.</div>
    <div class="agent-results" data-agent-results></div>
</aside>
<div class="agent-panel-backdrop" data-agent-backdrop hidden></div>

<dialog class="confirm-dialog" id="confirmDialog" aria-labelledby="confirmDialogTitle" aria-describedby="confirmDialogMessage">
    <div class="confirm-dialog-accent"></div>
    <div class="confirm-dialog-content">
        <div class="confirm-dialog-brand">
            <img src="/images/utica-jendouba-logo.png" alt="">
            <span><strong>U-Mail</strong><small>Confirm action</small></span>
        </div>
        <div class="confirm-dialog-copy">
            <p class="eyebrow">PLEASE CONFIRM</p>
            <h2 id="confirmDialogTitle">Continue with this action?</h2>
            <p id="confirmDialogMessage">Review this action before continuing.</p>
        </div>
        <div class="confirm-dialog-actions">
            <button class="soft-button" type="button" data-confirm-cancel>Cancel</button>
            <button class="confirm-action-button" type="button" data-confirm-accept>Continue</button>
        </div>
    </div>
</dialog>

<div class="emoji-picker" id="emojiPicker" hidden data-emojis="{{ json_encode(config('mailbox.emojis')) }}" aria-label="Emoji picker">
    <header><strong>Choose an emoji</strong><button type="button" data-close-emoji aria-label="Close emoji picker"><x-icon name="close" /></button></header>
    <div class="emoji-grid"></div>
</div>
@if($onboardingTourAvailable)
    <div class="onboarding-tour" data-onboarding-tour hidden>
        <div class="onboarding-tour-backdrop"></div>
        <div class="onboarding-tour-spotlight" data-onboarding-spotlight></div>
        <section class="onboarding-tour-card" data-onboarding-card role="dialog" aria-modal="true" aria-labelledby="onboardingTourTitle" aria-describedby="onboardingTourBody">
            <div class="onboarding-tour-bot">
                <img src="/images/utica-jendouba-logo.png" alt="">
                <span><strong>U-Mail guide</strong><small data-onboarding-progress>Step 1 of 7</small></span>
            </div>
            <h2 id="onboardingTourTitle" data-onboarding-title>Welcome to U-Mail</h2>
            <p id="onboardingTourBody" data-onboarding-body>Let us take a quick tour of your workspace.</p>
            <div class="onboarding-tour-actions">
                <button class="soft-button small" type="button" data-onboarding-skip>Skip</button>
                <span>
                    <button class="soft-button small" type="button" data-onboarding-back>Back</button>
                    <button class="primary-button small" type="button" data-onboarding-next>Next</button>
                </span>
            </div>
        </section>
    </div>
@endif
</body>
</html>
