@extends('layouts.app')
@section('title', 'Mail Notifications')
@section('content')
<section class="account-page notification-settings-page" data-notification-settings>
    <div class="page-heading">
        <div><p class="eyebrow">ACCOUNT SETTINGS</p><h1>Mail notifications</h1></div>
        <span class="page-count" data-notification-page-state><b>{{ $user->mail_notifications_enabled ? 'ON' : 'OFF' }}</b> Alerts</span>
    </div>

    <div class="notification-settings-grid">
        <article class="panel notification-main-card">
            <div class="security-card-intro">
                <span class="setting-symbol"><x-icon name="bell" /></span>
                <div>
                    <p class="eyebrow">NEW INBOX MAIL</p>
                    <h2>Desktop notifications</h2>
                    <p class="panel-note">See the sender and subject when a new message reaches your inbox. Keep at least one signed-in U-Mail tab open to receive alerts.</p>
                </div>
            </div>

            <div class="notification-status-card">
                <span class="notification-status-dot"></span>
                <div><small>Current status</small><strong data-notification-status>Checking this browser...</strong></div>
            </div>

            <div class="notification-actions">
                <button class="primary-button small" type="button" data-enable-notifications>Enable notifications</button>
                <button class="soft-button" type="button" data-test-notification>Send test notification</button>
                <button class="soft-button danger-button" type="button" data-disable-notifications>Turn off</button>
            </div>
            <p class="notification-help" data-notification-help>Browser permission is requested only after you choose Enable notifications.</p>
        </article>

        <article class="panel notification-privacy-card">
            <p class="eyebrow">PRIVACY</p>
            <h2>What alerts show</h2>
            <div class="notification-preview">
                <img src="/images/utica-jendouba-logo.png" alt="">
                <span><strong>New mail from UTICA Employee</strong><small>Example message subject</small></span>
            </div>
            <p>Alerts never include message text, recipients, attachments, contact email addresses, or BCC details.</p>
            <p>Notifications stop when every U-Mail tab is closed. This release does not use an outside push service.</p>
        </article>
    </div>
</section>
@endsection
