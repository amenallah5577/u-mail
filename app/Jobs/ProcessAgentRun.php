<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\UmailAgentService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAgentRun implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $agentRunId) {}

    public function uniqueId(): string
    {
        return (string) $this->agentRunId;
    }

    public function handle(UmailAgentService $agent): void
    {
        $run = AgentRun::find($this->agentRunId);
        if (! $run || $run->isFinished()) {
            return;
        }

        $run->update(['status' => 'running', 'error_text' => null]);

        try {
            $result = $agent->process($run->fresh(['user']));
            $run->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_text' => 'U-Assist could not finish this request.',
                'completed_at' => now(),
            ]);
        }
    }
}
