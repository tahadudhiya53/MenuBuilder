<?php

/**
 * Config for the integration harness's throwaway Craft install. Deliberately
 * minimal: this install exists only to give the integration tests a real
 * Craft 5 content pipeline, and any setting not needed for that is one more
 * thing that could make a test pass or fail for a reason unrelated to
 * MenuBuilder.
 */

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->devMode(true)
    ->allowAdminChanges(true)
    // The harness builds its fields, sections and entries through Craft's own
    // services rather than from YAML — see tests/integration-bootstrap.php.
    ->disallowRobots(true)
    ->securityKey('menu-builder-integration-tests')
;
