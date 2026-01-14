<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GrafanaApi
{

    private string $baseUrl;
    private string $adminUser;
    private string $adminPassword;

    public function __construct()
    {
        $cfg                 = config('services.grafana');
        $this->baseUrl       = rtrim($cfg['url'], '/') . '/api';
        $this->adminUser     = $cfg['user'];
        $this->adminPassword = $cfg['password'];
    }


    private function grafanaRequest(string $method, string $uri, array $payload = [], int $orgId = null)
    {
        $http = Http::withBasicAuth($this->adminUser, $this->adminPassword)->acceptJson();

        if ($orgId) {
            $http->withHeaders(['X-Grafana-Org-Id' => $orgId]);
        }

        return $http->{$method}($this->baseUrl . $uri, $payload);
    }


    public function bootstrapFor(User $user): array
    {
        /* 1) Organisation (eine pro User) */
        $orgName = 'u' . $user->id;
        $orgResp = $this->grafanaRequest('post', '/orgs', ['name' => $orgName]);

        $orgId = $orgResp->json('orgId')
            ?? $this->grafanaRequest('get', "/orgs/name/{$orgName}")->json('id');

        if (!$orgId) {
            throw new RuntimeException('Organisation konnte nicht angelegt / gefunden werden');
        }


        $saName = Str::slug($user->name) . '-sa';

        $sa = $this->grafanaRequest(
            'get',
            '/serviceaccounts/search',
            ['query' => $saName, 'perpage' => 1, 'orgId' => $orgId],
            $orgId
        )->json('serviceAccounts.0');

        if (!$sa) {
            $sa = $this->grafanaRequest(
                'post',
                '/serviceaccounts',
                ['name' => $saName, 'role' => 'Viewer', 'orgId' => $orgId],
                $orgId
            )->json();
        }


        foreach ($this->grafanaRequest('get', "/serviceaccounts/{$sa['id']}/tokens", [], $orgId)->json() as $t) {
            $this->grafanaRequest('delete', "/serviceaccounts/{$sa['id']}/tokens/{$t['id']}", [], $orgId);
        }

        $tokenKey = $this->grafanaRequest(
            'post',
            "/serviceaccounts/{$sa['id']}/tokens",
            ['name' => 't-' . Str::ulid(), 'role' => 'Viewer', 'secondsToLive' => 0],
            $orgId
        )->json('key');

        if (!$tokenKey) {
            throw new RuntimeException('Service-Account-Token konnte nicht erzeugt werden');
        }


        $dbName = $this->ensureUserDatabase($user, $orgId);


        $dsName = Str::slug($user->name, '-') . '-db';
        $this->ensureDatasourceAndDashboard($orgId, $dbName, $dsName);


        return [
            'token'  => $tokenKey,
            'org_id' => $orgId,
        ];
    }

    /**
     * Legt eine DB "<username>_db" an (z. B. "hanna_db"),
     * erstellt Tabelle "sales" und befüllt sie mit 90 Tagen Demo-Daten, falls leer.
     * Liefert den Datenbanknamen zurück.
     */
    private function ensureUserDatabase(User $user, int $orgId): string
    {

        $dbName = Str::slug($user->name, '_') . '_db';


        DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");


        DB::statement("
            CREATE TABLE IF NOT EXISTS `{$dbName}`.`sales` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `sale_date` DATE NOT NULL,
              `amount` DECIMAL(10,2) NOT NULL,
              `category` VARCHAR(255) NOT NULL,
              `created_at` TIMESTAMP NULL,
              `updated_at` TIMESTAMP NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");


        $row = DB::selectOne("SELECT COUNT(*) AS c FROM `{$dbName}`.`sales`");
        $count = $row ? (int)($row->c ?? 0) : 0;

        if ($count === 0) {
            $today = now()->startOfDay();
            // 90 Tage Demo-Daten
            for ($i = 0; $i < 90; $i++) {
                $date   = $today->copy()->subDays($i)->toDateString();
                $amount = random_int(80, 400) + random_int(0, 99) / 100;
                $cat    = ['Hardware', 'Software', 'Service'][array_rand([0, 1, 2])];

                DB::statement(
                    "INSERT INTO `{$dbName}`.`sales` (sale_date, amount, category, created_at, updated_at)
                     VALUES (DATE(?), ?, ?, NOW(), NOW())",
                    [$date, $amount, $cat]
                );
            }
        }

        return $dbName;
    }


    private function ensureDatasourceAndDashboard(int $orgId, string $dbName, string $dsName): void
    {

        $dsResp = $this->grafanaRequest('get', "/datasources/name/{$dsName}", [], $orgId);

        if ($dsResp->successful()) {
            $ds = $dsResp->json();

            // Update: auf die persönliche DB zeigen lassen
            $update = [
                'id'             => $ds['id'],
                'uid'            => $ds['uid'],
                'name'           => $dsName,
                'type'           => $ds['type'], // z. B. "mysql"
                'access'         => 'proxy',
                'isDefault'      => true,
                'url'            => env('DB_HOST') . ':' . env('DB_PORT'),
                'user'           => env('DB_USERNAME'),
                'database'       => $dbName,
                'secureJsonData' => ['password' => env('DB_PASSWORD')],
            ];

            $this->grafanaRequest('put', "/datasources/{$ds['id']}", $update, $orgId);
        } else {
            // neu anlegen
            $create = $this->grafanaRequest('post', '/datasources', [
                'name'           => $dsName,
                'type'           => 'mysql',
                'access'         => 'proxy',
                'isDefault'      => true,
                'url'            => env('DB_HOST') . ':' . env('DB_PORT'),
                'user'           => env('DB_USERNAME'),
                'database'       => $dbName,
                'secureJsonData' => ['password' => env('DB_PASSWORD')],
            ], $orgId);

            if ($create->failed()) {
                throw new RuntimeException(
                    'Datasource konnte nicht angelegt werden: ' . $create->status() . ' ' . $create->body()
                );
            }


            $ds = $this->grafanaRequest('get', "/datasources/name/{$dsName}", [], $orgId)->json();
        }


        $jsonFile = base_path('grafana/simple_sales.json');
        if (!is_readable($jsonFile)) {
            return;
        }

        $dashboard = json_decode(file_get_contents($jsonFile), true);
        unset($dashboard['id']);


        $dsRef = ['type' => $ds['type'], 'uid' => $ds['uid']];

        if (isset($dashboard['panels']) && is_array($dashboard['panels'])) {
            foreach ($dashboard['panels'] as &$panel) {
                $panel['datasource'] = $dsRef;
                if (!empty($panel['targets']) && is_array($panel['targets'])) {
                    foreach ($panel['targets'] as &$t) {
                        $t['datasource'] = $dsRef;
                    }
                    unset($t);
                }
            }
            unset($panel);
        }


        $this->grafanaRequest('post', '/dashboards/db', [
            'dashboard' => $dashboard,
            'folderId'  => 0,
            'overwrite' => true,
        ], $orgId);
    }
}
