<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    public function index(Request $request)
    {
        $query = trim((string) $request->query('q'));
        $canSearch = mb_strlen($query) >= 2;
        $users = User::where('status', 'active')
            ->where('role', 'employee');

        if ($canSearch) {
            $users->where(function ($builder) use ($query) {
                $builder->where('name', 'like', "%{$query}%")
                    ->orWhere('public_email', 'like', "%{$query}%");
            });
        } else {
            $users->whereRaw('1 = 0');
        }

        if ($request->expectsJson()) {
            if (! $canSearch) {
                return response()->json([]);
            }

            return response()->json(
                (clone $users)->orderBy('name')
                    ->limit(8)
                    ->get(['id', 'name', 'public_email'])
                    ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->public_email])
            );
        }

        return view('directory.index', [
            'users' => $users->orderBy('name')->paginate(30)->withQueryString(),
            'search' => $query,
            'canSearch' => $canSearch,
        ]);
    }
}
