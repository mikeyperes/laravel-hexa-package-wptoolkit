<?php

namespace hexa_package_wptoolkit\Services\Concerns;


/**
 * ManagesWpCli — WP-CLI operations: posts, media, categories, tags.
 */
trait ManagesWpCli
{
    use ManagesWpCliMedia;
    use EvaluatesWpCliCode;
    use ManagesWpCliContent;
    use ManagesWpCliTaxonomies;
    use RunsWpCliCommands;

    abstract public function commandTimeoutSeconds(): int;
}
