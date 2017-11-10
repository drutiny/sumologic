<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Client;

class Query extends ApiEnabledAudit {
  public function audit(Sandbox $sandbox)
  {
    list($sitegroup, $env) = explode('.', str_replace('@', '', $sandbox->drush()->getAlias()), 2);
    $tokens['@sitegroup'] = $sitegroup;
    $tokens['@environment'] = $sitegroup;

    $query = $sandbox->getParameter('query');

    $query = strtr($query, $tokens);

    $sandbox
      ->logger()
      ->info(get_class($this) . ': ' . $query);

    $client = new Client($this->getAccessId(), $this->getAccessKey());
    $client->query($query)
      ->onSuccess(function ($records) use ($sandbox) {
        foreach ($records as &$record) {
          if (isset($record['_timeslice'])) {
            $record['_timeslice'] = date('Y-m-d H:i:s', $record['_timeslice']/1000);
          }
        }
        $sandbox->setParameter('records', $records);
      })
      ->wait($sandbox->logger());
    return TRUE;
  }
}

 ?>
