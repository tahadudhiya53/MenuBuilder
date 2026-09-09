<?php

/**
 * Config for the integration harness's throwaway Craft install.
*/

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->devMode(true)
    ->allowAdminChanges(true)
    // The harness builds its fields, sections and entries through Craft's own services rather than
    // from YAML — see tests/integration-bootstrap.php.
    ->disallowRobots(true)
    ->securityKey('menu-builder-integration-tests')
;
