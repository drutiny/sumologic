<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Sandbox\Sandbox;

class NoLogsFound extends ApiEnabledAudit {

  /**
   * Find no records.
   */
  public function audit(Sandbox $sandbox)
  {
    list($sitegroup, $env) = explode('.', str_replace('@', '', $sandbox->drush()->getAlias()), 2);
    $tokens['@sitegroup'] = $sitegroup;
    $tokens['@environment'] = $env;

    $query = $sandbox->getParameter('query');

    $query = strtr($query, $tokens);

    $records = $this->search($sandbox, $query);

    if ($globals = $sandbox->getParameter('globals', []) && $row = reset($records)) {
      foreach ($globals as $key) {
        $sandbox->setParameter($key, $row[$key]);
      }
    }

    return count($records) === 0;
  }
}

 ?>
