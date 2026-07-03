<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;

class SecurityEventController extends Controller
{
    public function index(Request $request)
    {
        $events = SecurityEvent::with(['actor', 'targetUser'])->latest()->paginate(50);

        return view('owner.security-events', [
            'events' => $events,
        ]);
    }
}
