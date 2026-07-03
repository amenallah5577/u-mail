<?php

namespace App\Providers;

use App\Models\CalendarEvent;
use App\Models\AiSetting;
use App\Models\MailboxEntry;
use App\Policies\CalendarEventPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(CalendarEvent::class, CalendarEventPolicy::class);

        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $counts = collect();
            $labels = collect();
            $templates = collect();
            $aiSetting = null;
            if ($user) {
                $counts = MailboxEntry::where('user_id', $user->id)
                    ->selectRaw('folder, count(*) as total')
                    ->groupBy('folder')
                    ->pluck('total', 'folder');
                $counts['unread'] = MailboxEntry::where('user_id', $user->id)
                    ->where('folder', 'inbox')
                    ->where('is_read', false)
                    ->count();
                $counts['starred'] = MailboxEntry::where('user_id', $user->id)
                    ->where('is_starred', true)
                    ->where('folder', '!=', 'trash')
                    ->count();
                $labels = $user->mailLabels()
                    ->withCount('mailboxEntries')
                    ->orderBy('name')
                    ->get();
                $templates = $user->messageTemplates()->orderBy('name')->get();
                $aiSetting = AiSetting::current();
            }
            $view->with('mailCounts', $counts)
                ->with('mailLabels', $labels)
                ->with('messageTemplates', $templates)
                ->with('aiSetting', $aiSetting);
        });
    }
}
