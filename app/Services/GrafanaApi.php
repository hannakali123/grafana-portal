<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GrafanaApi
{
    /* --------------------------------------------------------------
       Instanzweite Einstellungen (aus config/services.php / .env)
       -------------------------------------------------------------- */
    private string $baseUrl;       // z. B. http://localhost:3000/grafana/api
    private string $adminUser;     // admin
    private string $adminPassword; // ***

    public function __construct()
    {
        $cfg                 = config('services.grafana');
        $this->baseUrl       = rtrim($cfg['url'], '/') . '/api';
        $this->adminUser     = $cfg['user'];
        $this->adminPassword = $cfg['password'];
    }

    /* --------------------------------------------------------------
       Low-Level: HTTP-Request mit Basic-Auth (+ optional Org-Id)
       -------------------------------------------------------------- */
    private function grafanaRequest(string $method, string $uri, array $payload = [], int $orgId = null)
    {
        $http = Http::withBasicAuth($this->adminUser, $this->adminPassword)->acceptJson();

        if ($orgId) {
            $http->withHeaders(['X-Grafana-Org-Id' => $orgId]);
        }

        return $http->{$method}($this->baseUrl . $uri, $payload);
    }

    /* --------------------------------------------------------------
       High-Level: Provisioning pro Laravel-User (einmalig)
       -------------------------------------------------------------- */
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

        /* 2) Service-Account (SA) in dieser Org */
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

        /* 3) Exakt EIN frisches SA-Token */
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

        /* 3.5) Persönliche MySQL-DB anlegen/befüllen (z. B. "hanna_db") */
        $dbName = $this->ensureUserDatabase($user, $orgId);

        /* 4) Datasource + Dashboard (DS-Name pro User, z. B. "hanna-db") */
        $dsName = Str::slug($user->name, '-') . '-db';
        $this->ensureDatasourceAndDashboard($orgId, $dbName, $dsName);

        /* 5) Rückgabe an Laravel */
        return [
            'token'  => $tokenKey,  // wird in users.grafana_token gespeichert
            'org_id' => $orgId,     // wird im iFrame als ?orgId=… genutzt
        ];
    }

    /**
     * Legt eine DB "<username>_db" an (z. B. "hanna_db"),
     * erstellt Tabelle "sales" und befüllt sie mit 90 Tagen Demo-Daten, falls leer.
     * Liefert den Datenbanknamen zurück.
     */
    private function ensureUserDatabase(User $user, int $orgId): string
    {
        // DB-Name aus Usernamen: "hanna_db"
        $dbName = Str::slug($user->name, '_') . '_db';

        // 1) DB anlegen (wenn nicht existiert)
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // 2) Tabelle "sales" anlegen (wenn nicht existiert)
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

        // 3) Befüllen, falls leer
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

    /**
     * Stellt sicher, dass es in der Org eine Datasource $dsName gibt,
     * die auf $dbName zeigt, und importiert/aktualisiert das Simple-Sales-Dashboard.
     * Panels/Targets werden per DS-UID gebunden (robust gegen DS-Umbenennungen).
     */
    private function ensureDatasourceAndDashboard(int $orgId, string $dbName, string $dsName): void
    {
        // a) Datasource holen oder erstellen
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

            // die frisch angelegte DS erneut holen (für uid)
            $ds = $this->grafanaRequest('get', "/datasources/name/{$dsName}", [], $orgId)->json();
        }

        // b) Dashboard-JSON laden
        $jsonFile = base_path('grafana/simple_sales.json');
        if (!is_readable($jsonFile)) {
            return; // Datei fehlt → Bonus überspringen
        }

        $dashboard = json_decode(file_get_contents($jsonFile), true);
        unset($dashboard['id']); // nie feste id importieren

        // c) alle Panels/Targets an DS-UID binden (statt Name)
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

        // d) Import/Update (overwrite=true, damit die Bindung sicher greift)
        $this->grafanaRequest('post', '/dashboards/db', [
            'dashboard' => $dashboard,
            'folderId'  => 0,
            'overwrite' => true,
        ], $orgId);
    }
}
