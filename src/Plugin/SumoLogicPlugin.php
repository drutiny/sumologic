<?php

namespace Drutiny\SumoLogic\Plugin;

use Drutiny\Plugin;

class SumoLogicPlugin extends Plugin {

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'sumologic:api';
    }

    public function configure()
    {
        $this->addField(
            'access_id',
            "Your access ID to connect to the Sumologic API with",
            static::FIELD_TYPE_CREDENTIAL
            )
          ->addField(
            'access_key',
            'Your access key to connect to the Sumologic API with',
            static::FIELD_TYPE_CREDENTIAL
          )
          ->addField(
            'endpoint',
            'The API endpoint to use. Defaults https://api.sumologic.com/api/v1/ if empty',
            static::FIELD_TYPE_CONFIG,
            'https://api.sumologic.com/api/v1/'
          );
    }
}

 ?>
