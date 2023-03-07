<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Sandbox\Sandbox;

class NoLogsFound extends ApiEnabledAudit
{

    /**
     * Find no records.
     */
    public function audit(Sandbox $sandbox)
    {
        list($sitegroup, $env) = explode('.', str_replace('@', '', $sandbox->drush()->getAlias()), 2);
        $tokens['@sitegroup'] = $sitegroup;
        $tokens['@environment'] = $env;

        $query = $this->getParameter('query');

        $query = strtr($query, $tokens);

        $records = $this->search($sandbox, $query);

        if (($globals = $this->getParameter('globals', [])) && $row = reset($records)) {
            foreach ($globals as $key) {
                $this->set($key, $row[$key]);
            }
        }

        $this->set('count', count($records));

        return count($records) === 0;
    }
}

?>
