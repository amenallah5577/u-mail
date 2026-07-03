<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\ActivationController;
use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AdminEmployeeController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CalendarInvitationController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailLabelController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\MessageReactionController;
use App\Http\Controllers\MfaChallengeController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OnboardingTutorialController;
use App\Http\Controllers\OwnerCredentialController;
use App\Http\Controllers\PasswordConfirmationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SecurityEventController;
use App\Http\Controllers\SecuritySettingsController;
use Illuminate\Support\Facades\Route;

$adminLoginPath = trim((string) config('security.admin_login_path', 'utica-admin-entry'), '/');
if ($adminLoginPath === '' || in_array($adminLoginPath, ['login', 'admin/login'], true)) {
    $adminLoginPath = 'utica-admin-entry';
}

Route::get('/auth/session', [AuthController::class, 'sessionStatus'])->name('auth.session');
Route::get('/calendar/invitations/{token}', [CalendarInvitationController::class, 'show'])->name('calendar.invitations.show');

Route::middleware('guest')->group(function () use ($adminLoginPath) {
    Route::get('/login', [AuthController::class, 'showLogin'])->middleware('no.history')->name('login');
    Route::get($adminLoginPath, [AuthController::class, 'showAdminLogin'])->middleware('no.history')->name('admin.login');
    Route::get('/register', [RegistrationController::class, 'show'])->middleware('registration.enabled')->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware(['registration.enabled', 'throttle:6,1']);
    Route::get('/activate', [ActivationController::class, 'showActivation'])->name('activate');
    Route::post('/activate', [ActivationController::class, 'activate'])->middleware('throttle:6,1');
    Route::get('/reset-password', [ActivationController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password/request', [ActivationController::class, 'requestReset'])->middleware('throttle:6,1')->name('password.reset.request');
    Route::post('/reset-password', [ActivationController::class, 'reset'])->middleware('throttle:6,1')->name('password.reset.update');
    Route::get('/mfa/challenge', [MfaChallengeController::class, 'show'])->name('mfa.challenge');
    Route::post('/mfa/challenge/method', [MfaChallengeController::class, 'select'])->name('mfa.challenge.method');
    Route::post('/mfa/challenge/verify', [MfaChallengeController::class, 'verify'])->name('mfa.challenge.verify');
    Route::post('/mfa/challenge/resend', [MfaChallengeController::class, 'resend'])->name('mfa.challenge.resend');
});

Route::post('/login', [AuthController::class, 'loginEmployee'])->middleware('throttle:6,1');
Route::post($adminLoginPath, [AuthController::class, 'loginAdmin'])->middleware('throttle:6,1')->name('admin.login.submit');

Route::get('/register/verify', [RegistrationController::class, 'showVerification'])->middleware('registration.enabled')->name('register.verify');
Route::post('/register/verify', [RegistrationController::class, 'verify'])->middleware(['registration.enabled', 'throttle:6,1'])->name('register.verify.submit');
Route::post('/register/verify/resend', [RegistrationController::class, 'resend'])->middleware(['registration.enabled', 'throttle:4,15'])->name('register.verify.resend');

Route::middleware(['auth', 'active', 'admin.idle', 'no.history'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [MailboxController::class, 'index'])->name('mailbox');
    Route::get('/mail/{folder}', [MailboxController::class, 'index'])->name('mailbox.folder');
    Route::get('/threads/{thread}', [MailboxController::class, 'show'])->name('threads.show');
    Route::post('/threads/{thread}/mailbox', [MailboxController::class, 'updateThread'])->name('threads.mailbox.update');
    Route::post('/messages/send', [MessageController::class, 'send'])->name('messages.send');
    Route::post('/messages/draft', [MessageController::class, 'draft'])->name('messages.draft');
    Route::get('/messages/{message}/draft', [MessageController::class, 'showDraft'])->name('messages.draft.show');
    Route::delete('/messages/{message}/draft', [MessageController::class, 'discard'])->name('messages.discard');
    Route::post('/agent/runs', [AgentRunController::class, 'store'])->middleware('throttle:12,1')->name('agent.runs.store');
    Route::get('/agent/runs/{agentRun}', [AgentRunController::class, 'show'])->name('agent.runs.show');
    Route::post('/agent/runs/{agentRun}/confirm', [AgentRunController::class, 'confirm'])->middleware('throttle:20,1')->name('agent.runs.confirm');
    Route::post('/mailbox/{entry}', [MailboxController::class, 'update'])->name('mailbox.update');
    Route::post('/mailbox/{entry}/labels', [MailLabelController::class, 'apply'])->name('mailbox.labels.apply');
    Route::delete('/mailbox/{entry}/labels/{mailLabel}', [MailLabelController::class, 'remove'])->name('mailbox.labels.remove');
    Route::post('/labels', [MailLabelController::class, 'store'])->name('labels.store');
    Route::patch('/labels/{mailLabel}', [MailLabelController::class, 'update'])->name('labels.update');
    Route::delete('/labels/{mailLabel}', [MailLabelController::class, 'destroy'])->name('labels.destroy');
    Route::get('/templates', [MessageTemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [MessageTemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{messageTemplate}', [MessageTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{messageTemplate}', [MessageTemplateController::class, 'destroy'])->name('templates.destroy');
    Route::post('/messages/{message}/reaction', [MessageReactionController::class, 'toggle'])->name('messages.reactions.toggle');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
    Route::get('/directory', [DirectoryController::class, 'index'])->middleware('throttle:60,1')->name('directory');
    Route::get('/poll', [MailboxController::class, 'poll'])->name('poll');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('/calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store');
    Route::patch('/calendar/events/{calendarEvent}', [CalendarController::class, 'update'])->name('calendar.events.update');
    Route::delete('/calendar/events/{calendarEvent}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy');
    Route::post('/calendar/events/{calendarEvent}/invitation', [CalendarInvitationController::class, 'generate'])->name('calendar.events.invitation.generate');
    Route::post('/calendar/events/{calendarEvent}/invitation/regenerate', [CalendarInvitationController::class, 'regenerate'])->name('calendar.events.invitation.regenerate');
    Route::delete('/calendar/events/{calendarEvent}/invitation', [CalendarInvitationController::class, 'revoke'])->name('calendar.events.invitation.revoke');
    Route::post('/calendar/invitations/{token}/accept', [CalendarInvitationController::class, 'accept'])->name('calendar.invitations.accept');
    Route::get('/settings', [AccountSettingsController::class, 'index'])->name('account.settings');
    Route::post('/tutorial/onboarding/complete', [OnboardingTutorialController::class, 'complete'])->middleware('throttle:10,1')->name('tutorial.onboarding.complete');
    Route::get('/settings/appearance', [AppearanceController::class, 'index'])->name('appearance.settings');
    Route::patch('/settings/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
    Route::get('/settings/notifications', [NotificationSettingsController::class, 'index'])->name('notifications.settings');
    Route::patch('/settings/notifications', [NotificationSettingsController::class, 'update'])->name('notifications.update');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile-photo/{user}', [ProfileController::class, 'photo'])->name('profile.photo');
    Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
    Route::get('/confirm-password', [PasswordConfirmationController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [PasswordConfirmationController::class, 'confirm'])->name('password.confirm.submit');
    Route::get('/security', [SecuritySettingsController::class, 'index'])->name('security.settings');
    Route::post('/security/password', [SecuritySettingsController::class, 'changePassword'])->middleware('throttle:5,1')->name('security.password.update');
    Route::middleware('password.confirmed')->group(function () {
        Route::post('/security/mfa/totp/start', [SecuritySettingsController::class, 'beginTotp'])->name('security.mfa.totp.start');
        Route::post('/security/mfa/totp/confirm', [SecuritySettingsController::class, 'confirmTotp'])->name('security.mfa.totp.confirm');
        Route::post('/security/mfa/email', [SecuritySettingsController::class, 'enableEmail'])->name('security.mfa.email.enable');
        Route::delete('/security/mfa', [SecuritySettingsController::class, 'disable'])->name('security.mfa.disable');
        Route::post('/security/mfa/recovery', [SecuritySettingsController::class, 'regenerateRecovery'])->name('security.mfa.recovery');
    });

    Route::middleware('owner')->prefix('owner')->name('owner.')->group(function () {
        Route::get('/credentials', [OwnerCredentialController::class, 'index'])->name('credentials');
        Route::post('/credentials/{user}/reveal', [OwnerCredentialController::class, 'reveal'])->middleware('password.confirmed')->name('credentials.reveal');
        Route::get('/security-events', [SecurityEventController::class, 'index'])->name('security-events');
    });

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings');
        Route::patch('/ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');
        Route::get('/employees', [AdminEmployeeController::class, 'index'])->name('employees');
        Route::middleware('password.confirmed')->group(function () {
            Route::post('/employees', [AdminEmployeeController::class, 'store'])->name('employees.store');
            Route::post('/employees/{user}/status', [AdminEmployeeController::class, 'status'])->name('employees.status');
            Route::post('/employees/{user}/activation', [AdminEmployeeController::class, 'resendActivation'])->name('employees.activation');
            Route::post('/employees/{user}/promote', [AdminEmployeeController::class, 'promote'])->name('employees.promote');
            Route::delete('/employees/{user}', [AdminEmployeeController::class, 'destroy'])->name('employees.destroy');
            Route::post('/employees/{user}/mfa-reset', [AdminEmployeeController::class, 'resetMfa'])->name('employees.mfa-reset');
            Route::post('/employees/{user}/public-email', [AdminEmployeeController::class, 'publicEmail'])->name('employees.public-email');
            Route::post('/employees/{user}/approve', [AdminEmployeeController::class, 'approve'])->name('employees.approve');
            Route::post('/employees/{user}/reject', [AdminEmployeeController::class, 'reject'])->name('employees.reject');
        });
    });
});
