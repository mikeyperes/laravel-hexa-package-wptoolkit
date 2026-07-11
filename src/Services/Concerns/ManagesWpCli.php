<?php

namespace hexa_package_wptoolkit\Services\Concerns;

use hexa_package_wptoolkit\Services\Concerns\WpCli\ManagesWpCliMedia;
use hexa_package_wptoolkit\Services\Concerns\WpCli\ManagesWpCliPosts;
use hexa_package_wptoolkit\Services\Concerns\WpCli\ManagesWpCliTaxonomies;
use hexa_package_wptoolkit\Services\Concerns\WpCli\ManagesWpCliUsers;
use hexa_package_wptoolkit\Services\Concerns\WpCli\SupportsWpCliConnections;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;

/**
 * ManagesWpCli — WP-CLI operations: posts, media, categories, tags.
 */
trait ManagesWpCli
{
    abstract public function commandTimeoutSeconds(): int;

    use ManagesWpCliMedia;
    use ManagesWpCliPosts;
    use ManagesWpCliTaxonomies;
    use ManagesWpCliUsers;
    use SupportsWpCliConnections;

}
