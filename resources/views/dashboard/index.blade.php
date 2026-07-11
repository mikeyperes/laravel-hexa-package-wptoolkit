@extends('layouts.app')

@section('title', 'WP Toolkit Settings')
@section('header', 'WP Toolkit Settings')

@section('content')
<div class="max-w-6xl mx-auto space-y-6" x-data="wpToolkitSettingsPage()">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Command Runtime</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Configure how WP Toolkit commands resolve and run. Auto mode now chooses local execution only when the target host matches this app host and the current runtime user can actually execute the binary.
                </p>
            </div>
            <a :href="routes.raw" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Open Raw Sandbox</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Execution Mode</span>
                <select x-model="form.mode" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    <option value="auto">Auto</option>
                    <option value="ssh">Remote Only</option>
                    <option value="local">Local Only</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700">Probe Timeout</span>
                <input x-model="form.probe_timeout" type="number" min="2" max="60" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-gray-700">Local Hosts</span>
                <textarea x-model="form.local_hosts" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm font-mono" placeholder="51.81.93.236,publish.scalemypublication.com"></textarea>
                <p class="text-xs text-gray-500 mt-1">Comma-separated hostnames or IPs that should count as “same host” for auto mode.</p>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700">Local Binary Path</span>
                <input x-model="form.local_binary_path" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm font-mono" placeholder="/usr/local/bin/wp-toolkit">
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700">Remote Binary Path</span>
                <input x-model="form.remote_binary_path" type="text" class="mt-1 w-full rounded-lg border-gray-300 text-sm font-mono" placeholder="/usr/local/bin/wp-toolkit">
            </label>
        </div>

        <div class="mt-5 flex items-center gap-3">
            <button
                @click="saveSettings"
                :disabled="savingSettings"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
            >
                <svg x-show="savingSettings" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="savingSettings ? 'Saving…' : 'Save WP Toolkit Settings'"></span>
            </button>
            <span x-show="settingsMessage" class="text-sm" :class="settingsMessageOk ? 'text-green-600' : 'text-red-600'" x-text="settingsMessage"></span>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Server Diagnostics</h2>
                <p class="text-sm text-gray-500 mt-1">See how the app resolved transport, which binary it found, and why it chose local or remote.</p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="block flex-1 min-w-[220px]">
                    <span class="text-sm font-medium text-gray-700">WHM Server</span>
                    <select x-model="selectedServerId" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Select server…</option>
                        <template x-for="server in servers" :key="server.id">
                            <option :value="String(server.id)" x-text="server.name + ' (' + server.hostname + ')'"></option>
                        </template>
                    </select>
                </label>

                <button
                    @click="runServerDiagnostics"
                    :disabled="serverDiagnosticsLoading || !selectedServerId"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    <svg x-show="serverDiagnosticsLoading" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="serverDiagnosticsLoading ? 'Inspecting…' : 'Inspect Runtime'"></span>
                </button>

                <button
                    @click="runInstallDiscovery"
                    :disabled="installDiscoveryLoading || !selectedServerId"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-60"
                >
                    <svg x-show="installDiscoveryLoading" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="installDiscoveryLoading ? 'Scanning…' : 'List Installs'"></span>
                </button>
            </div>

            <template x-if="serverDiagnostics">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                              :class="serverDiagnostics.resolution.transport === 'local' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'"
                              x-text="serverDiagnostics.resolution.label"></span>
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                              :class="serverDiagnostics.server.same_host ? 'bg-amber-100 text-amber-700' : 'bg-gray-200 text-gray-700'"
                              x-text="serverDiagnostics.server.same_host ? 'Same Host Match' : 'Remote Host'"></span>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="font-medium text-gray-500">Selected Binary</dt>
                            <dd class="mt-1 font-mono text-gray-900 break-all" x-text="serverDiagnostics.resolution.selected_binary || 'Not resolved'"></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Reason</dt>
                            <dd class="mt-1 text-gray-900" x-text="serverDiagnostics.resolution.reason"></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Local Runtime User</dt>
                            <dd class="mt-1 font-mono text-gray-900" x-text="serverDiagnostics.local_probe.runtime_user || 'unknown'"></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Remote Runtime User</dt>
                            <dd class="mt-1 font-mono text-gray-900" x-text="serverDiagnostics.remote_probe.runtime_user || 'unavailable'"></dd>
                        </div>
                    </dl>

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-800 mb-2">Local Candidate Checks</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="text-left text-gray-500 border-b border-gray-200">
                                        <tr>
                                            <th class="py-2 pr-3">Path</th>
                                            <th class="py-2 pr-3">Exists</th>
                                            <th class="py-2 pr-3">Executable</th>
                                            <th class="py-2 pr-3">Version</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="candidate in serverDiagnostics.local_probe.candidates" :key="'local-' + candidate.path">
                                            <tr class="border-b border-gray-100">
                                                <td class="py-2 pr-3 font-mono text-xs break-all" x-text="candidate.path"></td>
                                                <td class="py-2 pr-3 text-xs" x-text="formatCandidateState(candidate.exists)"></td>
                                                <td class="py-2 pr-3 text-xs" x-text="formatCandidateState(candidate.executable)"></td>
                                                <td class="py-2 pr-3 text-xs text-gray-600" x-text="candidate.version || 'Unavailable'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-800 mb-2">Remote Candidate Checks</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="text-left text-gray-500 border-b border-gray-200">
                                        <tr>
                                            <th class="py-2 pr-3">Path</th>
                                            <th class="py-2 pr-3">Exists</th>
                                            <th class="py-2 pr-3">Executable</th>
                                            <th class="py-2 pr-3">Version</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="candidate in serverDiagnostics.remote_probe.candidates" :key="'remote-' + candidate.path">
                                            <tr class="border-b border-gray-100">
                                                <td class="py-2 pr-3 font-mono text-xs break-all" x-text="candidate.path"></td>
                                                <td class="py-2 pr-3 text-xs" x-text="formatCandidateState(candidate.exists)"></td>
                                                <td class="py-2 pr-3 text-xs" x-text="formatCandidateState(candidate.executable)"></td>
                                                <td class="py-2 pr-3 text-xs text-gray-600" x-text="candidate.version || 'Unavailable'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <p x-show="serverDiagnostics.remote_probe.error" class="mt-2 text-sm text-red-600" x-text="serverDiagnostics.remote_probe.error"></p>
                        </div>
                    </div>

                    <div x-show="installDiscoveryResult" class="rounded-lg border border-gray-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-800">Install Discovery</h3>
                            <span class="text-xs text-gray-500" x-text="installDiscoverySummary"></span>
                        </div>
                        <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100" x-text="jsonPretty(installDiscoveryResult)"></pre>
                    </div>
                </div>
            </template>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Publish Site Command Tests</h2>
                <p class="text-sm text-gray-500 mt-1">Run real write, author, and category checks against configured WP Toolkit sites without opening the publish pipeline.</p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="block flex-1 min-w-[220px]">
                    <span class="text-sm font-medium text-gray-700">Publish Site</span>
                    <select x-model="selectedSiteId" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Select site…</option>
                        <template x-for="site in publishSites" :key="site.id">
                            <option :value="String(site.id)" x-text="site.name + ' (' + site.url + ')'"></option>
                        </template>
                    </select>
                </label>

                <div class="flex flex-wrap gap-2">
                    <button @click="runSiteTest('write')" :disabled="siteTestLoading || !selectedSiteId" class="rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-60">Write Test</button>
                    <button @click="runSiteTest('authors')" :disabled="siteTestLoading || !selectedSiteId" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">Load Authors</button>
                    <button @click="runSiteTest('categories')" :disabled="siteTestLoading || !selectedSiteId" class="rounded-lg bg-purple-600 px-3 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-60">Load Categories</button>
                </div>
            </div>

            <p x-show="siteTestLoading" class="text-sm text-gray-500">Running site test…</p>

            <template x-if="siteTestResult">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900" x-text="siteTestResult.site.name + ' — ' + siteTestResult.test"></h3>
                            <p class="text-xs text-gray-500 mt-1" x-text="siteTestResult.server.name + ' (' + siteTestResult.server.hostname + ')'"></p>
                        </div>
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                              :class="siteTestResult.success ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                              x-text="siteTestResult.success ? 'Success' : 'Failed'"></span>
                    </div>

                    <p class="text-sm text-gray-800" x-text="siteTestResult.result.message || 'No message returned.'"></p>

                    <template x-if="siteTestResult.result.authors">
                        <pre class="max-h-48 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100" x-text="jsonPretty(siteTestResult.result.authors)"></pre>
                    </template>

                    <template x-if="siteTestResult.result.categories">
                        <pre class="max-h-48 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100" x-text="jsonPretty(siteTestResult.result.categories)"></pre>
                    </template>

                    <details class="rounded-lg border border-gray-200 bg-white p-3">
                        <summary class="cursor-pointer text-sm font-medium text-gray-800">Resolved Runtime Snapshot</summary>
                        <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100" x-text="jsonPretty(siteTestResult.runtime)"></pre>
                    </details>
                </div>
            </template>

            <template x-if="publishSites.length === 0">
                <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                    No WP Toolkit publish sites were found in this app.
                </div>
            </template>
        </div>
    </div>
</div>

@include("wptoolkit::dashboard.partials.config")
<x-hexa-package-script package="wptoolkit" :version="config('wptoolkit.version')" asset="dashboard.js" />
@endsection
