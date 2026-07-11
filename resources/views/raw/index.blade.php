@extends('layouts.app')
@section('title', 'WP Toolkit — Raw')
@section('header', 'WP Toolkit — Raw Dev View')

@section('content')
<div class="space-y-6">

    {{-- Package Functions Index --}}
    <div class="bg-gray-900 rounded-xl p-6 text-sm font-mono">
        <h2 class="text-white font-semibold mb-3">WP Toolkit Package Functions</h2>
        <table class="w-full text-left">
            <thead>
                <tr class="text-gray-400 border-b border-gray-700">
                    <th class="py-1.5 px-2">Function</th>
                    <th class="py-1.5 px-2">Method</th>
                    <th class="py-1.5 px-2">Route</th>
                    <th class="py-1.5 px-2">Status</th>
                </tr>
            </thead>
            <tbody class="text-gray-300">
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Scan all WP installs on server</td>
                    <td class="py-1.5 px-2 text-blue-400">getAllInstalls()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/get-all-installs</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Get WP installs for cPanel user</td>
                    <td class="py-1.5 px-2 text-blue-400">getInstallsForAccount()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/get-installs</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Get users + credentials + login URL + DB creds</td>
                    <td class="py-1.5 px-2 text-blue-400">getCredentials()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/get-credentials</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">One-click WP admin login (mu-plugin)</td>
                    <td class="py-1.5 px-2 text-blue-400">generateWordPressLoginUrl()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/wp-login</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Reset WP user password</td>
                    <td class="py-1.5 px-2 text-blue-400">resetWordPressPassword()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/reset-password</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">One-click cPanel login</td>
                    <td class="py-1.5 px-2 text-blue-400">generateCpanelLoginUrl()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/cpanel-login</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">One-click WHM reseller login</td>
                    <td class="py-1.5 px-2 text-blue-400">generateWhmResellerLoginUrl()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wp-toolkit/whm-reseller-login</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Detect modified wp-admin URL</td>
                    <td class="py-1.5 px-2 text-blue-400">getStoredCredentials() &rarr; login_info</td>
                    <td class="py-1.5 px-2 text-gray-500">via get-credentials</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr>
                    <td class="py-1.5 px-2">Stored WP credentials (raw user/pass)</td>
                    <td class="py-1.5 px-2 text-blue-400">getStoredCredentials() &rarr; credentials</td>
                    <td class="py-1.5 px-2 text-gray-500">via get-credentials</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
            </tbody>
        </table>
    </div>

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
                    <div id="action-login-modified" class="hidden mt-1">
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-orange-100 text-orange-700">Modified from default</span>
                        <div id="action-login-default" class="text-xs text-gray-400 line-through mt-0.5"></div>
                    </div>
                </div>

                {{-- Stored WP Credentials --}}
                <div id="stored-creds-row" class="hidden">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Stored WP Credentials (from WP Toolkit)</label>
                    <div id="stored-creds" class="bg-gray-900 rounded-lg p-3 space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">User:</span>
                            <span id="stored-username" class="text-green-400 font-mono text-sm"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">Pass:</span>
                            <span id="stored-password" class="text-green-400 font-mono text-sm"></span>
                            <button id="btn-copy-stored-pass" class="text-gray-400 hover:text-white text-xs px-2 py-0.5 border border-gray-600 rounded">Copy</button>
                            <span id="stored-copy-confirm" class="hidden text-green-400 text-xs">Copied</span>
                        </div>
                    </div>
                </div>

                {{-- DB Credentials --}}
                <div id="db-creds-row" class="hidden">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Database Credentials</label>
                    <div id="db-creds" class="bg-gray-900 rounded-lg p-3 space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">Host:</span>
                            <span id="db-host" class="text-blue-400 font-mono text-sm"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">DB:</span>
                            <span id="db-name" class="text-blue-400 font-mono text-sm"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">User:</span>
                            <span id="db-user" class="text-blue-400 font-mono text-sm"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400 text-xs w-16">Pass:</span>
                            <span id="db-password" class="text-blue-400 font-mono text-sm"></span>
                            <button id="btn-copy-db-pass" class="text-gray-400 hover:text-white text-xs px-2 py-0.5 border border-gray-600 rounded">Copy</button>
                            <span id="db-copy-confirm" class="hidden text-green-400 text-xs">Copied</span>
                        </div>
                    </div>
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

@include("wptoolkit::raw.partials.config")
<x-hexa-package-script package="wptoolkit" :version="config('wptoolkit.version')" asset="raw.js" />
@endsection
