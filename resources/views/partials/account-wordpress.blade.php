{{--
/**
 * WordPress Installations Partial
 * ================================
 * Reusable collapsible section showing WordPress installations for a hosting account.
 * Provides scan, credential lookup, auto-login, and password reset functionality.
 *
 * @param \hexa_package_whm\Models\WhmServer $server  The WHM server instance
 * @param \hexa_package_whm\Models\HostingAccount $account  The hosting account instance
 *
 * Usage:
 *   @include('wptoolkit::partials.account-wordpress', ['server' => $server, 'account' => $account])
 */
--}}

@if(Route::has('wptoolkit.get-installs'))
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6"
     x-data="{
        wpOpen: true,
        wpLoading: false,
        wpInstalls: null,
        wpCredentials: {},
        wpCredLoading: {},
        wpAutoLogging: {},
        wpPasswordVisible: {},
        wpResetForm: null,
        wpResetResult: null,
        wpScanError: null,

        /**
         * Scan for WordPress installations on this account
         */
        wpScan() {
            this.wpLoading = true;
            this.wpScanError = null;
            fetch('{{ route('wptoolkit.get-installs') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: JSON.stringify({ server_id: {{ $server->id }}, username: '{{ $account->username }}' })
            }).then(r => r.json()).then(d => {
                this.wpInstalls = d.installs || [];
                this.wpLoading = false;
                this.wpOpen = true;
            }).catch(e => {
                this.wpLoading = false;
                this.wpScanError = 'Failed to scan: ' + (e.message || 'Unknown error');
            });
        },

        /**
         * Fetch credentials for a specific WordPress installation
         * @param {Object} wp  The WordPress install object from scan results
         */
        wpFetchCreds(wp) {
            this.wpCredLoading[wp.path] = true;
            fetch('{{ route('wptoolkit.get-credentials') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: JSON.stringify({ server_id: {{ $server->id }}, install_id: wp.id, wp_path: wp.path, username: '{{ $account->username }}', login_url: wp.url })
            }).then(r => r.json()).then(d => {
                this.wpCredentials[wp.path] = d;
                this.wpCredLoading[wp.path] = false;
            }).catch(e => {
                this.wpCredentials[wp.path] = { error: e.message || 'Failed to fetch credentials' };
                this.wpCredLoading[wp.path] = false;
            });
        },

        /**
         * One-click auto-login to WordPress admin
         * @param {Object} wp      The WordPress install object
         * @param {string} wpUser  The WordPress username to log in as
         */
        wpAutoLogin(wp, wpUser) {
            this.wpAutoLogging[wp.path] = true;
            fetch('{{ route('wptoolkit.wp-login') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: JSON.stringify({ server_id: {{ $server->id }}, wp_path: wp.path, username: '{{ $account->username }}', wp_user: wpUser, site_url: wp.url })
            }).then(r => r.json()).then(d => {
                this.wpAutoLogging[wp.path] = false;
                if (d.url) window.open(d.url, '_blank');
                else alert('Auto-login failed: no URL returned');
            }).catch(e => {
                this.wpAutoLogging[wp.path] = false;
                alert('Auto-login failed: ' + (e.message || 'Unknown error'));
            });
        },

        /**
         * Open the password reset modal for a specific user
         * @param {Object} wp    The WordPress install object
         * @param {Object} user  The WordPress user object
         */
        wpOpenReset(wp, user) {
            this.wpResetForm = {
                path: wp.path,
                installId: wp.id,
                wpPath: wp.path,
                username: user.username || user.user_login || 'admin',
                email: user.email || user.user_email || '',
                password: '',
                show: true,
                saving: false
            };
            this.wpResetResult = null;
        },

        /**
         * Generate a random 24-character password
         */
        wpGeneratePassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let pw = '';
            for (let i = 0; i < 24; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
            this.wpResetForm.password = pw;
        },

        /**
         * Execute the password reset via the API
         */
        wpDoReset() {
            if (!this.wpResetForm.password) return;
            this.wpResetForm.saving = true;
            this.wpResetResult = null;
            fetch('{{ route('wptoolkit.reset-password') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: JSON.stringify({ server_id: {{ $server->id }}, install_id: this.wpResetForm.installId, wp_path: this.wpResetForm.wpPath, username: '{{ $account->username }}', wp_user: this.wpResetForm.username })
            }).then(r => r.json()).then(d => {
                this.wpResetForm.saving = false;
                if (d.password) {
                    this.wpResetResult = { success: true, message: 'Password reset successfully. New password: ' + d.password, password: d.password };
                } else {
                    this.wpResetResult = { success: false, message: d.error || d.message || 'Password reset failed' };
                }
            }).catch(e => {
                this.wpResetForm.saving = false;
                this.wpResetResult = { success: false, message: 'Request failed: ' + (e.message || 'Unknown error') };
            });
        }
     }">

    {{-- Section Header (entire header is clickable for expand/collapse) --}}
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between cursor-pointer" @click="wpOpen = !wpOpen">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
            WordPress Installations
            <template x-if="wpInstalls !== null">
                <span class="text-xs font-normal text-gray-400" x-text="wpInstalls.length + ' found'"></span>
            </template>
        </h2>
        <div class="flex items-center gap-3">
            {{-- Scan button (stop propagation so it doesn't toggle collapse) --}}
            <button @click.stop="wpScan()" :disabled="wpLoading"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 border border-blue-200 disabled:opacity-50">
                <svg x-show="!wpLoading" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <svg x-show="wpLoading" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-text="wpLoading ? 'Scanning...' : 'Scan'"></span>
            </button>
            {{-- Chevron --}}
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="wpOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
    </div>

    {{-- Section Body --}}
    <div x-show="wpOpen" x-collapse>
        <div class="p-6">

            {{-- Scan Error Banner --}}
            <template x-if="wpScanError">
                <div class="rounded-lg px-4 py-3 text-sm font-medium bg-red-50 border border-red-200 text-red-800 mb-4">
                    <span x-text="wpScanError"></span>
                </div>
            </template>

            {{-- Empty state: no installs found --}}
            <template x-if="wpInstalls !== null && wpInstalls.length === 0">
                <div class="text-center py-8">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    <p class="text-sm text-gray-500">No WordPress installations found.</p>
                </div>
            </template>

            {{-- Not scanned yet --}}
            <template x-if="wpInstalls === null && !wpLoading">
                <div class="text-center py-8">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <p class="text-sm text-gray-500">Click "Scan" to detect WordPress installations on this account.</p>
                </div>
            </template>

            {{-- Loading spinner --}}
            <div x-show="wpLoading" class="flex items-center justify-center gap-2 py-8 text-sm text-gray-500">
                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Scanning for WordPress installations...
            </div>

            {{-- Install Cards --}}
            <template x-if="wpInstalls !== null && wpInstalls.length > 0">
                <div class="grid grid-cols-1 gap-4">
                    <template x-for="(wp, wpIdx) in wpInstalls" :key="wp.path || wpIdx">
                        <div class="border border-gray-200 rounded-xl p-5 hover:border-blue-200 transition-colors">

                            {{-- Card Header: Site URL + Version Badge --}}
                            <div class="flex items-start justify-between mb-3">
                                <div class="min-w-0 flex-1">
                                    <a :href="wp.url" target="_blank" class="text-sm font-semibold text-blue-600 hover:text-blue-800 hover:underline break-words inline-flex items-center gap-1">
                                        <span x-text="wp.url"></span>
                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                    <p class="text-xs text-gray-400 font-mono mt-0.5 break-words" x-text="wp.relative_path || wp.path"></p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 shrink-0 ml-3" x-text="'WP ' + (wp.version || '?')"></span>
                            </div>

                            {{-- Admin user + Credential status badge --}}
                            <div class="flex items-center gap-3 mb-3 text-xs text-gray-500">
                                <span class="inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span x-text="wp.admin_user || 'admin'"></span>
                                </span>

                                {{-- Green badge: credentials loaded successfully --}}
                                <template x-if="wpCredentials[wp.path] && !wpCredentials[wp.path].error">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Credentials found
                                    </span>
                                </template>

                                {{-- Red badge: credential fetch failed --}}
                                <template x-if="wpCredentials[wp.path] && wpCredentials[wp.path].error">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        No credentials
                                    </span>
                                </template>
                            </div>

                            {{-- Default Login URL (before credentials loaded) --}}
                            <template x-if="!wpCredentials[wp.path]">
                                <div class="flex items-center gap-2 mb-3 text-xs">
                                    <a :href="wp.url + '/wp-login.php'" target="_blank" class="text-gray-400 hover:text-blue-600 hover:underline inline-flex items-center gap-1 break-words">
                                        <span x-text="wp.url + '/wp-login.php'"></span>
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                    <span class="text-gray-300">(default)</span>
                                </div>
                            </template>

                            {{-- Actual Login URL (after credentials loaded, uses login_url from API) --}}
                            <template x-if="wpCredentials[wp.path] && wpCredentials[wp.path].login_info && wpCredentials[wp.path].login_info.url">
                                <div class="flex flex-wrap items-center gap-3 mb-3 text-xs">
                                    <a :href="wpCredentials[wp.path].login_info.url" target="_blank" class="text-gray-500 hover:text-blue-600 hover:underline inline-flex items-center gap-1 break-words">
                                        <span x-text="wpCredentials[wp.path].login_info.url"></span>
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                    {{-- Modified login URL indicator --}}
                                    <template x-if="wpCredentials[wp.path].login_info.is_modified">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-orange-100 text-orange-700">Modified from default</span>
                                    </template>
                                </div>
                            </template>

                            {{-- Action Buttons --}}
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                {{-- Show Credentials (only visible before credentials are loaded) --}}
                                <button @click="wpFetchCreds(wp)" :disabled="wpCredLoading[wp.path] === true"
                                    x-show="!wpCredentials[wp.path]"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-50 rounded-lg hover:bg-gray-100 border border-gray-200 disabled:opacity-50">
                                    <svg x-show="!wpCredLoading[wp.path]" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="wpCredLoading[wp.path]" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="wpCredLoading[wp.path] ? 'Loading...' : 'Show Credentials'"></span>
                                </button>

                                {{-- Auto Login --}}
                                <button @click="wpAutoLogin(wp, wp.admin_user || 'admin')" :disabled="wpAutoLogging[wp.path] === true"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 rounded-lg hover:bg-green-100 border border-green-200 disabled:opacity-50">
                                    <svg x-show="!wpAutoLogging[wp.path]" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                    <svg x-show="wpAutoLogging[wp.path]" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="wpAutoLogging[wp.path] ? 'Logging in...' : 'Auto Login'"></span>
                                </button>
                            </div>

                            {{-- Credentials Table (shown after fetch, displays users with password, role, actions) --}}
                            <template x-if="wpCredentials[wp.path] && !wpCredentials[wp.path].error && ((wpCredentials[wp.path].admin_users && wpCredentials[wp.path].admin_users.length > 0) || (wpCredentials[wp.path].credentials && wpCredentials[wp.path].credentials.users && wpCredentials[wp.path].credentials.users.length > 0))">
                                <div class="mt-3 border border-gray-100 rounded-lg overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Username</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Email</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Role</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Password</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="(user, uIdx) in (wpCredentials[wp.path].admin_users || wpCredentials[wp.path].credentials?.users || [])" :key="user.username || user.user_login || uIdx">
                                                <tr class="border-t border-gray-100">
                                                    {{-- Username --}}
                                                    <td class="px-3 py-2 font-mono text-gray-900 break-words" x-text="user.username || user.user_login"></td>

                                                    {{-- Email --}}
                                                    <td class="px-3 py-2 text-gray-600 break-words" x-text="user.email || user.user_email || '-'"></td>

                                                    {{-- Role --}}
                                                    <td class="px-3 py-2 text-gray-600" x-text="user.role || user.roles || '-'"></td>

                                                    {{-- Password (masked with eye toggle) --}}
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="font-mono text-gray-700 break-words"
                                                                x-text="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)] ? (user.password || user.user_pass || '(hashed)') : String.fromCharCode(8226).repeat(8)"></span>
                                                            <button @click="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)] = !wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]"
                                                                class="text-gray-400 hover:text-gray-600 shrink-0" title="Toggle password visibility">
                                                                {{-- Eye open icon --}}
                                                                <svg x-show="!wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                                {{-- Eye closed icon --}}
                                                                <svg x-show="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                                            </button>
                                                        </div>
                                                    </td>

                                                    {{-- Actions: Set Password + Auto Login --}}
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-1.5">
                                                            {{-- Set Password --}}
                                                            <button @click="wpOpenReset(wp, user)"
                                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-orange-600 bg-orange-50 rounded hover:bg-orange-100 border border-orange-200">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                                                Set Password
                                                            </button>

                                                            {{-- Auto Login per user --}}
                                                            <button @click="wpAutoLogin(wp, user.username || user.user_login)" :disabled="wpAutoLogging[wp.path] === true"
                                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-green-600 bg-green-50 rounded hover:bg-green-100 border border-green-200 disabled:opacity-50">
                                                                <svg x-show="!wpAutoLogging[wp.path]" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                                                <svg x-show="wpAutoLogging[wp.path]" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                                Login
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            {{-- Credential fetch error --}}
                            <template x-if="wpCredentials[wp.path] && wpCredentials[wp.path].error">
                                <div class="mt-3 rounded-lg px-4 py-3 text-xs font-medium bg-red-50 border border-red-200 text-red-700">
                                    <span x-text="wpCredentials[wp.path].error"></span>
                                </div>
                            </template>

                        </div>
                    </template>
                </div>
            </template>

            {{-- Password Reset Modal --}}
            <template x-if="wpResetForm && wpResetForm.show">
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="wpResetForm.show = false">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            Set WordPress Password
                        </h3>

                        <div class="space-y-3 mb-4">
                            {{-- Username (read-only display) --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                                <p class="text-sm font-mono text-gray-900 break-words" x-text="wpResetForm.username"></p>
                            </div>

                            {{-- Email (read-only display) --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                                <p class="text-sm text-gray-900 break-words" x-text="wpResetForm.email || 'N/A'"></p>
                            </div>

                            {{-- New Password input with Generate button --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">New Password</label>
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="wpResetForm.password" autocomplete="off" data-1p-ignore
                                        class="flex-1 px-3 py-2 text-sm font-mono border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 break-words"
                                        placeholder="Enter or generate password">
                                    <button @click="wpGeneratePassword()"
                                        class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium text-purple-600 bg-purple-50 rounded-lg hover:bg-purple-100 border border-purple-200 shrink-0">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Generate
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Reset Result Banner --}}
                        <template x-if="wpResetResult">
                            <div class="rounded-lg px-4 py-3 text-sm font-medium mb-4"
                                :class="wpResetResult.success ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'">
                                <span x-text="wpResetResult.message"></span>
                            </div>
                        </template>

                        {{-- Modal Footer Buttons --}}
                        <div class="flex items-center justify-end gap-3">
                            <button @click="wpResetForm.show = false"
                                class="px-4 py-2 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
                                Close
                            </button>
                            <button @click="wpDoReset()" :disabled="wpResetForm.saving || !wpResetForm.password"
                                class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700 disabled:opacity-50">
                                <svg x-show="wpResetForm.saving" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="wpResetForm.saving ? 'Resetting...' : 'Reset Password'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </div>
</div>
@endif
