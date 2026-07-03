@extends('layouts.app')
@section('title', 'Security Events')
@section('content')
<section class="security-page">
    <div class="page-heading"><div><p class="eyebrow">OWNER ACCESS ONLY</p><h1>Security events</h1></div><span class="page-count"><b>{{ $events->total() }}</b> recorded</span></div>
    <div class="panel audit-list">
        @forelse($events as $event)
            <article class="audit-row">
                <div><strong>{{ $event->event }}</strong><small>{{ $event->created_at->format('M j, Y H:i:s') }}</small></div>
                <span>{{ $event->actor?->mailAddress() ?? 'Unauthenticated' }}</span>
                <span>{{ $event->targetUser?->mailAddress() ?? 'No target' }}</span>
                <span>{{ $event->ip_address ?? 'Unknown IP' }}</span>
            </article>
        @empty
            <div class="empty-state"><h2>No security events yet</h2></div>
        @endforelse
        <div class="pagination">{{ $events->links() }}</div>
    </div>
</section>
@endsection
