<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\SumoLogic\Client;
use Drutiny\Audit;
use Drutiny\Sandbox\Sandbox;

abstract class ApiEnabledAudit extends Audit {
  static public function credentialFilepath()
  {
    return sprintf('%s/.drutiny/sumologic.json', $_SERVER['HOME']);
  }

  protected function getAccessId()
  {
    $data = file_get_contents(self::credentialFilepath());
    $data = json_decode($data, TRUE);
    return $data['access_id'];
  }

  protected function getAccessKey()
  {
    $data = file_get_contents(self::credentialFilepath());
    $data = json_decode($data, TRUE);
    return $data['access_key'];
  }

  public function requireApiCredentials()
  {
    $creds = self::credentialFilepath();
    if (!file_exists($creds)) {
      throw new InvalidArgumentException("Sumologic credentials need to be setup. Please run setup:sumologic.");
    }
    return TRUE;
  }

  protected function search(Sandbox $sandbox, $query)
  {
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
    return $sandbox->getParameter('records', []);
  }
}

 ?>
