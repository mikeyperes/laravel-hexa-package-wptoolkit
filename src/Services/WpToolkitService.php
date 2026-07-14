<?php

namespace hexa_package_wptoolkit\Services;

use hexa_core\Models\Setting;
use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use hexa_core\Services\GenericService;
use hexa_package_wptoolkit\Services\Concerns\ManagesInstalls;
use hexa_package_wptoolkit\Services\Concerns\ManagesCredentials;
use hexa_package_wptoolkit\Services\Concerns\ManagesLogins;
use hexa_package_wptoolkit\Services\Concerns\ManagesWpCli;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * WpToolkitService — all WP Toolkit operations go through this service.
 *
 * Connects to WHM servers via SSH and interacts with the wp-toolkit CLI
 * to discover WordPress installs, manage credentials, and generate login URLs.
 *
 * Methods are organized into domain traits:
 * - ManagesInstalls: getAllInstalls, getInstallsForAccount, parsing
 * - ManagesCredentials: getCredentials, resetWordPressPassword, stored credentials
 * - ManagesLogins: generateWordPressLoginUrl, generateCpanelLoginUrl, etc.
 * - ManagesWpCli: wpCliCreatePost, wpCliUploadMedia, categories, tags, etc.
 */
class WpToolkitService
{
    use \hexa_package_wptoolkit\Services\Concerns\WpToolkit\ManagesWpToolkitConnections;
    use \hexa_package_wptoolkit\Services\Concerns\WpToolkit\ResolvesWpToolkitRuntime;
    use \hexa_package_wptoolkit\Services\Concerns\WpToolkit\ProbesWpToolkitRuntime;
    use \hexa_package_wptoolkit\Services\Concerns\WpToolkit\OperatesWpToolkitInstalls;
    use \hexa_package_wptoolkit\Services\Concerns\WpToolkit\RunsWpToolkitCommands;

    use ManagesInstalls;
    use ManagesCredentials;
    use ManagesLogins;
    use ManagesWpCli;

    protected GenericService $generic;
    protected WhmService $whm;
    protected array $sshCache = [];
    protected array $installInfoCache = [];
    protected ?array $localProbe = null;
    protected ?array $localWpCliProbe = null;
    protected array $remoteProbeCache = [];
    protected ?array $localHostAliasesCache = null;
    protected array $hostAliasCache = [];
    protected array $wpAuthorIdCache = [];

    /**
     * @param GenericService $generic
     * @param WhmService     $whm
     */
    public function __construct(GenericService $generic, WhmService $whm)
    {
        $this->generic = $generic;
        $this->whm = $whm;
    }












































































}
