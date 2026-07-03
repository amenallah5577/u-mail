@extends('layouts.app')
@section('title', 'Directory')
@section('content')
<section class="directory-page">
    <div class="page-heading">
        <div><p class="eyebrow">WORKSPACE</p><h1>Employee directory</h1></div>
        <span class="page-count"><b>{{ $users->total() }}</b> results</span>
    </div>
    <form class="account-finder" method="GET" action="{{ route('directory') }}">
        <label class="account-search">
            <x-icon name="search" />
            <span><small>Find a coworker</small><input name="q" value="{{ $search }}" placeholder="Name or U-Mail address" autocomplete="off"></span>
        </label>
        <button class="finder-submit" type="submit"><x-icon name="search" /><span>Search</span></button>
        @if($search)<a class="finder-clear" href="{{ route('directory') }}"><x-icon name="close" /><span>Clear</span></a>@endif
    </form>
    <div class="directory-grid">
        @if(! $canSearch)
            <div class="empty-state compact-empty"><x-icon name="search" /><h2>Search required</h2><p>Enter at least two characters to look up an active U-Mail employee.</p></div>
        @else
            @forelse($users as $user)
            <article class="directory-card">
                <x-user-avatar :user="$user" />
                <div>
                    <strong>{{ $user->name }}</strong>
                    <span>{{ $user->public_email }}</span>
                    <small>Active U-Mail employee</small>
                </div>
            </article>
            @empty
            <div class="empty-state compact-empty"><x-icon name="search" /><h2>No coworkers found</h2><p>Try another name or U-Mail address.</p></div>
            @endforelse
        @endif
    </div>
    @if($canSearch)<div class="pagination">{{ $users->links() }}</div>@endif
</section>
@endsection
