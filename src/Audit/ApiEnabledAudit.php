<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Audit;

/**
 *
 */
abstract class ApiEnabledAudit extends Audit
{
    use SearchTrait;
}
