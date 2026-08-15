<?php

namespace Tahadudhiya\MenuBuilder\events;

use yii\base\Event;

/**
 * @property array<string,\Tahadudhiya\MenuBuilder\visibility\VisibilityRuleInterface> $rules Keyed by rule type.
 */
class RegisterVisibilityRulesEvent extends Event
{
    /** @var array<string,\Tahadudhiya\MenuBuilder\visibility\VisibilityRuleInterface> */
    public array $rules = [];
}
