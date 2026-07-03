<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function update(Request $request, SecurityAuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\s-]+$/'],
            'photo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(2048)],
        ], [
            'phone.regex' => 'Use a valid phone number containing only numbers and phone symbols.',
        ]);

        $user = $request->user();
        $phone = $data['phone'] ?? null;
        $metadata = ['name_changed' => $user->name !== $data['name'], 'phone_changed' => $user->phone !== $phone];
        $user->fill(['name' => $data['name'], 'phone' => $phone ?: null]);
        $oldPhoto = null;

        if ($request->hasFile('photo')) {
            $oldPhoto = $user->profile_photo_path;
            $user->profile_photo_path = $request->file('photo')->store('profile-photos/'.$user->id, 'local');
            $metadata['photo_changed'] = true;
        }

        $user->save();
        if ($oldPhoto) {
            Storage::disk('local')->delete($oldPhoto);
        }
        $audit->record('profile.updated', $user, $user, $metadata, $request);

        return back()->with('status', 'Profile information updated.');
    }

    public function photo(User $user)
    {
        abort_unless($user->profile_photo_path && Storage::disk('local')->exists($user->profile_photo_path), 404);

        return Storage::disk('local')->response($user->profile_photo_path, 'profile-photo');
    }

    public function destroyPhoto(Request $request, SecurityAuditService $audit)
    {
        $user = $request->user();
        if ($user->profile_photo_path) {
            Storage::disk('local')->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);
            $audit->record('profile.photo_removed', $user, $user, request: $request);
        }

        return back()->with('status', 'Profile photo removed.');
    }
}
