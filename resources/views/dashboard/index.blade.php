@extends('layouts.app')
@section('title', 'WP Toolkit')
@section('header', 'WP Toolkit')

@section('content')
<div class="space-y-6">

    {{-- Step 1: Select server and scan --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">WordPress Installs</h2>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">WHM Server</label>
                <select id="server-select" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64">
                    <option value="">Select server...</option>
                    @foreach($servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->hostname }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button id="btn-scan" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
                    <svg id="spinner-scan" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-scan">Scan Server</span>
                </button>
            </div>
        </div>

        {{-- Result banner --}}
        <div id="scan-banner" class="hidden mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2"></div>

        {{-- Installs table --}}
        <div id="installs-table" class="hidden mt-4">
            <div id="installs-content" class="text-sm text-gray-500"></div>
        </div>
    </div>

    {{-- Step 2: Selected install actions --}}
    <div id="actions-panel" class="hidden bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">WordPress Actions</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Left: Install info --}}
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Site URL</label>
                    <div id="action-site-url" class="text-sm font-semibold text-gray-900 break-words">-</div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">cPanel User</label>
                    <div id="action-cpanel-user" class="text-sm text-gray-700">-</div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Path</label>
                    <div id="action-wp-path" class="text-sm text-gray-700 break-words">-</div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Version</label>
                    <div id="action-version" class="text-sm text-gray-700">-</div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Login URL</label>
                    <div id="action-login-url" class="text-sm break-words">-</div>
                </div>
                <div id="action-custom-login-row" class="hidden">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Custom Login URL (modified wp-admin)</label>
                    <div id="action-custom-login-url" class="text-sm break-words font-semibold text-orange-600">-</div>
                </div>

                {{-- Users (loaded after selecting install) --}}
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Users</label>
                    <div id="action-admin-users" class="text-sm text-gray-500">
                        <span id="admin-users-loading" class="hidden flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Loading admin users...
                        </span>
                        <div id="admin-users-list"></div>
                    </div>
                </div>
            </div>

            {{-- Right: Action buttons --}}
            <div class="space-y-3">
                {{-- One-click WP Login --}}
                <button id="btn-wp-login" class="w-full bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm hover:bg-green-700 flex items-center justify-center gap-2">
                    <svg id="spinner-wp-login" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-wp-login">One-Click WP Admin Login</span>
                </button>

                {{-- Reset Password --}}
                <button id="btn-reset-pass" class="w-full bg-red-600 text-white px-4 py-2.5 rounded-lg text-sm hover:bg-red-700 flex items-center justify-center gap-2">
                    <svg id="spinner-reset" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-reset">Reset Password</span>
                </button>

                {{-- cPanel Login --}}
                <button id="btn-cpanel-login" class="w-full bg-orange-600 text-white px-4 py-2.5 rounded-lg text-sm hover:bg-orange-700 flex items-center justify-center gap-2">
                    <svg id="spinner-cpanel" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-cpanel">cPanel Login</span>
                </button>

                {{-- Password display box --}}
                <div id="password-box" class="hidden bg-gray-900 rounded-lg p-4">
                    <label class="block text-xs font-medium text-gray-400 mb-1">New Password</label>
                    <div class="flex items-center gap-2">
                        <span id="password-value" class="text-green-400 font-mono text-sm break-words"></span>
                        <button id="btn-copy-pass" class="text-gray-400 hover:text-white text-xs px-2 py-1 border border-gray-600 rounded">Copy</button>
                    </div>
                    <div id="copy-confirm" class="hidden text-green-400 text-xs mt-1">Copied</div>
                </div>
            </div>
        </div>

        {{-- Action result banner --}}
        <div id="action-banner" class="hidden mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2"></div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

    // Current selection state
    let currentInstall = null;  // {id, path, url, cpanel_user, version}
    let currentAdminUser = null; // wp username string

    // --- Scan server ---
    document.getElementById('btn-scan').addEventListener('click', function() {
        const serverId = document.getElementById('server-select').value;
        if (!serverId) {
            showBanner('scan-banner', 'error', 'Please select a server.');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('spinner-scan');
        const btnText = document.getElementById('btn-text-scan');

        spinner.classList.remove('hidden');
        btnText.textContent = 'Scanning...';
        btn.disabled = true;
        document.getElementById('actions-panel').classList.add('hidden');
        currentInstall = null;
        currentAdminUser = null;

        fetch('{{ route("wptoolkit.get-all-installs") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ server_id: serverId }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const installs = data.installs || [];
                showBanner('scan-banner', 'success', 'Found ' + installs.length + ' WordPress install(s).');
                renderInstalls(installs);
            } else {
                showBanner('scan-banner', 'error', data.error || 'Scan failed.');
                document.getElementById('installs-table').classList.add('hidden');
            }
        })
        .catch(err => {
            showBanner('scan-banner', 'error', 'Request failed: ' + err.message);
        })
        .finally(() => {
            spinner.classList.add('hidden');
            btnText.textContent = 'Scan Server';
            btn.disabled = false;
        });
    });

    function renderInstalls(installs) {
        const container = document.getElementById('installs-content');
        const wrapper = document.getElementById('installs-table');

        if (installs.length === 0) {
            container.textContent = 'No WordPress installs found on this server.';
            wrapper.classList.remove('hidden');
            return;
        }

        let html = '<table class="w-full text-left text-sm">';
        html += '<thead><tr class="border-b border-gray-200">';
        html += '<th class="py-2 px-2">ID</th>';
        html += '<th class="py-2 px-2">Site URL</th>';
        html += '<th class="py-2 px-2">cPanel User</th>';
        html += '<th class="py-2 px-2">Version</th>';
        html += '<th class="py-2 px-2">Status</th>';
        html += '</tr></thead><tbody>';

        installs.forEach(function(inst, idx) {
            html += '<tr class="border-b border-gray-100 cursor-pointer hover:bg-blue-50" data-idx="' + idx + '">';
            html += '<td class="py-2 px-2">' + (inst.id || '-') + '</td>';
            html += '<td class="py-2 px-2 break-words">' + (inst.url || '-') + '</td>';
            html += '<td class="py-2 px-2">' + (inst.cpanel_user || '-') + '</td>';
            html += '<td class="py-2 px-2">' + (inst.version || '-') + '</td>';
            html += '<td class="py-2 px-2">' + (inst.status || '-') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
        wrapper.classList.remove('hidden');

        // Attach click handlers
        container.querySelectorAll('tr[data-idx]').forEach(function(row) {
            row.addEventListener('click', function() {
                const idx = parseInt(this.dataset.idx);
                selectInstall(installs[idx]);

                // Highlight selected row
                container.querySelectorAll('tr[data-idx]').forEach(r => r.classList.remove('bg-blue-100'));
                this.classList.add('bg-blue-100');
            });
        });
    }

    function selectInstall(inst) {
        currentInstall = inst;
        currentAdminUser = null;

        // Populate action panel
        document.getElementById('action-site-url').textContent = inst.url || '-';
        document.getElementById('action-cpanel-user').textContent = inst.cpanel_user || '-';
        document.getElementById('action-wp-path').textContent = inst.path || '-';
        document.getElementById('action-version').textContent = inst.version || '-';

        const loginUrl = inst.url ? inst.url.replace(/\/$/, '') + '/wp-login.php' : null;
        const loginUrlEl = document.getElementById('action-login-url');
        if (loginUrl) {
            loginUrlEl.innerHTML = '<a href="' + loginUrl + '" target="_blank" class="text-blue-600 hover:underline">' + loginUrl + ' &#8599;</a>';
        } else {
            loginUrlEl.textContent = '-';
        }

        // Reset state
        document.getElementById('password-box').classList.add('hidden');
        document.getElementById('action-banner').classList.add('hidden');
        document.getElementById('admin-users-list').innerHTML = '';

        // Show panel
        document.getElementById('actions-panel').classList.remove('hidden');
        document.getElementById('actions-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Auto-load admin users
        loadAdminUsers(inst);
    }

    function loadAdminUsers(inst) {
        const loading = document.getElementById('admin-users-loading');
        const list = document.getElementById('admin-users-list');
        loading.classList.remove('hidden');
        list.innerHTML = '';

        // Reset custom login URL
        document.getElementById('action-custom-login-row').classList.add('hidden');

        const serverId = document.getElementById('server-select').value;

        fetch('{{ route("wptoolkit.get-credentials") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                server_id: serverId,
                install_id: inst.id,
                wp_path: inst.path,
                username: inst.cpanel_user,
                login_url: inst.url,
            }),
        })
        .then(r => r.json())
        .then(data => {
            loading.classList.add('hidden');

            // Show custom login URL if detected
            if (data.custom_login_url) {
                const customRow = document.getElementById('action-custom-login-row');
                const customEl = document.getElementById('action-custom-login-url');
                const fullUrl = (inst.url || '').replace(/\/$/, '') + '/' + data.custom_login_url;
                customEl.innerHTML = '<a href="' + fullUrl + '" target="_blank" class="text-orange-600 hover:underline">' + fullUrl + ' &#8599;</a>';
                customRow.classList.remove('hidden');
            }

            if (data.success) {
                const users = data.admin_users || [];
                if (users.length === 0) {
                    list.innerHTML = '<span class="text-gray-400">No users found.</span>';
                    return;
                }

                // Auto-select first administrator
                const firstAdmin = users.find(u => u.roles && u.roles.indexOf('administrator') !== -1);
                currentAdminUser = firstAdmin ? firstAdmin.user_login : users[0].user_login;

                let html = '<table class="w-full text-left text-xs">';
                html += '<thead><tr class="border-b border-gray-200">';
                html += '<th class="py-1.5 px-2">Username</th>';
                html += '<th class="py-1.5 px-2">Email</th>';
                html += '<th class="py-1.5 px-2">Role</th>';
                html += '<th class="py-1.5 px-2">Registered</th>';
                html += '</tr></thead><tbody>';

                users.forEach(function(u) {
                    const isSelected = u.user_login === currentAdminUser;
                    const roles = u.roles || '-';
                    const registered = u.user_registered || '-';
                    const isAdmin = roles.indexOf('administrator') !== -1;

                    html += '<tr class="border-b border-gray-100 cursor-pointer hover:bg-gray-100 admin-user-row' + (isSelected ? ' bg-blue-50' : '') + '" data-wp-user="' + (u.user_login || '') + '">';
                    html += '<td class="py-1.5 px-2 font-medium text-gray-900">' + (u.user_login || '-') + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-500 break-words">' + (u.user_email || '-') + '</td>';
                    html += '<td class="py-1.5 px-2"><span class="inline-block px-1.5 py-0.5 rounded text-xs ' + (isAdmin ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') + '">' + roles + '</span></td>';
                    html += '<td class="py-1.5 px-2 text-gray-400">' + registered + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                list.innerHTML = html;

                // Click to select different user
                list.querySelectorAll('.admin-user-row').forEach(function(row) {
                    row.addEventListener('click', function() {
                        currentAdminUser = this.dataset.wpUser;
                        list.querySelectorAll('.admin-user-row').forEach(r => r.classList.remove('bg-blue-50'));
                        this.classList.add('bg-blue-50');
                    });
                });
            } else {
                list.innerHTML = '<span class="text-red-500">' + (data.error || 'Failed to load users.') + '</span>';
            }
        })
        .catch(err => {
            loading.classList.add('hidden');
            list.innerHTML = '<span class="text-red-500">Request failed: ' + err.message + '</span>';
        });
    }

    // --- One-click WP login ---
    document.getElementById('btn-wp-login').addEventListener('click', function() {
        if (!currentInstall || !currentAdminUser) {
            showBanner('action-banner', 'error', 'Select an install and admin user first.');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('spinner-wp-login');
        const btnText = document.getElementById('btn-text-wp-login');
        const serverId = document.getElementById('server-select').value;

        spinner.classList.remove('hidden');
        btnText.textContent = 'Generating...';
        btn.disabled = true;

        fetch('{{ route("wptoolkit.wp-login") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                server_id: serverId,
                wp_path: currentInstall.path,
                username: currentInstall.cpanel_user,
                wp_user: currentAdminUser,
                site_url: currentInstall.url,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                showBanner('action-banner', 'success', 'Login URL generated (expires in ' + (data.expires_in || 300) + 's). Opening...');
                window.open(data.url, '_blank');
            } else {
                showBanner('action-banner', 'error', data.error || 'Failed to generate login URL.');
            }
        })
        .catch(err => showBanner('action-banner', 'error', 'Request failed: ' + err.message))
        .finally(() => {
            spinner.classList.add('hidden');
            btnText.textContent = 'One-Click WP Admin Login';
            btn.disabled = false;
        });
    });

    // --- Reset password ---
    document.getElementById('btn-reset-pass').addEventListener('click', function() {
        if (!currentInstall || !currentAdminUser) {
            showBanner('action-banner', 'error', 'Select an install and admin user first.');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('spinner-reset');
        const btnText = document.getElementById('btn-text-reset');
        const serverId = document.getElementById('server-select').value;

        spinner.classList.remove('hidden');
        btnText.textContent = 'Resetting...';
        btn.disabled = true;
        document.getElementById('password-box').classList.add('hidden');

        fetch('{{ route("wptoolkit.reset-password") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                server_id: serverId,
                install_id: currentInstall.id,
                wp_path: currentInstall.path,
                username: currentInstall.cpanel_user,
                wp_user: currentAdminUser,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.password) {
                showBanner('action-banner', 'success', 'Password reset for ' + data.wp_user);
                document.getElementById('password-value').textContent = data.password;
                document.getElementById('password-box').classList.remove('hidden');
            } else {
                showBanner('action-banner', 'error', data.error || 'Password reset failed.');
            }
        })
        .catch(err => showBanner('action-banner', 'error', 'Request failed: ' + err.message))
        .finally(() => {
            spinner.classList.add('hidden');
            btnText.textContent = 'Reset Password';
            btn.disabled = false;
        });
    });

    // --- cPanel login ---
    document.getElementById('btn-cpanel-login').addEventListener('click', function() {
        if (!currentInstall) {
            showBanner('action-banner', 'error', 'Select an install first.');
            return;
        }

        const btn = this;
        const spinner = document.getElementById('spinner-cpanel');
        const btnText = document.getElementById('btn-text-cpanel');
        const serverId = document.getElementById('server-select').value;

        spinner.classList.remove('hidden');
        btnText.textContent = 'Generating...';
        btn.disabled = true;

        fetch('{{ route("wptoolkit.cpanel-login") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                server_id: serverId,
                username: currentInstall.cpanel_user,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                showBanner('action-banner', 'success', 'cPanel login URL generated. Opening...');
                window.open(data.url, '_blank');
            } else {
                showBanner('action-banner', 'error', data.error || 'Failed to generate cPanel login URL.');
            }
        })
        .catch(err => showBanner('action-banner', 'error', 'Request failed: ' + err.message))
        .finally(() => {
            spinner.classList.add('hidden');
            btnText.textContent = 'cPanel Login';
            btn.disabled = false;
        });
    });

    // --- Copy password ---
    document.getElementById('btn-copy-pass').addEventListener('click', function() {
        const pass = document.getElementById('password-value').textContent;
        if (pass) {
            navigator.clipboard.writeText(pass);
            const confirm = document.getElementById('copy-confirm');
            confirm.classList.remove('hidden');
            setTimeout(() => confirm.classList.add('hidden'), 2000);
        }
    });

    // --- Banner helper ---
    function showBanner(id, type, message) {
        const el = document.getElementById(id);
        el.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800');
        if (type === 'success') {
            el.classList.add('bg-green-100', 'text-green-800');
            el.innerHTML = '<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> ' + message;
        } else {
            el.classList.add('bg-red-100', 'text-red-800');
            el.innerHTML = '<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg> ' + message;
        }
    }
});
</script>
@endsection
