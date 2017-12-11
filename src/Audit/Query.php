<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Sandbox\Sandbox;

class Query extends ApiEnabledAudit {
  public function audit(Sandbox $sandbox)
  {
    list($sitegroup, $env) = explode('.', str_replace('@', '', $sandbox->drush()->getAlias()), 2);
    $tokens['@sitegroup'] = $sitegroup;
    $tokens['@environment'] = $env;

    $query = $sandbox->getParameter('query');

    $query = strtr($query, $tokens);

    $records = $this->search($sandbox, $query);

    return count($records) === 0 ? self::NOT_APPLICABLE : self::NOTICE;
  }
}

 ?>
