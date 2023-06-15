# Drutiny Sumologic Plugin

Allows Drutiny to query the Sumologic API and audit the result sets returned.
Requires API credentials which can be obtained from Sumologic.

## Setup

```
composer require drutiny/sumologic
./vendor/bin/drutiny plugin:setup sumologic
```

# Usage

See [Audit Documentation](https://drutiny.github.io/2.1.x/audits/DrutinySumoLogicAudit/).

# Send logs to HTTP source in collector.
Set the `sumologic.stats.collector` parameter in drutiny.yml to the URL of your HTTP source for your collector
and this plugin will start sending Drutiny logs for policy audit responses and reports to the collector.

```yaml
parameters:
    sumologic.stats.collector: https://[SumoEndpoint]/receiver/v1/http/[UniqueHTTPCollectorCode]
```
