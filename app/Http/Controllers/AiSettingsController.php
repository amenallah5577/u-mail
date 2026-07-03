<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Services\LocalAgentEngineService;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function index(Request $request, LocalAgentEngineService $engine)
    {
        return view('admin.ai-settings', [
            'setting' => AiSetting::current(),
            'connectionStatus' => $request->boolean('check') ? $engine->connectionStatus() : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'local_endpoint' => ['nullable', 'url', 'max:255'],
            'local_model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:\\-]+$/'],
        ]);
        $enabled = $request->boolean('enabled');

        AiSetting::current()->update([
            'enabled' => $enabled,
            'provider' => $enabled ? 'local' : 'none',
            'local_endpoint' => $data['local_endpoint'] ?: config('ai.local_endpoint'),
            'local_model' => $data['local_model'] ?: config('ai.local_model'),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', $enabled ? 'U-Assist local agent engine is enabled.' : 'U-Assist local agent engine is turned off.');
    }
}
