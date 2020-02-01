<?php

namespace Drutiny\Acquia\CS\Audit;

use Drutiny\Annotation\Param;
use Drutiny\Annotation\Token;
use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Audit\ApiEnabledAudit;

/**
 * Audit Sumologc and expect an empty result.
 *
 * @Param(
 *  name = "query",
 *  description = "The Sumologic Query to run. @sitegroup and @environment are
 *    available variables.",
 *  type = "string"
 * )
 * @Param(
 *  name = "globals",
 *  description = "A list of fields returned from the query to be available
 *    globally (outside of a row).",
 *  type = "array"
 * )
 * @Token(
 *  name = "count",
 *  description = "The number of rows returned by the query.",
 *  type = "integer"
 * )
 * @Token(
 *  name = "records",
 *  description = "The result array returned by the query.",
 *  type = "array"
 * )
 */
class SumoLogicEmptyQuery extends ApiEnabledAudit {

  /**
   * Find no records.
   */
  public function audit(Sandbox $sandbox) {
    list($sitegroup, $env) = explode('.', str_replace('@', '', $sandbox->drush()->getAlias()), 2);
    $tokens['@sitegroup'] = $sitegroup . $env;

    // Acquia-ism that doesn't use the prod term in production sitegroup names.
    if ($env == 'prod') {
      $tokens['@sitegroup'] = $sitegroup;
    }

    $tokens['@environment'] = $env;

    $query = $sandbox->getParameter('query');

    $query = strtr($query, $tokens);

    $records = $this->search($sandbox, $query);

    if (($globals = $sandbox->getParameter('globals', [])) && $row = reset($records)) {
      foreach ($globals as $key) {
        $sandbox->setParameter($key, $row[$key]);
      }
    }

    $sandbox->setParameter('count', count($records));

    return count($records) === 0;
  }

}
