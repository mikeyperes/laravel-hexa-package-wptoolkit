@extends('layouts.app')
@section('title', 'WP Toolkit')
@section('header', 'WP Toolkit — Test Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Server + Username selector --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">1. Get WordPress Installs for cPanel Account</h2>

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
                <label class="block text-sm font-medium text-gray-700 mb-1">cPanel Username</label>
                <input type="text" id="cpanel-username" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-48" placeholder="e.g. username">
            </div>
            <div>
                <button id="btn-get-installs" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
                    <svg id="spinner-get-installs" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-get-installs">Get Installs</span>
                </button>
            </div>
        </div>

        {{-- Result banner --}}
        <div id="result-banner" class="hidden mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2"></div>
    </div>

    {{-- Raw Output --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Raw Output</h3>
        <div id="raw-output" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs font-mono whitespace-pre-wrap break-words min-h-[80px]">
            Waiting for request...
        </div>
    </div>

    {{-- Parsed Installs --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Parsed Installs</h3>
        <div id="parsed-installs" class="text-sm text-gray-500">
            No data yet.
        </div>
    </div>

    {{-- Credentials Test --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">2. Get Credentials (Admin Users) for Install</h2>
        <p class="text-xs text-gray-500 mb-4">Click "Get Credentials" on any install row above, or manually enter an install ID below.</p>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Install ID</label>
                <input type="text" id="cred-install-id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-24" placeholder="e.g. 165">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">WP Path</label>
                <input type="text" id="cred-wp-path" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-72" placeholder="/home/user/public_html">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">cPanel User</label>
                <input type="text" id="cred-username" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-36" placeholder="username">
            </div>
            <input type="hidden" id="cred-login-url" value="">
            <input type="hidden" id="cred-site-url" value="">
            <div>
                <button id="btn-get-creds" class="bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 flex items-center gap-2">
                    <svg id="spinner-get-creds" class="hidden animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span id="btn-text-get-creds">Get Credentials</span>
                </button>
            </div>
        </div>

        <div id="cred-banner" class="hidden mt-4 px-4 py-3 rounded-lg text-sm flex items-center gap-2"></div>

        <div class="mt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Credentials Raw Output</h3>
            <div id="cred-raw-output" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs font-mono whitespace-pre-wrap break-words min-h-[80px]">
                Waiting for request...
            </div>
        </div>

        <div class="mt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Admin Users</h3>
            <div id="cred-parsed" class="text-sm text-gray-500">
                No data yet.
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('btn-get-installs');
    const spinner = document.getElementById('spinner-get-installs');
    const btnText = document.getElementById('btn-text-get-installs');
    const banner = document.getElementById('result-banner');
    const rawOutput = document.getElementById('raw-output');
    const parsedDiv = document.getElementById('parsed-installs');

    btn.addEventListener('click', function() {
        const serverId = document.getElementById('server-select').value;
        const username = document.getElementById('cpanel-username').value.trim();

        if (!serverId || !username) {
            showBanner('error', 'Please select a server and enter a cPanel username.');
            return;
        }

        // Show spinner
        spinner.classList.remove('hidden');
        btnText.textContent = 'Scanning...';
        btn.disabled = true;
        banner.classList.add('hidden');
        rawOutput.textContent = 'Connecting to server...';
        parsedDiv.textContent = 'Scanning...';

        fetch('{{ route("wptoolkit.get-installs") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
            },
            body: JSON.stringify({
                server_id: serverId,
                username: username,
            }),
        })
        .then(r => r.json())
        .then(data => {
            // Raw output
            rawOutput.textContent = data.raw_output || JSON.stringify(data, null, 2);

            if (data.success) {
                const installs = data.installs || [];
                showBanner('success', 'Found ' + installs.length + ' WordPress install(s).');

                if (installs.length === 0) {
                    parsedDiv.textContent = 'No WordPress installs found for this account.';
                } else {
                    let html = '<table class="w-full text-left text-sm">';
                    html += '<thead><tr class="border-b border-gray-200">';
                    html += '<th class="py-2 px-2">ID</th><th class="py-2 px-2">URL</th><th class="py-2 px-2">Path</th><th class="py-2 px-2">Version</th><th class="py-2 px-2">Actions</th>';
                    html += '</tr></thead><tbody>';
                    installs.forEach(function(inst) {
                        const escapedPath = (inst.path || '').replace(/'/g, "\\'");
                        const escapedLoginUrl = (inst.login_url || inst.url || '').replace(/'/g, "\\'");
                        const escapedSiteUrl = (inst.url || '').replace(/'/g, "\\'");
                        html += '<tr class="border-b border-gray-100">';
                        html += '<td class="py-2 px-2">' + (inst.id || '-') + '</td>';
                        html += '<td class="py-2 px-2 break-words">' + (inst.url || '-') + '</td>';
                        html += '<td class="py-2 px-2 break-words">' + (inst.path || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (inst.version || '-') + '</td>';
                        html += '<td class="py-2 px-2"><button onclick="fillCredForm(' + (inst.id || 0) + ', \'' + escapedPath + '\', \'' + escapedLoginUrl + '\', \'' + escapedSiteUrl + '\')" class="text-purple-600 hover:text-purple-800 text-xs font-medium">Get Credentials</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    parsedDiv.innerHTML = html;
                }
            } else {
                showBanner('error', data.error || 'Unknown error.');
                parsedDiv.textContent = 'Error: ' + (data.error || 'Unknown');
            }
        })
        .catch(err => {
            rawOutput.textContent = 'Request failed: ' + err.message;
            showBanner('error', 'Request failed: ' + err.message);
            parsedDiv.textContent = 'Error';
        })
        .finally(() => {
            spinner.classList.add('hidden');
            btnText.textContent = 'Get Installs';
            btn.disabled = false;
        });
    });

    // --- Credentials ---
    const credBtn = document.getElementById('btn-get-creds');
    const credSpinner = document.getElementById('spinner-get-creds');
    const credBtnText = document.getElementById('btn-text-get-creds');
    const credBanner = document.getElementById('cred-banner');
    const credRaw = document.getElementById('cred-raw-output');
    const credParsed = document.getElementById('cred-parsed');

    window.fillCredForm = function(installId, wpPath, loginUrl, siteUrl) {
        document.getElementById('cred-install-id').value = installId;
        document.getElementById('cred-wp-path').value = wpPath;
        document.getElementById('cred-login-url').value = loginUrl;
        document.getElementById('cred-site-url').value = siteUrl || '';
        document.getElementById('cred-username').value = document.getElementById('cpanel-username').value.trim();
        credBtn.click();
    };

    credBtn.addEventListener('click', function() {
        const serverId = document.getElementById('server-select').value;
        const installId = document.getElementById('cred-install-id').value.trim();
        const wpPath = document.getElementById('cred-wp-path').value.trim();
        const username = document.getElementById('cred-username').value.trim();
        const loginUrl = document.getElementById('cred-login-url').value;

        if (!serverId || !installId || !wpPath || !username) {
            showCredBanner('error', 'Fill in all fields or click Get Credentials on an install row.');
            return;
        }

        credSpinner.classList.remove('hidden');
        credBtnText.textContent = 'Loading...';
        credBtn.disabled = true;
        credBanner.classList.add('hidden');
        credRaw.textContent = 'Connecting to server...';
        credParsed.textContent = 'Loading...';

        fetch('{{ route("wptoolkit.get-credentials") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
            },
            body: JSON.stringify({
                server_id: serverId,
                install_id: installId,
                wp_path: wpPath,
                username: username,
                login_url: loginUrl,
            }),
        })
        .then(r => r.json())
        .then(data => {
            credRaw.textContent = data.raw_output || JSON.stringify(data, null, 2);

            if (data.success) {
                const users = data.admin_users || [];
                showCredBanner('success', 'Found ' + users.length + ' admin user(s).' + (data.login_url ? ' Login URL: ' + data.login_url : ''));

                if (users.length === 0) {
                    credParsed.textContent = 'No admin users found.';
                } else {
                    let html = '';

                    // Quick login buttons
                    const cpUser = document.getElementById('cred-username').value.trim();
                    html += '<div class="mb-4 flex flex-wrap gap-2">';
                    html += '<button onclick="doCpanelLogin()" class="bg-orange-600 text-white px-3 py-1.5 rounded text-xs hover:bg-orange-700">cPanel Login ↗</button>';
                    html += '<button onclick="doWhmResellerLogin()" class="bg-red-600 text-white px-3 py-1.5 rounded text-xs hover:bg-red-700">WHM Reseller Login ↗</button>';
                    html += '</div>';

                    if (data.login_url) {
                        html += '<div class="mb-3 text-xs"><strong>WP Toolkit Login URL:</strong> <a href="' + data.login_url + '" target="_blank" class="text-blue-600 hover:underline">' + data.login_url + ' ↗</a></div>';
                    }
                    html += '<table class="w-full text-left text-sm">';
                    html += '<thead><tr class="border-b border-gray-200">';
                    html += '<th class="py-2 px-2">ID</th><th class="py-2 px-2">Username</th><th class="py-2 px-2">Email</th><th class="py-2 px-2">Display Name</th><th class="py-2 px-2">Actions</th>';
                    html += '</tr></thead><tbody>';
                    users.forEach(function(u) {
                        const safeLogin = (u.user_login || '').replace(/'/g, "\\'");
                        html += '<tr class="border-b border-gray-100">';
                        html += '<td class="py-2 px-2">' + (u.id || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (u.user_login || '-') + '</td>';
                        html += '<td class="py-2 px-2 break-words">' + (u.user_email || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (u.display_name || '-') + '</td>';
                        html += '<td class="py-2 px-2"><button onclick="doWpLogin(\'' + safeLogin + '\')" class="bg-green-600 text-white px-2 py-1 rounded text-xs hover:bg-green-700">WP Login ↗</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    credParsed.innerHTML = html;
                }
            } else {
                showCredBanner('error', data.error || 'Unknown error.');
                credParsed.textContent = 'Error: ' + (data.error || 'Unknown');
            }
        })
        .catch(err => {
            credRaw.textContent = 'Request failed: ' + err.message;
            showCredBanner('error', 'Request failed: ' + err.message);
            credParsed.textContent = 'Error';
        })
        .finally(() => {
            credSpinner.classList.add('hidden');
            credBtnText.textContent = 'Get Credentials';
            credBtn.disabled = false;
        });
    });

    // --- Login actions ---
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

    window.doWpLogin = function(wpUser) {
        const serverId = document.getElementById('server-select').value;
        const wpPath = document.getElementById('cred-wp-path').value;
        const username = document.getElementById('cred-username').value;
        const siteUrl = document.getElementById('cred-site-url').value;

        if (!serverId || !wpPath || !username || !wpUser || !siteUrl) {
            showCredBanner('error', 'Missing data for WP login.');
            return;
        }

        showCredBanner('success', 'Generating WP login URL...');

        fetch('{{ route("wptoolkit.wp-login") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ server_id: serverId, wp_path: wpPath, username: username, wp_user: wpUser, site_url: siteUrl }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                showCredBanner('success', 'Login URL generated (expires in ' + (data.expires_in || 300) + 's). Opening...');
                window.open(data.url, '_blank');
            } else {
                showCredBanner('error', data.error || 'Failed to generate login URL.');
            }
        })
        .catch(err => showCredBanner('error', 'Request failed: ' + err.message));
    };

    window.doCpanelLogin = function() {
        const serverId = document.getElementById('server-select').value;
        const username = document.getElementById('cred-username').value;

        if (!serverId || !username) {
            showCredBanner('error', 'Missing server or username.');
            return;
        }

        showCredBanner('success', 'Generating cPanel login URL...');

        fetch('{{ route("wptoolkit.cpanel-login") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ server_id: serverId, username: username }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                showCredBanner('success', 'cPanel login URL generated. Opening...');
                window.open(data.url, '_blank');
            } else {
                showCredBanner('error', data.error || 'Failed to generate cPanel login URL.');
            }
        })
        .catch(err => showCredBanner('error', 'Request failed: ' + err.message));
    };

    window.doWhmResellerLogin = function() {
        const serverId = document.getElementById('server-select').value;
        const username = document.getElementById('cred-username').value;

        if (!serverId || !username) {
            showCredBanner('error', 'Missing server or username.');
            return;
        }

        showCredBanner('success', 'Generating WHM reseller login URL...');

        fetch('{{ route("wptoolkit.whm-reseller-login") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ server_id: serverId, username: username }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                showCredBanner('success', 'WHM reseller login URL generated. Opening...');
                window.open(data.url, '_blank');
            } else {
                showCredBanner('error', data.error || 'Failed to generate WHM login URL. Account may not be a reseller.');
            }
        })
        .catch(err => showCredBanner('error', 'Request failed: ' + err.message));
    };

    function showCredBanner(type, message) {
        credBanner.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800');
        if (type === 'success') {
            credBanner.classList.add('bg-green-100', 'text-green-800');
        } else {
            credBanner.classList.add('bg-red-100', 'text-red-800');
        }
        credBanner.textContent = message;
    }

    function showBanner(type, message) {
        banner.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800');
        if (type === 'success') {
            banner.classList.add('bg-green-100', 'text-green-800');
            banner.innerHTML = '<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> ' + message;
        } else {
            banner.classList.add('bg-red-100', 'text-red-800');
            banner.innerHTML = '<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg> ' + message;
        }
    }
});
</script>
@endsection
