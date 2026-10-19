/* Telegram Backup — no analytics, remote assets, or browser-persisted secrets. */
(() => {
    'use strict';
    const app = document.getElementById('parcel-app');
    const config = window.ParcelBackups;
    if (!app || !config) return;

    const state = { status: null, tab: 'overview', draft: {}, dirty: false, busy: new Set(), notice: null, diagnostics: null, chats: null, preflight: null, type: 'full', logs: {}, openLog: '', lastRefresh: 0, pollError: '' };
    const names = { full: 'Full site', database: 'Database', files: 'Files only' };
    const statuses = { queued: 'Queued', running: 'Running', retry: 'Waiting to retry', completed: 'Delivered', failed: 'Error', cancelled: 'Cancelled' };
    const stages = { scan: 'Scanning files', database: 'Exporting database', archive: 'Building archive', send: 'Uploading to Telegram', complete: 'Complete' };
    const keys = ['chat_id', 'thread_id', 'api_mode', 'api_url', 'proxy_url', 'proxy_username', 'dns_servers', 'doh_url', 'schedule', 'schedule_time', 'schedule_day', 'backup_type', 'retention_count', 'retention_days', 'quota_mb', 'reserve_mb', 'max_backup_mb', 'part_mb', 'excludes', 'delete_on_uninstall', 'consent', 'token', 'password', 'proxy_password', 'clear_token', 'clear_proxy_password'];
    const numberKeys = new Set(['thread_id', 'schedule_day', 'retention_count', 'retention_days', 'quota_mb', 'reserve_mb', 'max_backup_mb', 'part_mb']);
    const booleanKeys = new Set(['delete_on_uninstall', 'consent', 'clear_token', 'clear_proxy_password']);
    const active = job => ['queued', 'running', 'retry'].includes(job.status);
    const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const settings = () => ({ ...(state.status?.settings || {}), ...state.draft });
    const val = key => esc(settings()[key] ?? '');
    const checked = key => settings()[key] ? ' checked' : '';
    const selected = (key, value) => settings()[key] === value ? ' selected' : '';
    const busy = key => state.busy.has(key);
    const busyAttr = key => busy(key) ? ' disabled aria-busy="true"' : '';
    const safeNumber = n => Number.isFinite(Number(n)) ? Number(n) : 0;
    function bytes(value) {
        let n = Math.max(0, safeNumber(value));
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return `${new Intl.NumberFormat('en-US', { maximumFractionDigits: i ? 1 : 0 }).format(n)} ${units[i]}`;
    }
    function date(value) {
        if (!value) return 'Not yet';
        const d = new Date(typeof value === 'number' ? value * 1000 : value);
        if (Number.isNaN(d.getTime())) return 'Unknown';
        try { return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: state.status?.schedule?.timezone || undefined }).format(d); }
        catch (_) { return d.toLocaleString('en-US'); }
    }
    function ageSeconds(value) {
        if (!value) return Infinity;
        const d = new Date(typeof value === 'number' ? value * 1000 : value);
        return Math.max(0, (Date.now() - d.getTime()) / 1000);
    }
    function textError(error) {
        let text = String(error?.message || error || 'The action could not be completed.');
        [state.draft.token, state.draft.password, state.draft.proxy_password].filter(Boolean).forEach(secret => { text = text.split(secret).join('[redacted]'); });
        return text.replace(/bot\d+:[A-Za-z0-9_-]+/g, 'bot[redacted]').slice(0, 2000);
    }
    function endpoint(path) {
        const root = new URL(config.root, window.location.href);
        const [pathname, query = ''] = path.split('?');
        if (root.searchParams.has('rest_route')) {
            root.searchParams.set('rest_route', root.searchParams.get('rest_route').replace(/\/$/, '') + pathname);
            new URLSearchParams(query).forEach((value, key) => root.searchParams.set(key, value));
            return root.toString();
        }
        return `${config.root}${path}`;
    }
    async function api(path, method = 'GET', data) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 120000);
        try {
            const response = await fetch(endpoint(path), { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, ...(data === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: data === undefined ? undefined : JSON.stringify(data), signal: controller.signal });
            let result;
            try { result = await response.json(); } catch (_) { throw new Error(`The server returned an unexpected response (${response.status}). Check the PHP log and site proxy settings.`); }
            if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? `${result.message || 'Access denied.'} Refresh the page and check your WordPress login.` : result.message || `Server error: ${response.status}`);
            return result;
        } catch (error) { if (error.name === 'AbortError') throw new Error('The server did not respond within 2 minutes. Refresh the status before retrying: the action may still be running on the server.'); throw error; }
        finally { clearTimeout(timeout); }
    }
    async function refresh(renderNow = true) {
        state.status = await api('/status');
        state.lastRefresh = Date.now(); state.pollError = '';
        if (renderNow) render();
        return state.status;
    }
    async function action(key, fn) {
        if (busy(key)) return;
        state.busy.add(key); state.notice = null; render();
        try { await fn(); }
        catch (error) { state.notice = { type: 'error', text: textError(error) }; }
        finally { state.busy.delete(key); render(); }
    }
    const notice = () => state.notice ? `<div class="notice notice-${state.notice.type === 'error' ? 'error' : 'success'} p-notice" role="${state.notice.type === 'error' ? 'alert' : 'status'}"><p>${esc(state.notice.text)}</p><button type="button" class="notice-dismiss" data-action="dismiss"><span class="screen-reader-text">Dismiss notice</span></button></div>` : '';
    const badge = (text, type = 'neutral') => `<span class="p-state p-state--${esc(type)}">${esc(text)}</span>`;
    const button = (text, actionName, key = actionName, primary = false, extra = '') => `<button type="button" class="button${primary ? ' button-primary' : ''}" data-action="${esc(actionName)}"${busyAttr(key)}${extra}>${esc(text)}</button>`;
    const row = (title, content, id = '') => `<tr><th scope="row">${id ? `<label for="p-${esc(id)}">${esc(title)}</label>` : esc(title)}</th><td>${content}</td></tr>`;
    const help = text => text ? `<p class="description">${text}</p>` : '';
    function field(key, title, description = '', options = {}) {
        const { type = 'text', min, max, placeholder = '', secret = false } = options;
        const input = `<input id="p-${esc(key)}" name="${esc(key)}" type="${type}" class="${type === 'number' ? 'small-text' : 'regular-text'}" value="${secret ? esc(state.draft[key] || '') : val(key)}"${secret ? ' autocomplete="new-password" spellcheck="false"' : ''}${min !== undefined ? ` min="${min}"` : ''}${max !== undefined ? ` max="${max}"` : ''} placeholder="${esc(placeholder)}"${numberKeys.has(key) ? ' step="1" inputmode="numeric"' : ''}>`;
        return row(title, input + help(description), key);
    }
    const formSave = () => `<p class="submit p-savebar"><button class="button button-primary" type="submit"${busyAttr('save')}>${busy('save') ? 'Saving…' : 'Save changes'}</button><span class="description">${state.dirty ? 'You have unsaved changes.' : ''}</span></p>`;
    function requirements() {
        return `<table class="widefat striped p-check-table"><tbody>${(state.status?.requirements || []).map(item => `<tr><td>${esc(item.label)}</td><td>${badge(item.ok ? 'Ready' : 'Error', item.ok ? 'green' : 'red')}</td><td>${esc(item.detail)}</td></tr>`).join('')}</tbody></table>`;
    }
    function diagnostics() {
        const result = state.diagnostics || state.status?.connection;
        if (!result) return '<p class="description">No check has been run yet.</p>';
        return `<div class="p-diagnostics"><p>${badge(result.ok ? 'Check passed' : 'Errors found', result.ok ? 'green' : 'red')}${result.checked_at ? ` · ${date(result.checked_at)}` : ''}</p><table class="widefat striped p-check-table"><thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead><tbody>${(result.checks || []).map(item => `<tr><td>${esc(item.label)}</td><td>${badge(item.skipped ? 'Not checked' : item.ok ? 'OK' : 'Error', item.skipped ? 'neutral' : item.ok ? 'green' : 'red')}</td><td>${esc(item.detail)}${item.ms != null ? ` (${esc(item.ms)} ms)` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    }
    function systemCronControls() {
        const cron = state.status.system_cron || {};
        const present = Boolean(cron.present || cron.installed);
        const wpDisabled = Boolean(state.status.schedule?.wp_cron_disabled);
        const reason = cron.error || cron.reason || (!cron.available ? 'Automatic setup is unavailable on this host.' : '');
        const disabled = cron.available ? '' : ' disabled';
        const installButton = title => button(busy('system-cron-install') ? 'Setting up…' : title, 'system-cron-install', 'system-cron-install', false, disabled);
        return `${wpDisabled && !cron.installed ? `<p class="description">WP-Cron is disabled.</p><p>${installButton(present ? 'Update system cron' : 'Set up system cron')}</p>${reason ? help(esc(reason)) : ''}` : ''}<details data-detail="cron"><summary>Cron setup</summary>${present ? `<p>${cron.installed ? 'System cron is installed' : 'The system cron entry needs updating'}${cron.user ? ` for ${esc(cron.user)}` : ''}. Check the last-run timestamp above to confirm execution.</p>${!cron.installed && !wpDisabled ? `<p>${installButton('Update system cron')}</p>` : ''}<p>${button(busy('system-cron-remove') ? 'Removing…' : 'Remove plugin system cron', 'system-cron-remove')}</p>${reason && !(wpDisabled && !cron.installed) ? help(esc(reason)) : ''}` : !wpDisabled ? `<p>WP-Cron runs on site visits. If the host allows it, a system cron entry can be added automatically.</p><p>${installButton('Set up automatically')}</p>${reason ? help(esc(reason)) : ''}` : ''}${cron.command ? `<p>Command for manual setup (once per minute):</p><pre><code>${esc(cron.command)}</code></pre>` : !cron.installed ? `<p>Run this once per minute through your hosting panel:</p><pre><code>curl --fail --silent --show-error --max-time 120 '${esc(config.cronUrl || '')}' &gt; /dev/null</code></pre>` : ''}<p>${button(busy('repair-schedule') ? 'Repairing…' : 'Repair WordPress Cron events', 'repair-schedule')}</p></details>`;
    }
    function overview() {
        const s = settings(), status = state.status, jobs = status.jobs || [], worker = status.worker || {}, storage = status.storage || {};
        const ongoing = jobs.filter(active), last = jobs.find(job => job.status === 'completed');
        const cronOk = ageSeconds(worker.last_cron_tick) <= 300;
        const ready = s.has_token && s.has_password && s.chat_id && s.consent;
        return `${!ready ? '<div class="notice notice-warning inline"><p>Enter your bot token, chat, and archive password under <a href="#connection" data-tab="connection">Connection</a>.</p></div>' : ''}
        <h2>Create a backup</h2><div class="p-run-controls"><label for="p-run-type">Backup scope</label><select id="p-run-type">${Object.entries(names).map(([key,title]) => `<option value="${key}"${state.type === key ? ' selected' : ''}>${title}</option>`).join('')}</select>${button(busy('preflight') ? 'Checking…' : 'Check size', 'preflight')}${button(busy('enqueue') ? 'Adding…' : 'Create backup', 'enqueue', 'enqueue', true, ready ? '' : ' disabled')}</div>${preflight()}
        <div class="p-overview-grid"><section class="postbox"><h2 class="hndle">Status</h2><div class="inside"><table class="p-status-table"><tbody>${row('Last backup', last ? `${date(last.completed_at || last.updated_at || last.created_at)}<br><span class="description">${esc(names[last.type] || last.type)} · ${bytes(last.bytes)}</span>` : 'No backups yet')}${row('Next backup', s.schedule === 'off' ? 'Schedule disabled' : date(status.schedule?.next_run))}${row('Timezone', esc(status.schedule?.timezone || 'WordPress'))}${row('Used / quota', `${bytes(storage.used_bytes)} / ${bytes(storage.quota_bytes)}`)}${row('Free disk space', storage.free_bytes == null ? 'Unknown' : bytes(storage.free_bytes))}${row('Required reserve', bytes(storage.reserve_bytes))}</tbody></table></div></section>
        <section class="postbox"><h2 class="hndle">Cron and queue</h2><div class="inside"><table class="p-status-table"><tbody>${row('Cron', badge(cronOk ? 'Running' : worker.last_cron_tick ? 'No run in over 5 minutes' : 'No runs yet', cronOk ? 'green' : 'amber'))}${row('System cron', status.system_cron ? (status.system_cron.installed ? 'Installed' : status.system_cron.present ? 'Needs updating' : 'Not installed') : 'Not checked')}${row('Last cron run', date(worker.last_cron_tick))}${row('Next cron step', date(status.schedule?.next_worker))}${row('Last queue step', `${date(worker.last_tick)}${worker.last_source ? ` · ${esc(worker.last_source === 'manual' ? 'manual' : worker.last_source === 'cron' ? 'cron' : worker.last_source)}` : ''}`)}${row('Active jobs', `${ongoing.length}${worker.busy ? ' · running' : ''}`)}</tbody></table><p>${button(busy('worker') ? 'Running…' : 'Run one queue step', 'worker')}</p>${help('A manual run does not confirm cron execution.')}${systemCronControls()}</div></section></div>
        <div class="p-section-bar"><h2>Queue</h2>${button('Refresh', 'refresh')}</div>${jobsTable(ongoing)}<details class="p-section-details" data-detail="requirements"><summary>Server checks</summary>${requirements()}</details>`;
    }
    function preflight() {
        const p = state.preflight;
        if (!p) return '';
        return `<div class="notice notice-${p.ok ? 'success' : 'error'} inline p-preflight"><p><strong>${p.ok ? 'Size check passed.' : 'Size check failed.'}</strong> Source data: ${bytes(p.source_bytes)}. Estimated archive: ${bytes(p.estimated_bytes)}. Required: ${bytes(p.required_bytes)}.</p>${[...(p.errors || []), ...(p.warnings || [])].map(text => `<p>${esc(text)}</p>`).join('')}</div>`;
    }
    function connection() {
        const s = settings(), local = s.api_mode === 'local';
        return `<h2>Telegram connection</h2><details class="p-section-details" data-detail="instructions"><summary>Instructions</summary><ol><li>Open <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a>, send <code>/newbot</code> and get a token.</li><li>Open your new bot, press Start, and send a message. For a group, add the bot and allow it to send files.</li><li>Save the token and data-transfer consent. Find and select a chat, set an archive password, then save the settings again.</li><li>Send a test file. Keep the password outside the site; recovery is impossible without it.</li></ol></details>
        <form id="p-settings-form"><table class="form-table" role="presentation"><tbody>${field('token', 'Bot token', s.has_token ? 'Token saved. Leave blank to keep it unchanged.' : '', {type:'password',secret:true,placeholder:s.has_token ? 'Saved' : '123456789:AA…'})}${field('chat_id', 'Chat ID', '', {placeholder:'123456789 or -100…'})}${row('', `${button(busy('discover') ? 'Searching…' : 'Find chat from a message', 'discover')}${state.chats ? `<div class="p-chat-results">${state.chats.length ? state.chats.map(chat => `<p><button type="button" class="button p-chat-result" data-chat="${esc(chat.id)}" data-thread="${esc(chat.thread_id || 0)}">${esc(chat.title || chat.id)} · ${esc(chat.id)}${chat.thread_id ? ` · topic ${esc(chat.thread_id)}` : ''}</button></p>`).join('') : '<p>No chats found. Send the bot a new message.</p>'}</div>` : ''}`)}${field('thread_id', 'Topic ID', '0 for a regular chat.', {type:'number',min:0})}${field('password', 'Archive password', s.has_password ? 'Password saved. Older backups still require the password used when they were created.' : 'At least 12 characters. Store it outside the site.', {type:'password',secret:true,placeholder:s.has_password ? 'Saved' : ''})}${row('Data transfer', `<label><input type="checkbox" name="consent"${checked('consent')}> Allow connections to Telegram and encrypted backup uploads to the selected chat.</label>`)}</tbody></table>
        <h2>Bot API</h2><table class="form-table" role="presentation"><tbody>${row('Mode', `<select id="p-api_mode" name="api_mode"><option value="cloud"${selected('api_mode','cloud')}>Cloud Bot API — up to 50 MB</option><option value="local"${selected('api_mode','local')}>Self-hosted Bot API — up to 2 GB</option></select>${help('Telegram Premium does not increase Bot API limits.')}`, 'api_mode')}${local ? field('api_url', 'Server URL', 'Requires the official Bot API server in --local mode. Enter its URL without /bot or the token.', {placeholder:'https://bot-api.example.com'}) : row('',help('Backups are split into parts: 40 MiB per file by default.'))}</tbody></table>
        <details class="p-section-details" data-detail="network"${s.proxy_url || s.dns_servers || s.doh_url ? ' open' : ''}><summary>Proxy and DNS</summary><table class="form-table" role="presentation"><tbody>${field('proxy_url','Proxy URL','HTTP, HTTPS, SOCKS5, or SOCKS5H.',{placeholder:'socks5h://proxy.example.com:1080'})}${field('proxy_username','Proxy username')}${field('proxy_password','Proxy password',s.has_proxy_password ? 'Saved. Leave blank to keep the current password.' : '',{type:'password',secret:true,placeholder:s.has_proxy_password ? 'Saved' : ''})}${row('',`<label><input type="checkbox" name="clear_proxy_password"${checked('clear_proxy_password')}> Clear the saved proxy password</label>`)}${field('dns_servers','DNS servers','Comma-separated IP addresses. Requires cURL DNS_SERVERS support.',{placeholder:'1.1.1.1,8.8.8.8'})}${field('doh_url','DNS over HTTPS','Use instead of regular DNS servers. With SOCKS5H, the proxy resolves hostnames.',{placeholder:'https://cloudflare-dns.com/dns-query'})}</tbody></table></details>${formSave()}</form>
        <h2>Connection test</h2><p class="p-action-row">${button(busy('diagnostics') ? 'Checking…' : 'Test connection','diagnostics')}${button('Send test file','test-message','diagnostics')}</p>${state.dirty ? '<p class="description">Save your changes first.</p>' : ''}${diagnostics()}`;
    }
    function schedule() {
        const s = settings(), maxPart = s.api_mode === 'local' ? 1900 : 45;
        return `<form id="p-settings-form"><h2>Schedule</h2><table class="form-table" role="presentation"><tbody>${row('Frequency',`<select name="schedule" id="p-schedule"><option value="off"${selected('schedule','off')}>Manual only</option><option value="hourly"${selected('schedule','hourly')}>Hourly</option><option value="daily"${selected('schedule','daily')}>Daily</option><option value="weekly"${selected('schedule','weekly')}>Weekly</option></select>`, 'schedule')}${row('Backup scope',`<select name="backup_type" id="p-backup_type">${Object.entries(names).map(([key,title]) => `<option value="${key}"${selected('backup_type',key)}>${title}</option>`).join('')}</select>`, 'backup_type')}${s.schedule === 'daily' || s.schedule === 'weekly' ? field('schedule_time','Time',`Timezone: ${esc(state.status.schedule?.timezone || 'WordPress')}.`,{type:'time'}) : ''}${s.schedule === 'weekly' ? row('Day of week',`<select name="schedule_day" id="p-schedule_day">${[[1,'Monday'],[2,'Tuesday'],[3,'Wednesday'],[4,'Thursday'],[5,'Friday'],[6,'Saturday'],[0,'Sunday']].map(([key,title]) => `<option value="${key}"${Number(s.schedule_day) === key ? ' selected' : ''}>${title}</option>`).join('')}</select>`,'schedule_day') : ''}${row('Next run', s.schedule === 'off' ? 'Schedule disabled' : date(state.status.schedule?.next_run))}</tbody></table>
        <h2>Storage limits</h2><table class="form-table" role="presentation"><tbody>${field('quota_mb','Total quota, MiB','All local archives and working data.',{type:'number',min:128})}${field('reserve_mb','Free-space reserve, MiB','This amount of disk space must remain free.',{type:'number',min:64})}${field('max_backup_mb','Maximum backup size, MiB','Combined size of every part.',{type:'number',min:16})}${field('part_mb','Part size, MiB',s.api_mode === 'local' ? 'Up to 1900 MiB; self-hosted Bot API limit: 2 GB. This limits each uploaded file; backup size and total disk quota are separate.' : 'Up to 45 MiB; cloud Bot API limit: 50 MB. This limits each uploaded file; backup size and total disk quota are separate.',{type:'number',min:2,max:maxPart})}</tbody></table>
        <h2>Retention and exclusions</h2><table class="form-table" role="presentation"><tbody>${field('retention_count','Completed backups to keep','',{type:'number',min:1})}${field('retention_days','Maximum age, days','',{type:'number',min:1})}${row('Cleanup', `${button(busy('cleanup') ? 'Cleaning up…' : 'Apply retention rules now','cleanup')}${help('Only delivered local backups are removed by count, age, or quota. Telegram files remain.')}`)}${row('Excluded paths',`<textarea id="p-excludes" name="excludes" rows="5" class="large-text code" spellcheck="false">${val('excludes')}</textarea>${help('Relative to the WordPress root, one path per line. Symlinks are not copied.')}`,'excludes')}${row('Plugin removal',`<label><input type="checkbox" name="delete_on_uninstall"${checked('delete_on_uninstall')}> Delete saved settings when uninstalling</label>`)}</tbody></table>${formSave()}</form>`;
    }
    function downloadUrl(id, name) {
        return esc(endpoint(`/download?${new URLSearchParams({job:id,file:name,_wpnonce:config.nonce}).toString()}`));
    }
    function partLinks(job) {
        return `<ul class="p-part-list">${(job.parts || []).map(part => `<li><a href="${downloadUrl(job.id,part.name)}">${esc(part.name)}</a> — ${bytes(part.bytes)}${part.sent || part.message_id ? ', sent' : ', not sent'}</li>`).join('')}</ul>`;
    }
    function jobsTable(jobs, history = false) {
        if (!jobs.length) return '<p>No jobs.</p>';
        return `<div class="p-table-scroll"><table class="widefat striped p-jobs-table"><thead><tr><th scope="col">Date / scope</th><th scope="col">Status</th><th scope="col">Size</th><th scope="col">Files / parts</th><th scope="col">Actions</th></tr></thead><tbody>${jobs.map(job => jobRows(job,history)).join('')}</tbody></table></div>`;
    }
    function jobRows(job, history = false) {
        const progress = Math.max(0,Math.min(100,safeNumber(job.progress))), isActive = active(job), type = job.status === 'completed' ? 'green' : job.status === 'failed' ? 'red' : job.status === 'retry' ? 'amber' : 'neutral', key = `job-${job.id}`, log = state.logs[job.id];
        return `<tr class="p-job"><td><strong>${esc(names[job.type] || job.type)}</strong><br>${date(job.created_at)}<br><code>${esc(String(job.id).slice(0,8))}</code></td><td>${badge(statuses[job.status] || job.status,type)}${isActive ? `<div class="p-progress"><span>${esc(stages[job.stage] || job.stage || 'Preparing')} · ${Math.round(progress)}%</span><progress max="100" value="${progress}" aria-label="Backup progress"></progress>${job.retry_at ? `<span>Retry: ${date(Number(job.retry_at))}</span>` : ''}</div>` : ''}${job.error ? `<p class="p-error">${esc(job.error)}</p>` : ''}${job.message ? `<p class="description">${esc(job.message)}</p>` : ''}</td><td>${bytes(job.bytes)}</td><td>${safeNumber(job.files)} / ${Array.isArray(job.parts) ? job.parts.length : 0}${job.attempts ? `<br><span class="description">Attempts: ${safeNumber(job.attempts)}</span>` : ''}</td><td><div class="p-job-actions">${button(state.openLog === job.id ? 'Hide log' : 'Log','logs',`logs-${job.id}`,false,` data-id="${esc(job.id)}"`)}${isActive ? button('Cancel','cancel',key,false,` data-id="${esc(job.id)}"`) : `${job.status === 'failed' ? button('Retry','retry',key,false,` data-id="${esc(job.id)}"`) : ''}<button type="button" class="button-link-delete" data-action="delete" data-id="${esc(job.id)}"${busyAttr(key)}>Delete locally</button>`}</div></td></tr>
        ${state.openLog === job.id ? `<tr><td colspan="5"><div class="p-log-panel"><strong>Job log</strong><pre>${log ? log.length ? log.map(entry => `${entry.time || ''} [${entry.level || 'info'}] ${entry.message || ''}${entry.context && Object.keys(entry.context).length ? ` ${JSON.stringify(entry.context)}` : ''}`).map(esc).join('\n') : 'No entries.' : 'Loading…'}</pre></div></td></tr>` : ''}
        ${job.local_deleted ? '<tr><td colspan="5">Local files were removed. The backup remains in Telegram.</td></tr>' : history && !isActive && job.parts?.length ? `<tr><td colspan="5"><details class="p-downloads" data-detail="parts-${esc(job.id)}"><summary>Download archive (${job.parts.length} parts)</summary>${job.status !== 'completed' ? '<p class="p-error">This backup is unfinished. Its parts may be incomplete.</p>' : ''}${partLinks(job)}${job.status === 'completed' ? `<p><a href="${downloadUrl(job.id,'manifest.json')}">Download manifest.json</a></p>` : ''}</details></td></tr>` : ''}`;
    }
    function history() {
        return `<div class="p-section-bar"><h2>Job history</h2>${button('Refresh','refresh')}</div>${jobsTable(state.status.jobs || [],true)}<p class="description">Delivered means Telegram acknowledged every part. Test recovery separately.</p>`;
    }
    function recovery() {
        return `<h2>Recovery</h2><ol><li>Download every part of one backup and <code>manifest.json</code> from Telegram or the job history.</li><li>Download the tool and have the password for this backup ready.</li><li>Run the tool in a separate empty directory. Requires PHP 8.1+ with OpenSSL and zlib.</li><li>Check the files, import the SQL into an empty database on a test site, and verify WordPress works.</li></ol><p><a class="button" href="${esc(endpoint(`/recovery?_wpnonce=${encodeURIComponent(config.nonce)}`))}">Download telegram-backup-restore.php</a></p><p>Command options:</p><pre><code>php telegram-backup-restore.php --help</code></pre><p class="description">Enter the password at the prompt or through stdin. The tool does not overwrite a live site or import SQL automatically.</p><details class="p-section-details" data-detail="recovery"><summary>Instructions</summary><p>Do not mix parts from different backups or rename files. Older backups require the original password. Keep the password and tool outside the site.</p><p>After recovery, configure the database connection in <code>wp-config.php</code>. If the domain changes, use a URL replacement tool that handles serialized WordPress data.</p></details>`;
    }
    function render() {
        if (!state.status) return;
        const detailStates = new Map(Array.from(app.querySelectorAll('details[data-detail]'), element => [element.dataset.detail,element.open]));
        const tabs = [['overview','Overview'],['connection','Connection'],['schedule','Schedule and storage'],['history','History'],['recovery','Recovery']];
        const view = {overview,connection,schedule,history,recovery}[state.tab] || overview;
        app.setAttribute('aria-busy','false');
        app.innerHTML = `<h1 class="wp-heading-inline">Telegram Backup</h1><hr class="wp-header-end"><nav class="nav-tab-wrapper p-nav" aria-label="Telegram Backup sections">${tabs.map(([key,title]) => `<a href="#${key}" data-tab="${key}" class="nav-tab${state.tab === key ? ' nav-tab-active' : ''}"${state.tab === key ? ' aria-current="page"' : ''}>${title}</a>`).join('')}</nav><div class="p-content">${notice()}${state.pollError ? `<div class="notice notice-error inline"><p>Status could not be refreshed: ${esc(state.pollError)} Last refresh: ${date(Math.floor(state.lastRefresh / 1000))}.</p></div>` : ''}${state.status.storage_error ? `<div class="notice notice-error inline" role="alert"><p>Storage unavailable: ${esc(state.status.storage_error)}</p></div>` : ''}${state.status.schedule?.error ? `<div class="notice notice-error inline" role="alert"><p>Scheduled backup error: ${esc(state.status.schedule.error.message)} · ${date(state.status.schedule.error.time)}</p></div>` : ''}${view()}</div>`;
        app.querySelectorAll('details[data-detail]').forEach(element => {if (detailStates.has(element.dataset.detail)) element.open = detailStates.get(element.dataset.detail);});
    }
    async function saveSettings() {
        const s = settings(), data = {};
        keys.forEach(key => { if (s[key] !== undefined) data[key] = s[key]; });
        const saved = await api('/settings', 'POST', data);
        state.status.settings = saved;
        state.draft = {}; state.dirty = false; state.diagnostics = null; state.preflight = null;
        await refresh(false);
        state.notice = { type: 'success', text: 'Settings saved.' };
    }
    function requireSaved() {
        if (state.dirty) throw new Error('Save your changes first. Checks and jobs use the saved settings.');
    }
    app.addEventListener('input', event => {
        const input = event.target;
        if (!input.name || !keys.includes(input.name)) return;
        state.draft[input.name] = booleanKeys.has(input.name) ? input.checked : numberKeys.has(input.name) ? (input.value === '' ? '' : Number(input.value)) : input.value;
        state.dirty = true;
        const saveText = app.querySelector('.p-savebar > span');
        if (saveText) saveText.textContent = 'You have unsaved changes';
    });
    app.addEventListener('change', event => {
        const input = event.target;
        if (input.id === 'p-run-type') { state.type = input.value; state.preflight = null; render(); return; }
        if (!input.name || !keys.includes(input.name)) return;
        state.draft[input.name] = booleanKeys.has(input.name) ? input.checked : numberKeys.has(input.name) ? Number(input.value) : input.value;
        state.dirty = true;
        if (['schedule', 'api_mode'].includes(input.name)) {
            if (input.name === 'api_mode') {
                if (input.value === 'cloud') { state.draft.api_url = 'https://api.telegram.org'; state.draft.part_mb = Math.min(40, safeNumber(settings().part_mb) || 40); }
                else if (settings().api_url === 'https://api.telegram.org') state.draft.api_url = '';
            }
            render();
            app.querySelector(`[name="${input.name}"]`)?.focus();
        }
    });
    app.addEventListener('submit', event => {
        if (event.target.id !== 'p-settings-form') return;
        event.preventDefault();
        action('save', saveSettings);
    });
    app.addEventListener('click', event => {
        const button = event.target.closest('[data-action], [data-tab], [data-type], [data-chat]');
        if (!button || button.disabled) return;
        event.preventDefault();
        if (button.dataset.tab) {
            state.tab = button.dataset.tab; state.notice = null;
            window.history.replaceState(null, '', `#${state.tab}`);
            render(); app.querySelector('h1')?.setAttribute('tabindex', '-1'); app.querySelector('h1')?.focus({ preventScroll: true }); return;
        }
        if (button.dataset.type) { state.type = button.dataset.type; state.preflight = null; render(); return; }
        if (button.dataset.chat) { state.draft.chat_id = button.dataset.chat; state.draft.thread_id = Number(button.dataset.thread || 0); state.dirty = true; state.notice = { type: 'success', text: 'Chat selected. Save the settings.' }; render(); return; }
        const name = button.dataset.action, id = button.dataset.id;
        if (name === 'dismiss') { state.notice = null; render(); return; }
        if (name === 'refresh') return action('refresh', () => refresh(false));
        if (name === 'diagnostics' || name === 'test-message') return action('diagnostics', async () => { requireSaved(); state.diagnostics = await api('/diagnostics', 'POST', { send_test: name === 'test-message' }); });
        if (name === 'discover') return action('discover', async () => { requireSaved(); state.chats = (await api('/discover', 'POST', {})).chats || []; });
        if (name === 'preflight') return action('preflight', async () => { requireSaved(); state.preflight = await api('/preflight', 'POST', { type: state.type }); });
        if (name === 'enqueue') return action('enqueue', async () => { requireSaved(); await api('/jobs', 'POST', { type: state.type }); await refresh(false); state.notice = { type: 'success', text: 'Backup queued.' }; });
        if (name === 'system-cron-install' || name === 'system-cron-remove') return action(name, async () => {
            state.status = await api(name === 'system-cron-install' ? '/system-cron/install' : '/system-cron/remove', 'POST', {});
            state.lastRefresh = Date.now(); state.pollError = '';
            const cron = state.status.system_cron;
            if (!cron || (name === 'system-cron-install' ? !cron.installed : Boolean(cron.present || cron.installed))) throw new Error(cron?.error || cron?.reason || 'Could not confirm the system cron change. Refresh the status.');
            state.notice = { type: 'success', text: name === 'system-cron-install' ? 'System cron is installed. Wait for a background run to confirm it works.' : 'The plugin system cron entry was removed.' };
        });
        if (name === 'repair-schedule') return action('repair-schedule', async () => { requireSaved(); await api('/repair-schedule', 'POST', {}); await refresh(false); state.notice = { type: 'success', text: 'WordPress Cron events repaired.' }; });
        if (name === 'cleanup') return action('cleanup', async () => { requireSaved(); const result = await api('/cleanup', 'POST', {}); await refresh(false); state.notice = { type: 'success', text: `Cleanup completed. Local archives removed: ${Array.isArray(result.removed) ? result.removed.length : 0}. Telegram was not changed.` }; });
        if (name === 'worker') return action('worker', async () => { await api('/worker', 'POST', {}); await refresh(false); state.notice = { type: 'success', text: 'Queue step completed.' }; });
        if (name === 'logs') {
            if (state.openLog === id) { state.openLog = ''; render(); return; }
            return action(`logs-${id}`, async () => { state.openLog = id; state.logs[id] = (await api(`/logs?job=${encodeURIComponent(id)}`)).entries || []; });
        }
        if (['cancel', 'retry', 'delete'].includes(name) && id) {
            if (name === 'delete' && !window.confirm('Delete this local backup, its files, and log? Telegram messages will remain.')) return;
            return action(`job-${id}`, async () => { if (name === 'delete') await api(`/jobs/${encodeURIComponent(id)}`, 'DELETE'); else await api(`/jobs/${encodeURIComponent(id)}/${name}`, 'POST', {}); await refresh(false); state.notice = { type: 'success', text: name === 'delete' ? 'Local backup deleted. Telegram was not changed.' : name === 'cancel' ? 'Cancellation requested. Queue status refreshed.' : 'Job queued again.' }; });
        }
    });
    window.addEventListener('beforeunload', event => { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });
    const initialTab = window.location.hash.slice(1);
    if (['overview', 'connection', 'schedule', 'history', 'recovery'].includes(initialTab)) state.tab = initialTab;
    refresh().catch(error => { app.setAttribute('aria-busy', 'false'); app.innerHTML = `<h1>Telegram Backup</h1><div class="notice notice-error" role="alert"><p>${esc(textError(error))}</p><button class="button button-primary" id="p-reload">Try again</button></div>`; document.getElementById('p-reload')?.addEventListener('click', () => window.location.reload()); });
    setInterval(async () => {
        if (!state.status || document.hidden || state.busy.size) return;
        try { await refresh(['overview', 'history'].includes(state.tab)); }
        catch (error) { state.pollError = textError(error); if (['overview', 'history'].includes(state.tab)) render(); }
    }, 15000);
})();
