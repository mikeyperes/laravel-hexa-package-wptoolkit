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
                    html += '<th class="py-2 px-2">ID</th><th class="py-2 px-2">URL</th><th class="py-2 px-2">Path</th><th class="py-2 px-2">Version</th><th class="py-2 px-2">PHP</th><th class="py-2 px-2">Status</th>';
                    html += '</tr></thead><tbody>';
                    installs.forEach(function(inst) {
                        html += '<tr class="border-b border-gray-100">';
                        html += '<td class="py-2 px-2">' + (inst.id || '-') + '</td>';
                        html += '<td class="py-2 px-2 break-words">' + (inst.url || '-') + '</td>';
                        html += '<td class="py-2 px-2 break-words">' + (inst.path || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (inst.version || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (inst.php_version || '-') + '</td>';
                        html += '<td class="py-2 px-2">' + (inst.status || '-') + '</td>';
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
