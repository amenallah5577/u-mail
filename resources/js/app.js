const dialog = document.getElementById('composeDialog');
const form = document.getElementById('composeForm');
const body = document.getElementById('composeBody');
const bodyHtml = document.getElementById('bodyHtml');
const draftStatus = document.getElementById('draftStatus');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const signedInUserId = document.body?.dataset.userId;
const sessionCheckUrl = document.body?.dataset.sessionCheckUrl || '/auth/session';
const loginUrl = document.body?.dataset.loginUrl || '/login';
const agentRunUrl = document.body?.dataset.agentRunUrl || '/agent/runs';
const signedOutHistoryKey = 'u-mail:signed-out';
let dirty = false;
let suggestionTimer;

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

// Sensitive mail/admin pages must revalidate after Back/Forward instead of using BFCache.
function hasSignedOutCookie() {
    return document.cookie.split(';').some(cookie => cookie.trim() === 'u_mail_signed_out=1');
}

function hasSignedOutStorage() {
    try {
        return sessionStorage.getItem(signedOutHistoryKey) === 'true';
    } catch {
        return false;
    }
}

function hasSignedOutMarker() {
    return hasSignedOutCookie() || hasSignedOutStorage();
}

function setSignedOutMarker() {
    try {
        sessionStorage.setItem(signedOutHistoryKey, 'true');
    } catch {
        // Session storage can be unavailable in strict browser modes.
    }
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `u_mail_signed_out=1; Path=/; Max-Age=300; SameSite=Strict${secure}`;
}

function clearSignedOutMarker() {
    try {
        sessionStorage.removeItem(signedOutHistoryKey);
    } catch {
        // Session storage can be unavailable in strict browser modes.
    }
    document.cookie = 'u_mail_signed_out=; Path=/; Max-Age=0; SameSite=Strict';
}

function hidePageForSessionCheck() {
    document.documentElement.style.visibility = 'hidden';
}

function showPageAfterSessionCheck() {
    document.documentElement.style.visibility = '';
}

function redirectToLogin() {
    hidePageForSessionCheck();
    window.location.replace(loginUrl);
}

async function verifyRestoredSession(force = false, hideDuringCheck = false) {
    if (!signedInUserId) return;
    if (hasSignedOutCookie()) {
        redirectToLogin();
        return;
    }

    const fromHistory = performance.getEntriesByType('navigation')?.[0]?.type === 'back_forward';
    const afterLogout = hasSignedOutMarker();
    if (!force && !fromHistory && !afterLogout) return;
    if (afterLogout || fromHistory || hideDuringCheck) hidePageForSessionCheck();

    try {
        const response = await fetch(sessionCheckUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = response.ok ? await response.json() : { authenticated: false };
        if (hasSignedOutCookie() || !data.authenticated) {
            redirectToLogin();
            return;
        }
        if (String(data.user_id) !== String(signedInUserId)) {
            hidePageForSessionCheck();
            window.location.reload();
            return;
        }
        clearSignedOutMarker();
        showPageAfterSessionCheck();
    } catch {
        hidePageForSessionCheck();
        window.location.reload();
    }
}

window.addEventListener('pageshow', event => {
    const fromHistory = performance.getEntriesByType('navigation')?.[0]?.type === 'back_forward';
    verifyRestoredSession(true, event.persisted || fromHistory || hasSignedOutMarker());
});
window.addEventListener('focus', () => verifyRestoredSession(true, hasSignedOutMarker()));
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') verifyRestoredSession(true, hasSignedOutMarker());
});
window.addEventListener('popstate', () => verifyRestoredSession(true, true));
verifyRestoredSession();

function openCompose(data = {}) {
    if (!dialog || !form) return;
    form.reset();
    document.getElementById('draftId').value = data.id || '';
    document.getElementById('threadId').value = data.thread_id || '';
    document.getElementById('parentId').value = data.parent_id || '';
    document.getElementById('composeTo').value = data.to || '';
    document.getElementById('composeCc').value = data.cc || '';
    document.getElementById('composeBcc').value = data.bcc || '';
    document.getElementById('composeSubject').value = data.subject || '';
    if (form.elements.scheduled_at) form.elements.scheduled_at.value = data.scheduled_at || '';
    body.innerHTML = data.body || '';
    bodyHtml.value = data.body || '';
    draftStatus.textContent = data.loading ? 'Loading draft...' : (data.id ? 'Draft loaded' : 'Not saved');
    document.getElementById('copyFields').classList.toggle('open', Boolean(data.cc || data.bcc));
    document.querySelector('[data-toggle-copy]')?.setAttribute('aria-expanded', String(Boolean(data.cc || data.bcc)));
    document.querySelector('[data-attachment-summary]')?.setAttribute('hidden', '');
    dirty = false;
    if (!dialog.open) dialog.showModal();
    requestAnimationFrame(() => dialog.classList.add('compose-visible'));
}

async function closeCompose(save = true) {
    if (!dialog?.open) return;
    if (save) await saveDraft();
    dialog.classList.remove('compose-visible');
    setTimeout(() => dialog.open && dialog.close(), 180);
}

document.querySelectorAll('[data-open-compose]').forEach(button => button.addEventListener('click', () => openCompose()));
document.querySelectorAll('[data-close-compose]').forEach(button => button.addEventListener('click', () => closeCompose()));
document.querySelectorAll('[data-copy-value]').forEach(button => button.addEventListener('click', async () => {
    const originalText = button.textContent;
    try {
        await navigator.clipboard.writeText(button.dataset.copyValue || '');
        button.textContent = 'Copied';
    } catch {
        button.textContent = 'Copy failed';
    }
    setTimeout(() => {
        button.textContent = originalText;
    }, 1800);
}));
dialog?.addEventListener('cancel', event => {
    event.preventDefault();
    closeCompose();
});
document.querySelectorAll('[data-open-draft]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    const draftId = link.dataset.draftId || '';
    openCompose({ id: draftId, loading: true });
    fetch(link.dataset.draftUrl, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
        .then(response => response.ok ? response.json() : Promise.reject())
        .then(data => openCompose(data))
        .catch(() => {
            if (draftStatus) draftStatus.textContent = 'Draft could not load';
        });
}));
document.querySelectorAll('[data-reply]').forEach(button => button.addEventListener('click', () => openCompose(JSON.parse(button.dataset.reply))));
document.querySelectorAll('[data-forward]').forEach(button => button.addEventListener('click', () => openCompose(JSON.parse(button.dataset.forward))));
const confirmDialog = document.getElementById('confirmDialog');
const confirmMessage = document.getElementById('confirmDialogMessage');
const confirmAccept = confirmDialog?.querySelector('[data-confirm-accept]');
const confirmCancel = confirmDialog?.querySelector('[data-confirm-cancel]');
let pendingConfirmButton;

function closeConfirmDialog() {
    if (!confirmDialog?.open) return;
    confirmDialog.classList.remove('confirm-visible');
    setTimeout(() => {
        if (confirmDialog.open) confirmDialog.close();
        pendingConfirmButton?.focus();
        pendingConfirmButton = null;
    }, 180);
}

function acceptConfirmedAction() {
    const button = pendingConfirmButton;
    if (!button) return closeConfirmDialog();
    button.dataset.confirmed = 'true';
    closeConfirmDialog();
    setTimeout(() => button.click(), 190);
}

document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
    if (button.dataset.confirmed === 'true') {
        delete button.dataset.confirmed;
        return;
    }
    event.preventDefault();
    pendingConfirmButton = button;
    confirmMessage.textContent = button.dataset.confirm;
    confirmDialog.classList.toggle('confirm-danger', button.classList.contains('danger-button') || button.closest('.permanent'));
    confirmDialog.showModal();
    requestAnimationFrame(() => {
        confirmDialog.classList.add('confirm-visible');
        confirmCancel?.focus();
    });
}));
confirmCancel?.addEventListener('click', closeConfirmDialog);
confirmAccept?.addEventListener('click', acceptConfirmedAction);
confirmDialog?.addEventListener('cancel', event => {
    event.preventDefault();
    closeConfirmDialog();
});
confirmDialog?.addEventListener('click', event => {
    if (event.target === confirmDialog) closeConfirmDialog();
});
document.querySelector('[data-toggle-copy]')?.addEventListener('click', event => {
    const open = document.getElementById('copyFields').classList.toggle('open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
    if (open) document.getElementById('composeCc').focus();
});
document.querySelector('[data-template-picker]')?.addEventListener('change', event => {
    const option = event.target.selectedOptions?.[0];
    if (!option?.value) return;
    const subjectInput = document.getElementById('composeSubject');
    if (subjectInput && option.dataset.subject && !subjectInput.value) {
        subjectInput.value = option.dataset.subject;
    }
    if (body && option.dataset.body) {
        body.innerHTML = option.dataset.body;
        bodyHtml.value = option.dataset.body;
        dirty = true;
    }
    event.target.value = '';
});

const agentPanel = document.querySelector('[data-agent-panel]');
const agentBackdrop = document.querySelector('[data-agent-backdrop]');
const agentToggle = document.querySelector('[data-agent-toggle]');
const agentClose = document.querySelector('[data-agent-close]');
const agentForm = document.querySelector('[data-agent-form]');
const agentPrompt = document.getElementById('agentPrompt');
const agentStatus = document.querySelector('[data-agent-status]');
const agentResults = document.querySelector('[data-agent-results]');
let agentPollTimer;
let currentAgentConfirmUrl;
let currentPreparedDraft;
let currentAgentComposeContext;

function openAgentPanel(prompt = '') {
    if (!agentPanel) return;
    agentPanel.hidden = false;
    if (agentBackdrop) agentBackdrop.hidden = false;
    requestAnimationFrame(() => {
        agentPanel.classList.add('open');
        agentBackdrop?.classList.add('open');
    });
    if (prompt && agentPrompt) agentPrompt.value = prompt;
    setTimeout(() => agentPrompt?.focus(), 120);
}

function closeAgentPanel() {
    if (!agentPanel) return;
    agentPanel.classList.remove('open');
    agentBackdrop?.classList.remove('open');
    setTimeout(() => {
        if (agentPanel) agentPanel.hidden = true;
        if (agentBackdrop) agentBackdrop.hidden = true;
    }, 180);
}

function currentAgentContext() {
    const reader = document.querySelector('[data-thread-reader]');
    const threadId = Number(reader?.dataset.threadId || 0);
    if (threadId > 0) return { context_type: 'thread', context_id: threadId };

    const pageContext = document.querySelector('[data-agent-context]');
    const pageContextType = pageContext?.dataset.agentContextType || '';
    const pageContextId = Number(pageContext?.dataset.agentContextId || 0);
    if (pageContextType) {
        return {
            context_type: pageContextType,
            ...(pageContextId > 0 ? { context_id: pageContextId } : {}),
        };
    }

    return {};
}

function setAgentStatus(message, loading = false) {
    if (!agentStatus) return;
    agentStatus.textContent = message;
    agentStatus.classList.toggle('loading', loading);
}

function plainTextFromHtml(html) {
    const container = document.createElement('div');
    container.innerHTML = html || '';
    return (container.textContent || container.innerText || '').replace(/\s+/g, ' ').trim();
}

function collectComposeContext(includePrivate = false) {
    const context = {
        to: document.getElementById('composeTo')?.value.trim() || '',
        cc: document.getElementById('composeCc')?.value.trim() || '',
        subject: document.getElementById('composeSubject')?.value.trim() || '',
        body_html: body?.innerHTML || '',
        body_text: body?.innerText?.trim() || '',
        attachment_count: document.querySelector('[data-compose-attachments]')?.files?.length || 0,
        thread_id: Number(document.getElementById('threadId')?.value || 0) || null,
        parent_id: Number(document.getElementById('parentId')?.value || 0) || null,
    };
    if (includePrivate) {
        context.bcc = document.getElementById('composeBcc')?.value.trim() || '';
    }
    return context;
}

function composeAgentPayload(localContext = collectComposeContext()) {
    const threadId = Number(localContext.thread_id || document.querySelector('[data-thread-reader]')?.dataset.threadId || 0);
    const payload = {
        compose_context: {
            to: localContext.to || '',
            cc: localContext.cc || '',
            subject: localContext.subject || '',
            body_html: localContext.body_html || '',
            body_text: localContext.body_text || '',
            attachment_count: localContext.attachment_count || 0,
            thread_id: localContext.thread_id || null,
            parent_id: localContext.parent_id || null,
        },
    };

    if (threadId > 0) {
        payload.context_type = 'thread';
        payload.context_id = threadId;
    } else {
        payload.context_type = 'compose';
    }

    return payload;
}

function promptNeedsComposeContext(prompt) {
    return /\b(draft|write|compose|reply|respond|formal|formalize|polish|rewrite|professional|improve|shorten|translate|subject|tone|check|sending)\b/i.test(prompt);
}

function renderAgentCard(card) {
    const href = card.url ? `<a href="${escapeHtml(card.url)}">Open</a>` : '';

    return `<article class="agent-card ${escapeHtml(card.type || 'answer')}">
        <strong>${escapeHtml(card.title || 'Result')}</strong>
        ${card.body ? `<p>${escapeHtml(card.body)}</p>` : ''}
        ${href}
    </article>`;
}

function renderAgentResult(run) {
    currentAgentConfirmUrl = run.confirm_url;
    const result = run.result || {};
    const cards = Array.isArray(result.cards) ? result.cards : [];
    const preparedDraft = result.prepared_draft;
    currentPreparedDraft = preparedDraft || null;
    const preparedActions = Array.isArray(result.prepared_actions) ? result.prepared_actions : [];
    const draftPreview = preparedDraft ? plainTextFromHtml(preparedDraft.body_html || '').slice(0, 520) : '';
    const draftCard = preparedDraft ? `
        <article class="agent-card draft">
            <strong>Prepared draft</strong>
            <p><b>${escapeHtml(preparedDraft.subject || 'Draft message')}</b></p>
            ${draftPreview ? `<div class="agent-draft-preview">${escapeHtml(draftPreview)}</div>` : ''}
            <div class="agent-card-actions">
                <button class="primary-button small" type="button" data-agent-use-draft>Use in composer</button>
                <button class="soft-button small" type="button" data-agent-confirm="create_draft">Save draft copy</button>
            </div>
        </article>` : '';
    const actionCard = preparedActions.length ? `
        <article class="agent-card action_plan">
            <strong>Reviewed actions</strong>
            <p>${preparedActions.map(action => escapeHtml(`${action.type}: ${action.reason || 'Prepared action'}`)).join('<br>')}</p>
            <button class="soft-button small" type="button" data-agent-confirm="apply_actions">Apply reviewed actions</button>
        </article>` : '';

    if (agentResults) {
        agentResults.innerHTML = `
            <article class="agent-answer">
                <strong>Answer</strong>
                <p>${escapeHtml(result.answer || 'U-Assist finished.')}</p>
            </article>
            ${cards.map(renderAgentCard).join('')}
            ${draftCard}
            ${actionCard}
        `;
    }
    setAgentStatus('Finished. Review anything prepared before using it.');
}

async function startAgentRun(prompt, extraPayload = {}) {
    if (agentResults) agentResults.innerHTML = '';
    currentPreparedDraft = null;
    if (!extraPayload.compose_context) currentAgentComposeContext = null;
    setAgentStatus('U-Assist is preparing a precise result...', true);
    try {
        const response = await fetch(agentRunUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ prompt, ...extraPayload }),
        });
        const run = await response.json();
        if (!response.ok) throw new Error(run.message || 'Request failed');
        if (run.status === 'completed') {
            renderAgentResult(run);
            return;
        }
        pollAgentRun(run.url);
    } catch (error) {
        setAgentStatus(error.message || 'U-Assist could not start.');
    }
}

function usePreparedDraftInComposer(statusMessage = 'Draft updated by U-Assist') {
    if (!currentPreparedDraft) return;
    const preserved = currentAgentComposeContext || {};
    const draftData = {
        thread_id: currentPreparedDraft.thread_id || preserved.thread_id || '',
        parent_id: currentPreparedDraft.parent_id || preserved.parent_id || '',
        to: currentPreparedDraft.to || preserved.to || '',
        cc: currentPreparedDraft.cc || preserved.cc || '',
        bcc: currentPreparedDraft.bcc || preserved.bcc || '',
        subject: currentPreparedDraft.subject || preserved.subject || '',
        body: currentPreparedDraft.body_html || preserved.body_html || '',
    };

    if (dialog?.open && form) {
        document.getElementById('threadId').value = draftData.thread_id || document.getElementById('threadId').value || '';
        document.getElementById('parentId').value = draftData.parent_id || document.getElementById('parentId').value || '';
        document.getElementById('composeTo').value = draftData.to;
        document.getElementById('composeCc').value = draftData.cc;
        document.getElementById('composeBcc').value = draftData.bcc;
        document.getElementById('composeSubject').value = draftData.subject;
        body.innerHTML = draftData.body;
        bodyHtml.value = draftData.body;
        document.getElementById('copyFields').classList.toggle('open', Boolean(draftData.cc || draftData.bcc));
        document.querySelector('[data-toggle-copy]')?.setAttribute('aria-expanded', String(Boolean(draftData.cc || draftData.bcc)));
        if (draftStatus) draftStatus.textContent = statusMessage;
        dirty = true;
    } else {
        openCompose(draftData);
    }
    setAgentStatus('The draft is in the composer. Review it before sending.');
}

function wait(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
}

async function waitForAgentCompletion(url, onProgress = () => {}) {
    for (;;) {
        const run = await fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
        }).then(response => response.json());

        if (run.status === 'queued' || run.status === 'running') {
            onProgress(run.status);
            await wait(1500);
            continue;
        }

        return run;
    }
}

function firstAgentReviewMessage(result = {}) {
    const card = Array.isArray(result.cards) ? result.cards[0] : null;
    if (card?.title) {
        return card.body ? `${card.title}: ${card.body}` : card.title;
    }

    return result.answer || 'U-Assist finished. Review the draft before sending.';
}

async function startInlineComposeAssist(prompt, button = null) {
    currentAgentComposeContext = collectComposeContext(true);
    if (!currentAgentComposeContext.body_text && !currentAgentComposeContext.subject) {
        if (draftStatus) draftStatus.textContent = 'Write a draft or subject first.';
        body?.focus();
        return;
    }

    closeAgentPanel();
    currentPreparedDraft = null;
    const originalText = button?.textContent || '';
    button?.setAttribute('disabled', 'disabled');
    if (button) button.classList.add('loading');
    if (draftStatus) draftStatus.textContent = 'U-Assist is correcting the draft...';

    try {
        const response = await fetch(agentRunUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ prompt, inline_compose: true, ...composeAgentPayload(currentAgentComposeContext) }),
        });
        const startedRun = await response.json();
        if (!response.ok) throw new Error(startedRun.message || 'Request failed');

        const run = startedRun.status === 'completed'
            ? startedRun
            : await waitForAgentCompletion(startedRun.url, status => {
                if (draftStatus) draftStatus.textContent = status === 'queued' ? 'U-Assist is waiting...' : 'U-Assist is rewriting the draft...';
            });

        if (run.status !== 'completed') {
            throw new Error(run.error || 'U-Assist could not finish this draft.');
        }

        const preparedDraft = run.result?.prepared_draft || null;
        if (preparedDraft) {
            currentPreparedDraft = preparedDraft;
            usePreparedDraftInComposer('Draft corrected by U-Assist');
            return;
        }

        if (draftStatus) draftStatus.textContent = firstAgentReviewMessage(run.result).slice(0, 180);
    } catch (error) {
        if (draftStatus) draftStatus.textContent = error.message || 'U-Assist could not correct this draft.';
    } finally {
        button?.removeAttribute('disabled');
        if (button) {
            button.classList.remove('loading');
            if (originalText) button.setAttribute('aria-label', originalText.trim());
        }
    }
}

async function pollAgentRun(url) {
    window.clearTimeout(agentPollTimer);
    try {
        const run = await fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
        }).then(response => response.json());
        if (run.status === 'queued' || run.status === 'running') {
            setAgentStatus(run.status === 'queued' ? 'U-Assist is waiting...' : 'U-Assist is reading authorized mail...', true);
            agentPollTimer = window.setTimeout(() => pollAgentRun(url), 1500);
            return;
        }
        if (run.status === 'completed') {
            renderAgentResult(run);
            return;
        }
        setAgentStatus(run.error || 'U-Assist could not finish this request.');
    } catch {
        setAgentStatus('U-Assist could not be reached. Try again.');
    }
}

async function confirmAgentAction(action) {
    if (!currentAgentConfirmUrl) return;
    setAgentStatus('Applying your confirmed action...', true);
    try {
        const response = await fetch(currentAgentConfirmUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ action }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Action failed');
        setAgentStatus(data.message || 'Done.');
        if (data.draft) {
            openCompose(data.draft);
        } else if (data.status === 'actions_applied') {
            window.setTimeout(() => window.location.reload(), 900);
        }
    } catch (error) {
        setAgentStatus(error.message || 'The confirmed action could not be applied.');
    }
}

agentToggle?.addEventListener('click', () => openAgentPanel());
agentClose?.addEventListener('click', closeAgentPanel);
agentBackdrop?.addEventListener('click', closeAgentPanel);
document.querySelectorAll('[data-agent-example]').forEach(button => button.addEventListener('click', () => {
    openAgentPanel(button.dataset.agentExample || '');
}));

document.querySelectorAll('[data-agent-action]').forEach(button => button.addEventListener('click', () => {
    const prompt = button.dataset.agentPrompt || button.textContent.trim();
    const contextType = button.dataset.agentContextType || '';
    const contextId = Number(button.dataset.agentContextId || 0);
    const payload = contextType
        ? { context_type: contextType, ...(contextId > 0 ? { context_id: contextId } : {}) }
        : currentAgentContext();

    openAgentPanel(prompt);
    startAgentRun(prompt, payload);
}));

agentForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const prompt = agentPrompt?.value.trim() || '';
    if (prompt.length < 3) {
        setAgentStatus('Write a question or task first.');
        return;
    }
    let payload = currentAgentContext();
    if (dialog?.open && promptNeedsComposeContext(prompt)) {
        currentAgentComposeContext = collectComposeContext(true);
        payload = composeAgentPayload(currentAgentComposeContext);
    }
    startAgentRun(prompt, payload);
});

agentResults?.addEventListener('click', event => {
    const useDraftButton = event.target.closest('[data-agent-use-draft]');
    if (useDraftButton) {
        usePreparedDraftInComposer();
        return;
    }

    const button = event.target.closest('[data-agent-confirm]');
    if (!button) return;
    confirmAgentAction(button.dataset.agentConfirm);
});

document.querySelector('[data-agent-formalize-compose]')?.addEventListener('click', () => {
    const prompt = 'Make this email precise, formal, and ready to send. Preserve the recipient, subject, names, dates, amounts, and meaning. Return the complete polished email draft.';
    startInlineComposeAssist(prompt, document.querySelector('[data-agent-formalize-compose]'));
});

document.querySelectorAll('[data-agent-compose-prompt]').forEach(button => button.addEventListener('click', () => {
    const prompt = button.dataset.agentComposePrompt || 'Improve this email and prepare a polished draft.';
    startInlineComposeAssist(prompt, button);
}));

document.querySelector('[data-menu]')?.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));
const sidebarCollapsedKey = `u-mail:sidebar:${signedInUserId}:collapsed`;
const sidebarCollapseButton = document.querySelector('[data-sidebar-collapse]');

document.querySelectorAll('[data-logout-form]').forEach(form => {
    form.addEventListener('submit', async event => {
        if (!window.fetch) {
            setSignedOutMarker();
            hidePageForSessionCheck();
            return;
        }

        if (form.dataset.logoutPending === 'true') {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        form.dataset.logoutPending = 'true';
        setSignedOutMarker();
        form.querySelectorAll('button, input, select, textarea').forEach(control => {
            control.disabled = true;
        });

        try {
            const response = await fetch(form.action, {
                method: form.method || 'POST',
                body: new FormData(form),
                cache: 'no-store',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf || form.querySelector('input[name="_token"]')?.value || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('Logout failed');
            hidePageForSessionCheck();
            window.location.replace(loginUrl);
        } catch {
            hidePageForSessionCheck();
            HTMLFormElement.prototype.submit.call(form);
        }
    });
});

function applySidebarCollapsed(collapsed) {
    document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    sidebarCollapseButton?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Reduce sidebar');
    sidebarCollapseButton?.setAttribute('title', collapsed ? 'Expand sidebar' : 'Reduce sidebar');
}

if (signedInUserId && localStorage.getItem(sidebarCollapsedKey) === 'true') {
    applySidebarCollapsed(true);
}

sidebarCollapseButton?.addEventListener('click', () => {
    const collapsed = !document.body.classList.contains('sidebar-collapsed');
    localStorage.setItem(sidebarCollapsedKey, String(collapsed));
    applySidebarCollapsed(collapsed);
});

const onboardingTour = document.querySelector('[data-onboarding-tour]');
const onboardingSpotlight = document.querySelector('[data-onboarding-spotlight]');
const onboardingCard = document.querySelector('[data-onboarding-card]');
const onboardingTitle = document.querySelector('[data-onboarding-title]');
const onboardingBody = document.querySelector('[data-onboarding-body]');
const onboardingProgress = document.querySelector('[data-onboarding-progress]');
const onboardingBack = document.querySelector('[data-onboarding-back]');
const onboardingNext = document.querySelector('[data-onboarding-next]');
const onboardingSkip = document.querySelector('[data-onboarding-skip]');
const onboardingRestart = document.querySelector('[data-onboarding-restart]');
const onboardingAvailable = document.body?.dataset.onboardingTourAvailable === 'true';
const onboardingAutoStart = document.body?.dataset.onboardingTourAutoStart === 'true';
const onboardingCompleteUrl = document.body?.dataset.onboardingTourCompleteUrl || '/tutorial/onboarding/complete';
const onboardingVersion = document.body?.dataset.onboardingTourVersion || '1';
const onboardingStepKey = `u-mail:onboarding:${signedInUserId}:v${onboardingVersion}:step`;
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let onboardingStepIndex = 0;
let onboardingActive = false;
let onboardingTarget;

const onboardingSteps = [
    {
        target: 'compose',
        title: 'Start a message',
        body: 'Use Compose to write to another U-Mail user or an outside email address. Add attachments, formatting, emojis, and scheduled sending from the composer.',
    },
    {
        target: 'inbox',
        title: 'Your inbox',
        body: 'Inbox is where new messages arrive. Unread counts update while U-Mail is open, so you can spot new work quickly.',
    },
    {
        target: 'search',
        title: 'Find mail faster',
        body: 'Search by words, people, subject, date, read status, stars, attachments, or size using the filter button.',
    },
    {
        target: 'opened-mail',
        title: 'Work inside a message',
        body: 'When you open a conversation, you can reply, forward, react, star, print, archive, or move messages to Trash.',
    },
    {
        target: 'folders',
        title: 'Mailbox folders',
        body: 'Use the sidebar to move between Inbox, Starred, Sent, Drafts, Scheduled, Archive, and Trash.',
    },
    {
        target: 'profile-menu',
        title: 'Your profile menu',
        body: 'Open your profile menu to edit your profile, manage account settings, update security, or sign out.',
    },
    {
        target: 'account-settings',
        title: 'Personalize U-Mail',
        body: 'Account settings lets you change profile details, security, notifications, appearance, and restart this tutorial.',
    },
];

function onboardingTargetElement(step) {
    return document.querySelector(`[data-tour-target="${step.target}"]`);
}

function clearOnboardingTarget() {
    onboardingTarget?.classList.remove('onboarding-tour-highlighted');
    onboardingTarget = null;
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function positionOnboardingCard(target) {
    if (!onboardingCard) return;
    const margin = 18;
    const cardRect = onboardingCard.getBoundingClientRect();
    if (!target) {
        onboardingCard.style.left = `${Math.max(margin, (window.innerWidth - cardRect.width) / 2)}px`;
        onboardingCard.style.top = `${Math.max(margin, (window.innerHeight - cardRect.height) / 2)}px`;
        return;
    }

    const rect = target.getBoundingClientRect();
    const rightSpace = window.innerWidth - rect.right;
    const left = rightSpace > cardRect.width + margin
        ? rect.right + margin
        : clamp(rect.left, margin, window.innerWidth - cardRect.width - margin);
    const belowTop = rect.bottom + margin;
    const aboveTop = rect.top - cardRect.height - margin;
    const top = belowTop + cardRect.height <= window.innerHeight
        ? belowTop
        : clamp(aboveTop > margin ? aboveTop : rect.top, margin, window.innerHeight - cardRect.height - margin);

    onboardingCard.style.left = `${left}px`;
    onboardingCard.style.top = `${top}px`;
}

function positionOnboardingSpotlight(target) {
    if (!onboardingSpotlight) return;
    if (!target) {
        onboardingSpotlight.hidden = true;
        return;
    }

    const rect = target.getBoundingClientRect();
    onboardingSpotlight.hidden = false;
    onboardingSpotlight.style.left = `${rect.left - 8}px`;
    onboardingSpotlight.style.top = `${rect.top - 8}px`;
    onboardingSpotlight.style.width = `${rect.width + 16}px`;
    onboardingSpotlight.style.height = `${rect.height + 16}px`;
}

function renderOnboardingStep() {
    if (!onboardingActive || !onboardingTour) return;
    const step = onboardingSteps[onboardingStepIndex];
    clearOnboardingTarget();
    onboardingTarget = onboardingTargetElement(step);

    if (onboardingTarget) {
        onboardingTarget.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'center', inline: 'center' });
    }

    const updatePosition = () => {
        if (onboardingTarget) onboardingTarget.classList.add('onboarding-tour-highlighted');
        positionOnboardingSpotlight(onboardingTarget);
        positionOnboardingCard(onboardingTarget);
    };

    onboardingTitle.textContent = step.title;
    onboardingBody.textContent = step.body;
    onboardingProgress.textContent = `Step ${onboardingStepIndex + 1} of ${onboardingSteps.length}`;
    onboardingBack.disabled = onboardingStepIndex === 0;
    onboardingNext.textContent = onboardingStepIndex === onboardingSteps.length - 1 ? 'Finish' : 'Next';
    localStorage.setItem(onboardingStepKey, String(onboardingStepIndex));
    requestAnimationFrame(updatePosition);
    if (!prefersReducedMotion) setTimeout(updatePosition, 280);
}

function startOnboardingTour({ manual = false } = {}) {
    if (!onboardingAvailable || !onboardingTour || !onboardingCard) return;
    onboardingActive = true;
    onboardingStepIndex = manual ? 0 : Number(localStorage.getItem(onboardingStepKey) || 0);
    if (!Number.isFinite(onboardingStepIndex) || onboardingStepIndex < 0 || onboardingStepIndex >= onboardingSteps.length) {
        onboardingStepIndex = 0;
    }
    onboardingTour.hidden = false;
    onboardingTour.classList.add('active');
    document.body.classList.add('onboarding-tour-open');
    renderOnboardingStep();
}

async function completeOnboardingTour(action = 'finish') {
    if (!onboardingActive) return;
    try {
        await fetch(onboardingCompleteUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ action }),
        });
    } catch {
        // If saving fails, the server will show the tour again later.
    }
    onboardingActive = false;
    clearOnboardingTarget();
    localStorage.removeItem(onboardingStepKey);
    document.body.dataset.onboardingTourAutoStart = 'false';
    document.body.classList.remove('onboarding-tour-open');
    onboardingTour?.classList.remove('active');
    if (onboardingTour) onboardingTour.hidden = true;
}

onboardingBack?.addEventListener('click', () => {
    if (onboardingStepIndex === 0) return;
    onboardingStepIndex -= 1;
    renderOnboardingStep();
});
onboardingNext?.addEventListener('click', () => {
    if (onboardingStepIndex === onboardingSteps.length - 1) {
        completeOnboardingTour('finish');
        return;
    }
    onboardingStepIndex += 1;
    renderOnboardingStep();
});
onboardingSkip?.addEventListener('click', () => completeOnboardingTour('skip'));
onboardingRestart?.addEventListener('click', () => startOnboardingTour({ manual: true }));
window.addEventListener('resize', () => onboardingActive && renderOnboardingStep());
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && onboardingActive) completeOnboardingTour('skip');
});
if (onboardingAutoStart) {
    setTimeout(() => startOnboardingTour(), 550);
}

const appearanceForm = document.querySelector('[data-appearance-form]');
const appearanceCards = document.querySelectorAll('[data-appearance-card]');
const appearanceHeading = document.querySelector('[data-appearance-heading]');
const appearanceHint = document.querySelector('[data-appearance-hint]');

function applyAppearancePreview(theme) {
    if (!appearanceForm || !theme) return;

    document.documentElement.dataset.themePreference = theme;
    appearanceCards.forEach(card => {
        const input = card.querySelector('input[name="theme_preference"]');
        card.classList.toggle('selected', input?.value === theme);
    });

    if (appearanceHeading) appearanceHeading.textContent = theme.toUpperCase();
    if (appearanceHint) {
        const saved = appearanceForm.dataset.savedTheme || 'system';
        appearanceHint.textContent = theme === saved
            ? 'Current theme is saved.'
            : `Previewing ${theme}. Click Save appearance to keep it.`;
    }
}

appearanceCards.forEach(card => {
    card.addEventListener('change', event => {
        if (event.target?.matches('input[name="theme_preference"]')) {
            applyAppearancePreview(event.target.value);
        }
    });
});

const searchFilterToggle = document.querySelector('[data-search-filter-toggle]');
const searchFilterPanel = document.querySelector('[data-search-filter-panel]');
searchFilterToggle?.addEventListener('click', () => {
    const open = !searchFilterPanel.classList.contains('open');
    searchFilterPanel.classList.toggle('open', open);
    searchFilterToggle.setAttribute('aria-expanded', String(open));
});
document.addEventListener('click', event => {
    if (!searchFilterPanel?.classList.contains('open')) return;
    if (event.target.closest('[data-search-form]')) return;
    searchFilterPanel.classList.remove('open');
    searchFilterToggle?.setAttribute('aria-expanded', 'false');
});
const profileMenuButton = document.querySelector('[data-profile-menu-button]');
const profileMenu = document.querySelector('[data-profile-menu]');
const profileMenuWrap = document.querySelector('[data-profile-menu-wrap]');
profileMenuButton?.addEventListener('click', () => {
    const open = !profileMenu.classList.contains('open');
    profileMenu.classList.toggle('open', open);
    profileMenu.toggleAttribute('inert', !open);
    profileMenu.setAttribute('aria-hidden', String(!open));
    profileMenuButton.setAttribute('aria-expanded', String(open));
});
document.addEventListener('click', event => {
    if (profileMenu && !profileMenuWrap?.contains(event.target)) {
        profileMenu.classList.remove('open');
        profileMenu.setAttribute('inert', '');
        profileMenu.setAttribute('aria-hidden', 'true');
        profileMenuButton?.setAttribute('aria-expanded', 'false');
    }
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && profileMenu) {
        profileMenu.classList.remove('open');
        profileMenu.setAttribute('inert', '');
        profileMenu.setAttribute('aria-hidden', 'true');
        profileMenuButton?.setAttribute('aria-expanded', 'false');
        profileMenuButton?.focus();
    }
});
const photoInput = document.querySelector('[data-photo-input]');
photoInput?.addEventListener('change', () => {
    const fileName = document.querySelector('[data-photo-file-name]');
    if (fileName) fileName.textContent = photoInput.files?.[0]?.name || 'JPG, PNG, or WebP. Maximum 2 MB.';
});
document.querySelector('[data-discard]')?.addEventListener('click', async () => {
    const draftId = document.getElementById('draftId').value;
    if (draftId) {
        await fetch(`/messages/${draftId}/draft`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
    }
    dirty = false;
    closeCompose(false);
});

document.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click', () => {
    document.execCommand(button.dataset.format, false);
    body.focus();
    dirty = true;
}));

form?.addEventListener('input', () => {
    bodyHtml.value = body.innerHTML;
    dirty = true;
});

form?.addEventListener('submit', () => {
    bodyHtml.value = body.innerHTML;
});

async function saveDraft() {
    if (!dirty || !dialog?.open || !form) return;
    const data = new FormData();
    ['to', 'cc', 'bcc', 'subject', 'draft_id', 'thread_id', 'parent_id'].forEach(name => {
        data.append(name, form.elements[name]?.value || '');
    });
    data.append('body_html', body.innerHTML);
    draftStatus.textContent = 'Saving...';
    try {
        const response = await fetch('/messages/draft', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: data });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to save');
        document.getElementById('draftId').value = result.id;
        draftStatus.textContent = 'Saved just now';
        dirty = false;
    } catch {
        draftStatus.textContent = 'Draft not saved';
    }
}
setInterval(saveDraft, 5000);

const composeAttachments = document.querySelector('[data-compose-attachments]');
composeAttachments?.addEventListener('change', () => {
    const files = [...composeAttachments.files];
    const summary = document.querySelector('[data-attachment-summary]');
    const text = document.querySelector('[data-attachment-text]');
    if (!summary || !text) return;
    summary.toggleAttribute('hidden', files.length === 0);
    text.textContent = files.length === 1 ? files[0].name : `${files.length} files selected`;
    dirty = files.length > 0 || dirty;
});

const toInput = document.getElementById('composeTo');
const suggestions = document.getElementById('recipientSuggestions');
toInput?.addEventListener('input', () => {
    clearTimeout(suggestionTimer);
    const query = toInput.value.split(/[,;]/).pop().trim();
    if (query.length < 2) {
        suggestions.classList.remove('open');
        return;
    }
    suggestionTimer = setTimeout(async () => {
        const users = await fetch(`/directory?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } }).then(response => response.json());
        suggestions.innerHTML = '';
        users.forEach(user => {
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<strong>${user.name}</strong><span>${user.email}</span>`;
            button.addEventListener('click', () => {
                const parts = toInput.value.split(/[,;]/);
                parts.pop();
                parts.push(user.email);
                toInput.value = parts.filter(Boolean).join(', ') + ', ';
                suggestions.classList.remove('open');
                dirty = true;
                toInput.focus();
            });
            suggestions.appendChild(button);
        });
        suggestions.classList.toggle('open', users.length > 0);
    }, 250);
});

const notificationUserId = document.body?.dataset.userId;
const notificationCursorKey = `u-mail:notifications:${notificationUserId}:cursor`;
const notificationLeaseKey = `u-mail:notifications:${notificationUserId}:lease`;
const notificationActiveKey = `u-mail:notifications:${notificationUserId}:active`;
const notificationTabId = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`;
const notificationChannel = 'BroadcastChannel' in window && notificationUserId
    ? new BroadcastChannel(`u-mail:notifications:${notificationUserId}`)
    : null;
let mailNotificationsEnabled = document.body?.dataset.mailNotificationsEnabled === 'true';

function notificationSupported() {
    return 'Notification' in window && 'localStorage' in window;
}

function readLocalJson(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function claimNotificationLease() {
    if (!notificationUserId || !notificationSupported()) return false;
    const now = Date.now();
    const current = readLocalJson(notificationLeaseKey);
    if (current?.tabId !== notificationTabId && current?.expiresAt > now) return false;
    localStorage.setItem(notificationLeaseKey, JSON.stringify({ tabId: notificationTabId, expiresAt: now + 25000 }));
    return readLocalJson(notificationLeaseKey)?.tabId === notificationTabId;
}

function markNotificationTabActive() {
    if (!notificationUserId || document.visibilityState !== 'visible' || !document.hasFocus()) return;
    const active = { tabId: notificationTabId, expiresAt: Date.now() + 20000 };
    localStorage.setItem(notificationActiveKey, JSON.stringify(active));
    notificationChannel?.postMessage({ type: 'active', ...active });
}

function clearNotificationTabActive() {
    if (readLocalJson(notificationActiveKey)?.tabId === notificationTabId) {
        localStorage.removeItem(notificationActiveKey);
    }
}

function anyNotificationTabActive() {
    return (readLocalJson(notificationActiveKey)?.expiresAt || 0) > Date.now();
}

function setNotificationCursor(cursor) {
    if (!notificationUserId || cursor === undefined || cursor === null) return;
    localStorage.setItem(notificationCursorKey, String(cursor));
    notificationChannel?.postMessage({ type: 'cursor', cursor });
}

function notificationCursor() {
    const cursor = localStorage.getItem(notificationCursorKey);
    if (cursor === null) return null;
    const parsed = Number(cursor);
    if (Number.isFinite(parsed) && parsed >= 0) return parsed;
    localStorage.removeItem(notificationCursorKey);
    return null;
}

function showMailNotification(item) {
    if (!notificationSupported() || Notification.permission !== 'granted' || anyNotificationTabActive()) return;
    const notification = new Notification(`New mail from ${item.sender}`, {
        body: item.subject,
        icon: '/images/utica-jendouba-logo.png',
        badge: '/favicon.png',
        tag: `u-mail-entry-${item.id}`,
    });
    notification.onclick = () => {
        window.focus();
        window.location.href = item.url;
        notification.close();
    };
}

async function pollMailbox() {
    if (!csrf) return;
    try {
        const leader = mailNotificationsEnabled && claimNotificationLease();
        const cursor = leader ? notificationCursor() : null;
        const pollUrl = leader && cursor !== null ? `/poll?notification_cursor=${encodeURIComponent(cursor)}` : '/poll';
        const data = await fetch(pollUrl, { headers: { Accept: 'application/json' } }).then(response => response.json());
        const unread = document.querySelector('[data-count="inbox"]');
        if (unread) unread.textContent = data.unread || '';
        if (leader) {
            data.notifications?.forEach(showMailNotification);
            setNotificationCursor(data.notification_cursor);
        }
    } catch {
        // The next polling interval will retry.
    }
}
markNotificationTabActive();
window.addEventListener('focus', markNotificationTabActive);
window.addEventListener('blur', clearNotificationTabActive);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') markNotificationTabActive();
    else clearNotificationTabActive();
});
window.addEventListener('pagehide', clearNotificationTabActive);
setInterval(markNotificationTabActive, 5000);
setInterval(pollMailbox, 15000);

notificationChannel?.addEventListener('message', event => {
    if (event.data?.type === 'cursor') localStorage.setItem(notificationCursorKey, String(event.data.cursor));
    if (event.data?.type === 'preference') {
        mailNotificationsEnabled = Boolean(event.data.enabled);
        document.body.dataset.mailNotificationsEnabled = String(mailNotificationsEnabled);
        if (!mailNotificationsEnabled) {
            localStorage.removeItem(notificationCursorKey);
            localStorage.removeItem(notificationLeaseKey);
        }
    }
});

const notificationSettings = document.querySelector('[data-notification-settings]');
const notificationStatus = document.querySelector('[data-notification-status]');
const notificationHelp = document.querySelector('[data-notification-help]');
const notificationPageState = document.querySelector('[data-notification-page-state] b');
const enableNotificationsButton = document.querySelector('[data-enable-notifications]');
const disableNotificationsButton = document.querySelector('[data-disable-notifications]');
const testNotificationButton = document.querySelector('[data-test-notification]');

function updateNotificationSettingsUi() {
    if (!notificationSettings) return;
    let status = 'Notifications are off';
    let help = 'Choose Enable notifications to start receiving new-mail alerts in this browser.';
    if (!notificationSupported()) {
        status = 'This browser does not support notifications';
        help = 'Use a current version of Microsoft Edge or Google Chrome.';
    } else if (Notification.permission === 'denied') {
        status = 'Notifications are blocked by this browser';
        help = 'Allow notifications for U-Mail in your browser site settings, then return here.';
    } else if (mailNotificationsEnabled && Notification.permission === 'granted') {
        status = 'Notifications are enabled';
        help = 'Keep at least one signed-in U-Mail tab open. Alerts are hidden while you are actively using U-Mail.';
    } else if (mailNotificationsEnabled) {
        status = 'Browser permission is still needed';
        help = 'Choose Enable notifications to finish allowing alerts in this browser.';
    }
    notificationStatus.textContent = status;
    notificationHelp.textContent = help;
    notificationPageState.textContent = mailNotificationsEnabled ? 'ON' : 'OFF';
    enableNotificationsButton.hidden = !notificationSupported() || (mailNotificationsEnabled && Notification.permission === 'granted');
    disableNotificationsButton.hidden = !mailNotificationsEnabled;
    testNotificationButton.hidden = !mailNotificationsEnabled || Notification.permission !== 'granted';
}

async function saveNotificationPreference(enabled) {
    const response = await fetch('/settings/notifications', {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ enabled }),
    });
    if (!response.ok) throw new Error('Unable to update notifications.');
    return response.json();
}

enableNotificationsButton?.addEventListener('click', async () => {
    if (!notificationSupported()) return updateNotificationSettingsUi();
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return updateNotificationSettingsUi();
    try {
        const data = await saveNotificationPreference(true);
        mailNotificationsEnabled = true;
        document.body.dataset.mailNotificationsEnabled = 'true';
        setNotificationCursor(data.cursor);
        notificationChannel?.postMessage({ type: 'preference', enabled: true });
        updateNotificationSettingsUi();
    } catch {
        notificationHelp.textContent = 'Notifications could not be enabled. Please try again.';
    }
});

disableNotificationsButton?.addEventListener('click', async () => {
    try {
        await saveNotificationPreference(false);
        mailNotificationsEnabled = false;
        document.body.dataset.mailNotificationsEnabled = 'false';
        localStorage.removeItem(notificationCursorKey);
        localStorage.removeItem(notificationLeaseKey);
        notificationChannel?.postMessage({ type: 'preference', enabled: false });
        updateNotificationSettingsUi();
    } catch {
        notificationHelp.textContent = 'Notifications could not be turned off. Please try again.';
    }
});

testNotificationButton?.addEventListener('click', () => {
    if (Notification.permission !== 'granted') return updateNotificationSettingsUi();
    const notification = new Notification('U-Mail notifications are ready', {
        body: 'New inbox messages will show the sender and subject here.',
        icon: '/images/utica-jendouba-logo.png',
        badge: '/favicon.png',
        tag: 'u-mail-test-notification',
    });
    notification.onclick = () => {
        window.focus();
        notification.close();
    };
});

updateNotificationSettingsUi();

document.querySelectorAll('[data-message-toggle]').forEach(button => button.addEventListener('click', () => {
    const card = button.closest('[data-message-card]');
    const expanded = card.classList.toggle('expanded');
    card.classList.toggle('collapsed', !expanded);
    button.setAttribute('aria-expanded', String(expanded));
}));

document.querySelectorAll('.reader-more').forEach(menu => menu.addEventListener('toggle', () => {
    if (!menu.open) return;
    document.querySelectorAll('.reader-more[open]').forEach(other => {
        if (other !== menu) other.removeAttribute('open');
    });
}));

const inlineReplyCard = document.getElementById('inlineReplyCard');
const inlineReplyForm = document.getElementById('inlineReplyForm');
const inlineEditor = document.getElementById('inlineEditor');
const inlineBodyHtml = document.getElementById('inlineBodyHtml');
const inlineDraftStatus = document.getElementById('inlineDraftStatus');
let inlineReplyDirty = false;

function openInlineReply(data) {
    if (!inlineReplyCard || !inlineReplyForm || !inlineEditor) return;
    inlineReplyForm.reset();
    document.getElementById('inlineDraftId').value = '';
    document.getElementById('inlineThreadId').value = data.thread_id || '';
    document.getElementById('inlineParentId').value = data.parent_id || '';
    document.getElementById('inlineSubject').value = data.subject || '';
    document.getElementById('inlineTo').value = data.to || '';
    document.getElementById('inlineCc').value = data.cc || '';
    document.getElementById('inlineBcc').value = data.bcc || '';
    document.getElementById('inlineReplyMode').textContent = data.mode || 'Reply';
    inlineEditor.innerHTML = data.body || '';
    inlineBodyHtml.value = data.body || '';
    inlineDraftStatus.textContent = data.mode === 'Forward' ? 'Forward as a new conversation.' : 'Replies remain in this conversation.';
    inlineReplyDirty = false;
    inlineReplyCard.hidden = false;
    inlineReplyCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => inlineEditor.focus(), 350);
}

document.querySelectorAll('[data-inline-action]').forEach(button => button.addEventListener('click', () => {
    openInlineReply(JSON.parse(button.dataset.inlineAction));
}));

inlineReplyForm?.addEventListener('input', () => {
    inlineBodyHtml.value = inlineEditor.innerHTML;
    inlineReplyDirty = true;
});
inlineReplyForm?.addEventListener('submit', () => {
    inlineBodyHtml.value = inlineEditor.innerHTML;
});
document.querySelectorAll('[data-inline-format]').forEach(button => button.addEventListener('click', () => {
    document.execCommand(button.dataset.inlineFormat, false);
    inlineEditor.focus();
    inlineReplyDirty = true;
}));

async function saveInlineDraft() {
    if (!inlineReplyDirty || inlineReplyCard?.hidden || !inlineReplyForm) return;
    const draftData = new FormData();
    ['to', 'cc', 'bcc', 'subject', 'draft_id', 'thread_id', 'parent_id'].forEach(name => {
        draftData.append(name, inlineReplyForm.elements[name]?.value || '');
    });
    draftData.append('body_html', inlineEditor.innerHTML);
    inlineDraftStatus.textContent = 'Saving draft...';
    try {
        const response = await fetch('/messages/draft', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: draftData });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to save');
        document.getElementById('inlineDraftId').value = result.id;
        inlineDraftStatus.textContent = 'Draft saved';
        inlineReplyDirty = false;
    } catch {
        inlineDraftStatus.textContent = 'Draft not saved';
    }
}
setInterval(saveInlineDraft, 5000);

document.querySelectorAll('[data-close-inline-reply]').forEach(button => button.addEventListener('click', async () => {
    await saveInlineDraft();
    inlineReplyCard.hidden = true;
}));
document.querySelector('[data-discard-inline]')?.addEventListener('click', async () => {
    const draftId = document.getElementById('inlineDraftId').value;
    if (draftId) {
        await fetch(`/messages/${draftId}/draft`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
    }
    inlineReplyDirty = false;
    inlineReplyCard.hidden = true;
});

function setupRecipientSuggestions(input, container, onSelect) {
    let timer;
    input?.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.split(/[,;]/).pop().trim();
        if (query.length < 2) {
            container?.classList.remove('open');
            return;
        }
        timer = setTimeout(async () => {
            const users = await fetch(`/directory?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } }).then(response => response.json());
            container.innerHTML = '';
            users.forEach(user => {
                const suggestion = document.createElement('button');
                suggestion.type = 'button';
                suggestion.innerHTML = `<strong>${user.name}</strong><span>${user.email}</span>`;
                suggestion.addEventListener('click', () => {
                    const parts = input.value.split(/[,;]/);
                    parts.pop();
                    parts.push(user.email);
                    input.value = parts.filter(Boolean).join(', ') + ', ';
                    container.classList.remove('open');
                    onSelect();
                    input.focus();
                });
                container.appendChild(suggestion);
            });
            container.classList.toggle('open', users.length > 0);
        }, 250);
    });
}
setupRecipientSuggestions(document.getElementById('inlineTo'), document.getElementById('inlineRecipientSuggestions'), () => {
    inlineReplyDirty = true;
});

const emojiPicker = document.getElementById('emojiPicker');
const emojiGrid = emojiPicker?.querySelector('.emoji-grid');
let emojiTarget;
let reactionUrl;

function closeEmojiPicker() {
    if (!emojiPicker) return;
    emojiPicker.hidden = true;
    emojiTarget = null;
    reactionUrl = null;
}

function placeEmojiPicker(trigger) {
    const rect = trigger.getBoundingClientRect();
    emojiPicker.style.left = `${Math.min(rect.left, window.innerWidth - 300)}px`;
    emojiPicker.style.top = `${Math.min(rect.bottom + 8, window.innerHeight - 330)}px`;
}

async function chooseEmoji(emoji) {
    if (reactionUrl) {
        const reactionData = new FormData();
        reactionData.append('emoji', emoji);
        await fetch(reactionUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'text/html' }, body: reactionData });
        window.location.reload();
        return;
    }
    if (emojiTarget) {
        emojiTarget.focus();
        document.execCommand('insertText', false, emoji);
        emojiTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
    closeEmojiPicker();
}

if (emojiPicker && emojiGrid) {
    JSON.parse(emojiPicker.dataset.emojis || '[]').forEach(emoji => {
        const emojiButton = document.createElement('button');
        emojiButton.type = 'button';
        emojiButton.textContent = emoji;
        emojiButton.addEventListener('click', () => chooseEmoji(emoji));
        emojiGrid.appendChild(emojiButton);
    });
}
document.querySelectorAll('[data-emoji-trigger]').forEach(trigger => trigger.addEventListener('click', event => {
    event.stopPropagation();
    reactionUrl = null;
    emojiTarget = document.querySelector(trigger.dataset.emojiTarget);
    emojiPicker.hidden = false;
    placeEmojiPicker(trigger);
}));
document.querySelectorAll('[data-reaction-trigger]').forEach(trigger => trigger.addEventListener('click', event => {
    event.stopPropagation();
    emojiTarget = null;
    reactionUrl = trigger.dataset.reactionUrl;
    emojiPicker.hidden = false;
    placeEmojiPicker(trigger);
}));
document.querySelector('[data-close-emoji]')?.addEventListener('click', closeEmojiPicker);
document.addEventListener('click', event => {
    if (emojiPicker && !emojiPicker.hidden && !emojiPicker.contains(event.target) && !event.target.closest('[data-emoji-trigger], [data-reaction-trigger]')) {
        closeEmojiPicker();
    }
});

document.querySelectorAll('[data-print-thread]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelectorAll('[data-print-message]').forEach(button => button.addEventListener('click', () => {
    const card = document.querySelector(`[data-message-id="${button.dataset.printMessage}"]`);
    card?.classList.add('print-selected');
    document.body.classList.add('printing-single-message');
    window.print();
    card?.classList.remove('print-selected');
    document.body.classList.remove('printing-single-message');
}));

document.addEventListener('keydown', event => {
    const typing = event.target.matches('input, textarea, [contenteditable="true"]');
    if (event.key === 'Escape') {
        closeEmojiPicker();
        document.querySelectorAll('.reader-more[open]').forEach(menu => menu.removeAttribute('open'));
        return;
    }
    if (typing || event.ctrlKey || event.metaKey || event.altKey) return;
    const latestCard = document.querySelector('[data-message-card]:last-of-type');
    const shortcut = {
        r: latestCard?.querySelector('[data-inline-action]'),
        a: [...(latestCard?.querySelectorAll('[data-inline-action]') || [])].find(button => JSON.parse(button.dataset.inlineAction).mode === 'Reply all'),
        f: [...(latestCard?.querySelectorAll('[data-inline-action]') || [])].find(button => JSON.parse(button.dataset.inlineAction).mode === 'Forward'),
    }[event.key.toLowerCase()];
    if (shortcut) {
        event.preventDefault();
        shortcut.click();
    }
    if (event.key === '[' && document.querySelector('[data-thread-reader]')?.dataset.previousUrl) {
        window.location.href = document.querySelector('[data-thread-reader]').dataset.previousUrl;
    }
    if (event.key === ']' && document.querySelector('[data-thread-reader]')?.dataset.nextUrl) {
        window.location.href = document.querySelector('[data-thread-reader]').dataset.nextUrl;
    }
});
