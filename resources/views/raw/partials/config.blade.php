<script id="wptoolkit-raw-config" type="application/json">
{!! Illuminate\Support\Js::encode([
    "csrfToken" => csrf_token(),
    "routes" => [
        "getAllInstalls" => route("wptoolkit.get-all-installs"),
        "getCredentials" => route("wptoolkit.get-credentials"),
        "wpLogin" => route("wptoolkit.wp-login"),
        "resetPassword" => route("wptoolkit.reset-password"),
        "cpanelLogin" => route("wptoolkit.cpanel-login"),
    ],
]) !!}
</script>
