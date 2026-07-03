@props(['user', 'large' => false])
<span {{ $attributes->class(['avatar', 'avatar-large' => $large]) }}>
    @if($user?->profile_photo_path)
        <img src="{{ route('profile.photo', $user) }}" alt="{{ $user->name }}">
    @else
        <x-icon name="user" />
    @endif
</span>
