<script id="wptoolkit-dashboard-config" type="application/json">
{!! Illuminate\Support\Js::encode([
    "csrf" => csrf_token(),
    "settings" => $settings,
    "servers" => $serverPayload,
    "publishSites" => $publishSitePayload,
    "routes" => [
        "saveSettings" => route("wptoolkit.settings.save"),
        "serverDiagnostics" => route("wptoolkit.diagnostics.server"),
        "siteTest" => route("wptoolkit.diagnostics.site-test"),
        "getAllInstalls" => route("wptoolkit.get-all-installs"),
        "raw" => route("wptoolkit.raw"),
    ],
]) !!}
</script>
