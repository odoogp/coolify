<?php

namespace App\Support;

use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Grafana for one Odoo branch. Launch adds it to that stack.
 * Prometheus keeps only that stack's Odoo and PostgreSQL containers.
 */
class OdooMonitor
{
    public const SERVICE_NAME = 'monitor';

    public static function inject(string $compose, string $project): string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $project) !== 1) {
            return $compose;
        }

        try {
            $yaml = Yaml::parse($compose);
        } catch (\Throwable) {
            return $compose;
        }
        if (! is_array($yaml) || ! OdooJupyter::isOdooCompose($compose)) {
            return $compose;
        }

        $services = $yaml['services'] ?? null;
        if (! is_array($services) || isset($services[self::SERVICE_NAME])) {
            return $compose;
        }

        $services['cadvisor'] = [
            'image' => 'gcr.io/cadvisor/cadvisor:v0.49.1',
            'restart' => 'unless-stopped',
            'command' => ['--docker_only=true', '--housekeeping_interval=30s'],
            'volumes' => ['/var/run/docker.sock:/var/run/docker.sock:ro'],
        ];
        $services['prometheus'] = [
            'image' => 'prom/prometheus:v2.55.1',
            'restart' => 'unless-stopped',
            'user' => '0:0',
            'entrypoint' => ['sh', '-ec'],
            'command' => [self::prometheusCommand($project)],
            'depends_on' => ['cadvisor'],
        ];
        $services[self::SERVICE_NAME] = [
            'image' => 'grafana/grafana-oss',
            'restart' => 'unless-stopped',
            'entrypoint' => ['sh', '-ec'],
            'command' => [self::grafanaCommand()],
            'environment' => [
                'SERVICE_URL_MONITOR_3000',
                'GF_SERVER_ROOT_URL=${SERVICE_FQDN_MONITOR}',
                'GF_SECURITY_ADMIN_USER=admin',
                'GF_SECURITY_ADMIN_PASSWORD=${SERVICE_PASSWORD_MONITOR}',
                'GF_AUTH_ANONYMOUS_ENABLED=true',
                'GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer',
                'GF_USERS_ALLOW_SIGN_UP=false',
                'GF_PATHS_PROVISIONING=/tmp/grafana-provisioning',
            ],
            'depends_on' => ['prometheus'],
        ];
        $yaml['services'] = $services;

        return Yaml::dump($yaml, 8, 2);
    }

    public static function urlFor(Service $service): ?string
    {
        if (! $service->supportsOdooJupyter()) {
            return null;
        }

        $monitor = $service->applications()->get()->firstWhere('name', self::SERVICE_NAME);
        if (! filled($monitor?->fqdn)) {
            return null;
        }

        $odoo = self::containerName($service, 'odoo');
        $postgres = self::containerName($service, 'postgres');
        if ($odoo === null || $postgres === null) {
            return null;
        }

        return self::dashboardUrl(getFqdnWithoutPort(firstDomainFromList((string) $monitor->fqdn)), $odoo, $postgres);
    }

    public static function dashboardUrl(string $base, string $odoo, string $postgres): ?string
    {
        $base = rtrim($base, '/');
        if (preg_match('#^https?://[A-Za-z0-9.-]+$#', $base) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $odoo) !== 1 || preg_match('/^[A-Za-z0-9_.-]+$/', $postgres) !== 1) {
            return null;
        }

        return $base.'/d/gpsh-odoo/odoo?orgId=1&kiosk&var-container='.rawurlencode($odoo).'&var-container='.rawurlencode($postgres);
    }

    public static function hidesTerminal(string $name): bool
    {
        return in_array(strtolower($name), ['cadvisor', 'prometheus', self::SERVICE_NAME], true);
    }

    private static function prometheusCommand(string $project): string
    {
        return <<<BASH
cat > /tmp/prometheus.yml << 'EOF'
global:
  scrape_interval: 30s
scrape_configs:
  - job_name: cadvisor
    static_configs:
      - targets: ['cadvisor:8080']
    metric_relabel_configs:
      - source_labels: [container_label_com_docker_compose_project]
        regex: {$project}
        action: keep
      - source_labels: [container_label_com_docker_compose_service]
        regex: odoo|postgresql|postgres
        action: keep
EOF
exec prometheus --config.file=/tmp/prometheus.yml --storage.tsdb.path=/prometheus
BASH;
    }

    private static function grafanaCommand(): string
    {
        $dashboard = str_replace('$', '$$', json_encode([
            'uid' => 'gpsh-odoo',
            'title' => 'Odoo',
            'editable' => false,
            'schemaVersion' => 39,
            'timezone' => 'browser',
            'time' => ['from' => 'now-1h', 'to' => 'now'],
            'templating' => [
                'list' => [[
                    'name' => 'container',
                    'type' => 'custom',
                    'multi' => true,
                    'includeAll' => false,
                    'query' => 'none',
                    'current' => ['selected' => true, 'text' => 'none', 'value' => 'none'],
                ]],
            ],
            'panels' => [
                self::panel(1, 'CPU', 0, 'sum(rate(container_cpu_usage_seconds_total{name=~".*${container:regex}.*",id!="/"}[5m])) by (name)'),
                self::panel(2, 'Memory', 12, 'sum(container_memory_working_set_bytes{name=~".*${container:regex}.*",id!="/"}) by (name)'),
                self::panel(3, 'Network in', 0, 'sum(rate(container_network_receive_bytes_total{name=~".*${container:regex}.*",id!="/"}[5m])) by (name)', 8),
                self::panel(4, 'Network out', 12, 'sum(rate(container_network_transmit_bytes_total{name=~".*${container:regex}.*",id!="/"}[5m])) by (name)', 8),
            ],
        ], JSON_UNESCAPED_SLASHES));

        return <<<BASH
mkdir -p /tmp/grafana-provisioning/datasources /tmp/grafana-provisioning/dashboards /tmp/grafana-dashboards
cat > /tmp/grafana-provisioning/datasources/prometheus.yml << 'EOF'
apiVersion: 1
datasources:
  - name: Prometheus
    uid: prometheus
    type: prometheus
    access: proxy
    url: http://prometheus:9090
    isDefault: true
EOF
cat > /tmp/grafana-provisioning/dashboards/provider.yml << 'EOF'
apiVersion: 1
providers:
  - name: gpsh
    orgId: 1
    type: file
    disableDeletion: true
    options:
      path: /tmp/grafana-dashboards
EOF
cat > /tmp/grafana-dashboards/odoo.json << 'EOF'
{$dashboard}
EOF
exec /run.sh
BASH;
    }

    /**
     * @return array<string, mixed>
     */
    private static function panel(int $id, string $title, int $x, string $expr, int $y = 0): array
    {
        return [
            'id' => $id,
            'type' => 'timeseries',
            'title' => $title,
            'gridPos' => ['h' => 8, 'w' => 12, 'x' => $x, 'y' => $y],
            'datasource' => ['type' => 'prometheus', 'uid' => 'prometheus'],
            'targets' => [[
                'refId' => 'A',
                'expr' => $expr,
                'legendFormat' => '{{name}}',
            ]],
        ];
    }

    private static function containerName(Service $service, string $kind): ?string
    {
        $uuid = (string) $service->uuid;
        if (preg_match('/^[A-Za-z0-9_-]+$/', $uuid) !== 1) {
            return null;
        }

        if ($kind === 'odoo') {
            $row = $service->applications()->get()->first(function ($application): bool {
                $name = strtolower((string) $application->name);

                return str_contains($name, 'odoo') && ! str_contains($name, 'jupyter');
            });
        } else {
            $row = $service->databases()->get()->first(function ($database): bool {
                return str_contains(strtolower((string) $database->name), 'postgres');
            });
        }

        $name = (string) ($row->name ?? '');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return null;
        }

        return $name.'-'.$uuid;
    }
}
