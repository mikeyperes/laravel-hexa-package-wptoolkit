document.addEventListener('DOMContentLoaded', function() {
    const configNode = document.getElementById("wptoolkit-raw-config");
    const config = configNode ? JSON.parse(configNode.textContent) : {};
    const csrfToken = config.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || "";

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

        fetch(config.routes.getAllInstalls, {
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

        // Show default login URL initially — will be overwritten by WP Toolkit data if available
        const loginUrlEl = document.getElementById('action-login-url');
        loginUrlEl.innerHTML = '<span class="text-gray-400 text-xs">Loading from WP Toolkit...</span>';
        document.getElementById('action-login-modified').classList.add('hidden');

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

        // Reset creds and login info
        document.getElementById('action-login-modified').classList.add('hidden');
        document.getElementById('stored-creds-row').classList.add('hidden');
        document.getElementById('db-creds-row').classList.add('hidden');

        const serverId = document.getElementById('server-select').value;

        fetch(config.routes.getCredentials, {
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

            // Show login URL from WP Toolkit (authoritative)
            if (data.login_info && data.login_info.url) {
                const loginUrlEl = document.getElementById('action-login-url');
                loginUrlEl.innerHTML = '<a href="' + data.login_info.url + '" target="_blank" class="text-blue-600 hover:underline">' + data.login_info.url + ' &#8599;</a>';

                if (data.login_info.is_modified) {
                    const modifiedEl = document.getElementById('action-login-modified');
                    modifiedEl.classList.remove('hidden');
                    if (data.login_info.default_url) {
                        document.getElementById('action-login-default').textContent = data.login_info.default_url;
                    }
                }
            }

            // Show stored WP credentials if available
            if (data.stored_credentials && data.stored_credentials.username) {
                document.getElementById('stored-username').textContent = data.stored_credentials.username;
                document.getElementById('stored-password').textContent = data.stored_credentials.password || '(not stored)';
                document.getElementById('stored-creds-row').classList.remove('hidden');
            }

            // Show DB credentials if available
            if (data.db_credentials) {
                document.getElementById('db-host').textContent = data.db_credentials.db_host || '-';
                document.getElementById('db-name').textContent = data.db_credentials.db_name || '-';
                document.getElementById('db-user').textContent = data.db_credentials.db_user || '-';
                document.getElementById('db-password').textContent = data.db_credentials.db_password || '-';
                document.getElementById('db-creds-row').classList.remove('hidden');
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

        fetch(config.routes.wpLogin, {
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

        fetch(config.routes.resetPassword, {
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

        fetch(config.routes.cpanelLogin, {
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

    // --- Copy buttons ---
    document.getElementById('btn-copy-pass').addEventListener('click', function() {
        copyAndConfirm('password-value', 'copy-confirm');
    });

    document.getElementById('btn-copy-stored-pass').addEventListener('click', function() {
        copyAndConfirm('stored-password', 'stored-copy-confirm');
    });

    document.getElementById('btn-copy-db-pass').addEventListener('click', function() {
        copyAndConfirm('db-password', 'db-copy-confirm');
    });

    function copyAndConfirm(valueId, confirmId) {
        const val = document.getElementById(valueId).textContent;
        if (val) {
            navigator.clipboard.writeText(val);
            const el = document.getElementById(confirmId);
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 2000);
        }
    }

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
