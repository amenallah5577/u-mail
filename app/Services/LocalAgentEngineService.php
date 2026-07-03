<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class LocalAgentEngineService
{
    public function setting(): AiSetting
    {
        return AiSetting::current();
    }

    public function connectionStatus(): array
    {
        $setting = $this->setting();
        if (! $setting->enabled || $setting->provider !== 'local') {
            return ['ok' => false, 'message' => 'The local agent engine is off. Turn it on after Ollama and Llama 3.2 are ready.'];
        }

        try {
            $response = Http::timeout(5)->get($this->endpoint($setting).'/api/tags');
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Could not reach the local agent engine.'];
        }

        if (! $response->ok()) {
            return ['ok' => false, 'message' => 'The local agent engine replied, but it is not ready yet.'];
        }

        $model = $this->model($setting);
        $models = collect($response->json('models', []))
            ->map(fn ($item) => strtolower((string) ($item['name'] ?? $item['model'] ?? '')));

        if ($models->isNotEmpty() && ! $models->contains(strtolower($model)) && ! $models->contains(strtolower($model).':latest')) {
            return ['ok' => false, 'message' => "The local agent engine is running, but the {$model} model was not found."];
        }

        return ['ok' => true, 'message' => 'The local agent engine is ready.'];
    }

    public function generateJson(string $systemPrompt, array $payload, int $timeoutSeconds = 55): array
    {
        $setting = $this->setting();
        if (! $setting->enabled || $setting->provider !== 'local') {
            throw new RuntimeException('The local agent engine is off.');
        }

        $prompt = $systemPrompt."\n\nRequest JSON:\n".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout(5)
                ->acceptJson()
                ->post($this->endpoint($setting).'/api/generate', [
                    'model' => $this->model($setting),
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json',
                    'keep_alive' => '30m',
                    'options' => [
                        'temperature' => 0.12,
                        'top_p' => 0.85,
                        'repeat_penalty' => 1.1,
                        'num_predict' => 1400,
                    ],
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('The local agent engine is unavailable.', previous: $exception);
        }

        if (! $response->ok()) {
            throw new RuntimeException('The local agent engine returned an error.');
        }

        $decoded = $this->decodeJson((string) $response->json('response', ''));
        if (! is_array($decoded)) {
            throw new RuntimeException('The local agent engine returned invalid JSON.');
        }

        return $decoded;
    }

    public function modelName(): string
    {
        return $this->model($this->setting());
    }

    private function decodeJson(string $value): mixed
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $start = strpos($value, '{');
        $end = strrpos($value, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($value, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function endpoint(AiSetting $setting): string
    {
        return rtrim((string) ($setting->local_endpoint ?: config('ai.local_endpoint')), '/');
    }

    private function model(AiSetting $setting): string
    {
        $model = trim((string) ($setting->local_model ?: config('ai.local_model')));

        return $model !== '' ? $model : 'llama3.2:latest';
    }
}
