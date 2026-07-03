<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use Illuminate\Console\Command;

class ConfigureLocalAi extends Command
{
    protected $signature = 'ai:configure-local
        {--endpoint=http://127.0.0.1:11434 : Local Ollama endpoint used by the U-Mail host}
        {--model=llama3.2:latest : Local Ollama model name}
        {--enable-users : Legacy no-op kept for older startup scripts}
        {--check : Verify the stored local agent-engine settings without changing them}';

    protected $description = 'Configure U-Mail to use the host machine local agent engine';

    public function handle(): int
    {
        $endpoint = rtrim((string) $this->option('endpoint'), '/');
        $model = trim((string) $this->option('model'));

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! preg_match('/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/i', $endpoint)) {
            $this->error('The agent-engine endpoint must be local to the U-Mail host, for example http://127.0.0.1:11434.');

            return self::FAILURE;
        }

        if ($model === '' || ! preg_match('/^[A-Za-z0-9_.:\-]+$/', $model)) {
            $this->error('The local model name is invalid.');

            return self::FAILURE;
        }

        $setting = AiSetting::current();

        if ($this->option('check')) {
            $ready = $setting->enabled
                && $setting->provider === 'local'
                && rtrim((string) $setting->local_endpoint, '/') === $endpoint
                && (string) $setting->local_model === $model;

            $this->line($ready ? 'U-Mail local agent-engine settings are ready.' : 'U-Mail local agent-engine settings are not ready.');

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $setting->update([
            'enabled' => true,
            'provider' => 'local',
            'local_endpoint' => $endpoint,
            'local_model' => $model,
        ]);

        $this->info("Local agent engine enabled through {$endpoint} using {$model}.");

        return self::SUCCESS;
    }
}
