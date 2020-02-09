<?php

namespace Drutiny\SumoLogic\Audit;

use Drutiny\Annotation\Param;
use Drutiny\Annotation\Token;
use Drutiny\Sandbox\Sandbox;
use Drutiny\SumoLogic\Audit\ApiEnabledAudit;

/**
 * The class executes a sumologic query then graphs the results over time..
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
 * @Param(
 *  name = "chart-type",
 *  description = "The type of graph, either bar or line.",
 *  type = "string",
 *  default = {"bar"}
 * )
 * @Param(
 *  name = "y-axis-label",
 *  description = "Custom label for the y-axis.",
 *  type = "string",
 *  default = {"Count"}
 * )
 * @Param(
 *  name = "stacked",
 *  description = "Determines whether or not the graph data should be stacked.",
 *  type = "boolean",
 *  default = {"FALSE"}
 * )
 * @Token(
 *  name = "records",
 *  description = "The result array returned by the query.",
 *  type = "array"
 * )
 * @Token(
 *  name = "count",
 *  description = "The number of rows returned",
 *  type = "integer"
 * )
 */
class SumologicTimeSeriesGraph extends SumoLogicQuery {

  /**
   * {@inheritdoc}
   */
  public function audit(Sandbox $sandbox) {
    // Execute the query from parent class
    SumoLogicQuery::audit($sandbox);
    
    $records = $sandbox->getParameter('records', NULL);
    $globals = $sandbox->getParameter('globals', []);

    $table_rows = [];

    // Extract the headers from the first record that are not Globals.
    $table_headers = array_diff(array_keys(array_slice($records[0],1)), $globals);
    // Add the Date header to the beginning.
    array_unshift($table_headers, "Date");

    foreach ($records as $record) {
      // Remove the globals from the record set
      $record = array_diff_key($record, array_flip($globals));

      $table_rows[$record['_timeslice']] = [$record['_timeslice']];
      foreach ($table_headers as $idx => $header) {
        if ($header == 'Date') {
          continue;
        }
        $table_rows[$record['_timeslice']][$idx] = $record[$header];
      }
    }

    $sandbox->setParameter('table_headers', $table_headers);
    $sandbox->setParameter('table_rows', array_values($table_rows));

// graph

    $graph = [
      'type' => $sandbox->getParameter('chart-type', 'bar'),
      'labels' => 'tr td:first-child',
      'hide-table' => TRUE,
      'height' => 250,
      'width' => 400,
      'stacked' => $sandbox->getParameter('stacked',FALSE),
      'title' => $sandbox->getPolicy()->get('title'),
      'y-axis' => $sandbox->getParameter('y-axis-label','Count'),
      'series' => [],
      'series-labels' => [],
      'legend' => 'bottom',
    ];

    foreach ($table_headers as $idx => $name) {
      if ($name == 'Date') {
        continue;
      }
      $nth = $idx + 1;
      $graph['series'][] = 'tr td:nth-child(' . $nth . ')';
      $graph['series-labels'][] = 'tr th:nth-child(' . $nth . ')';
    }
    $graph['series'] = implode(',', $graph['series']);
    $graph['series-labels'] = implode(',', $graph['series-labels']);

    $element = [];
    foreach ($graph as $key => $value) {
      $element[] = $key . '="' . $value . '"';
    }
    $element = '[[[' . implode(' ', $element) . ']]]';
    $sandbox->setParameter('graph', $element);

    return count($records) === 0 ? self::NOT_APPLICABLE : self::NOTICE;
  }

}