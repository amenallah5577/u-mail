<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingTutorialController extends Controller
{
    public const CURRENT_VERSION = 1;

    public function complete(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['finish', 'skip'])],
        ]);

        $user = $request->user();
        abort_if($user->isAdmin(), 403);

        $user->forceFill([
            'onboarding_tour_completed_at' => now(),
            'onboarding_tour_version' => self::CURRENT_VERSION,
        ])->save();

        return response()->json([
            'completed' => true,
            'action' => $data['action'],
            'version' => self::CURRENT_VERSION,
        ]);
    }
}
