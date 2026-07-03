@props(['name'])
<svg {{ $attributes->class('ui-icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('inbox')
            <path d="M4 4h16v14H4z"/><path d="M4 13h4l2 3h4l2-3h4"/>
            @break
        @case('star')
            <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>
            @break
        @case('send')
            <path d="m21 3-7.5 18-3.8-7.7L2 9.5z"/><path d="M21 3 9.7 13.3"/>
            @break
        @case('draft')
            <path d="M5 3h10l4 4v14H5z"/><path d="M14 3v5h5M8 13h8M8 17h6"/>
            @break
        @case('archive')
            <path d="M4 8h16v12H4zM3 4h18v4H3zM9 12h6"/>
            @break
        @case('trash')
            <path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"/>
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
            @break
        @case('key')
            <circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 3h3v3M14 6l3 3"/>
            @break
        @case('activity')
            <path d="M3 12h4l2-7 4 14 2-7h6"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/>
            @break
        @case('shield')
            <path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>
            @break
        @case('logout')
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
            @break
        @case('chevron-up')
            <path d="m6 15 6-6 6 6"/>
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break
        @case('search')
            <circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>
            @break
        @case('camera')
            <path d="M4 7h4l2-3h4l2 3h4v13H4z"/><circle cx="12" cy="13" r="4"/>
            @break
        @case('lock')
            <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>
            @break
        @case('close')
            <path d="M6 6l12 12M18 6 6 18"/>
            @break
        @case('paperclip')
            <path d="m21.4 11.6-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7l-9.6 9.6a2 2 0 0 1-2.8-2.8l8.9-8.9"/>
            @break
        @case('list')
            <path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>
            @break
        @case('delete')
            <path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14"/>
            @break
        @case('arrow-right')
            <path d="M5 12h14M13 6l6 6-6 6"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('globe')
            <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>
            @break
        @case('back')
            <path d="M19 12H5M11 18l-6-6 6-6"/>
            @break
        @case('mail')
            <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
            @break
        @case('tag')
            <path d="M20 10.5 13.5 4H5v8.5L11.5 19a2 2 0 0 0 2.8 0l5.7-5.7a2 2 0 0 0 0-2.8z"/><path d="M8 8h.01"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('sparkles')
            <path d="m12 3 1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8zM5 14l.8 2.2L8 17l-2.2.8L5 20l-.8-2.2L2 17l2.2-.8z"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
            @break
        @case('moon')
            <path d="M21 13.2A8.6 8.6 0 1 1 10.8 3a6.8 6.8 0 0 0 10.2 10.2z"/>
            @break
        @case('sidebar')
            <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M13 9l3 3-3 3"/>
            @break
        @case('sliders')
            <path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>
            @break
        @case('more')
            <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
            @break
        @case('chevron-left')
            <path d="m15 18-6-6 6-6"/>
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6"/>
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6"/>
            @break
        @case('reply')
            <path d="m9 17-6-5 6-5v3h4a8 8 0 0 1 8 8v1a10 10 0 0 0-8-6H9z"/>
            @break
        @case('reply-all')
            <path d="m7 17-5-5 5-5v3h5a8 8 0 0 1 8 8v1a10 10 0 0 0-8-6H7zM12 7l-3-3M12 7 9 10"/>
            @break
        @case('forward')
            <path d="m15 17 6-5-6-5v3h-4a8 8 0 0 0-8 8v1a10 10 0 0 1 8-6h4z"/>
            @break
        @case('print')
            <path d="M7 8V3h10v5M7 17H5a3 3 0 0 1-3-3v-3a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v3a3 3 0 0 1-3 3h-2"/><path d="M7 14h10v7H7z"/>
            @break
        @case('smile')
            <circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>
            @break
        @case('download')
            <path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>
            @break
        @case('eye')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/>
            @break
        @case('external')
            <path d="M14 3h7v7M10 14 21 3M21 14v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h6"/>
            @break
        @case('file')
            <path d="M5 3h10l4 4v14H5zM14 3v5h5"/>
            @break
    @endswitch
</svg>
