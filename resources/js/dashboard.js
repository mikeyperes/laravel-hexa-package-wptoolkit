function wpToolkitSettingsPage() {
    const configNode = document.getElementById("wptoolkit-dashboard-config");
    const config = configNode ? JSON.parse(configNode.textContent) : {};
    return {
        routes: config.routes,
        servers: config.servers || [],
        publishSites: config.publishSites || [],
        selectedServerId: '',
        selectedSiteId: '',
        savingSettings: false,
        settingsMessage: '',
        settingsMessageOk: true,
        serverDiagnosticsLoading: false,
        installDiscoveryLoading: false,
        siteTestLoading: false,
        serverDiagnostics: null,
        installDiscoveryResult: null,
        installDiscoverySummary: '',
        siteTestResult: null,
        form: {
            mode: config.settings.mode || 'auto',
            local_hosts: (config.settings.local_hosts || []).join(', '),
            local_binary_path: config.settings.local_binary_path || '',
            remote_binary_path: config.settings.remote_binary_path || '',
            probe_timeout: config.settings.probe_timeout || 8,
        },

        async postJson(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Request failed with HTTP ' + response.status);
            }

            return data;
        },

        async saveSettings() {
            this.savingSettings = true;
            this.settingsMessage = '';
            try {
                const payload = {
                    mode: this.form.mode,
                    local_hosts: this.form.local_hosts,
                    local_binary_path: this.form.local_binary_path,
                    remote_binary_path: this.form.remote_binary_path,
                    probe_timeout: Number(this.form.probe_timeout || 8),
                };
                const data = await this.postJson(this.routes.saveSettings, payload);
                this.settingsMessage = data.message || 'Saved.';
                this.settingsMessageOk = true;
            } catch (error) {
                this.settingsMessage = error.message || 'Failed to save settings.';
                this.settingsMessageOk = false;
            } finally {
                this.savingSettings = false;
            }
        },

        async runServerDiagnostics() {
            this.serverDiagnosticsLoading = true;
            this.serverDiagnostics = null;
            try {
                this.serverDiagnostics = await this.postJson(this.routes.serverDiagnostics, {
                    server_id: Number(this.selectedServerId),
                });
            } catch (error) {
                this.serverDiagnostics = {
                    resolution: { transport: 'unknown', label: 'Diagnostics Failed', reason: error.message || 'Diagnostics failed.' },
                    server: { same_host: false },
                    local_probe: { runtime_user: 'unknown', candidates: [] },
                    remote_probe: { runtime_user: 'unknown', candidates: [], error: error.message || 'Diagnostics failed.' },
                };
            } finally {
                this.serverDiagnosticsLoading = false;
            }
        },

        async runInstallDiscovery() {
            this.installDiscoveryLoading = true;
            this.installDiscoveryResult = null;
            this.installDiscoverySummary = '';
            try {
                const data = await this.postJson(this.routes.getAllInstalls, {
                    server_id: Number(this.selectedServerId),
                });
                this.installDiscoveryResult = data;
                this.installDiscoverySummary = data.success
                    ? ((data.installs || []).length + ' installs returned')
                    : (data.error || 'Install discovery failed');
            } catch (error) {
                this.installDiscoveryResult = { success: false, message: error.message || 'Install discovery failed.' };
                this.installDiscoverySummary = error.message || 'Install discovery failed.';
            } finally {
                this.installDiscoveryLoading = false;
            }
        },

        async runSiteTest(test) {
            this.siteTestLoading = true;
            this.siteTestResult = null;
            try {
                this.siteTestResult = await this.postJson(this.routes.siteTest, {
                    site_id: Number(this.selectedSiteId),
                    test,
                });
            } catch (error) {
                this.siteTestResult = {
                    success: false,
                    test,
                    site: { name: 'Unknown site' },
                    server: { name: 'Unknown server', hostname: 'unknown' },
                    runtime: null,
                    result: { message: error.message || 'Site test failed.' },
                };
            } finally {
                this.siteTestLoading = false;
            }
        },

        formatCandidateState(value) {
            if (value === true) return 'Yes';
            if (value === false) return 'No';
            return 'N/A';
        },

        jsonPretty(value) {
            return JSON.stringify(value, null, 2);
        },
    };
}
