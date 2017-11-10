<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Audit;

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
}

 ?>
