# Laravel + Grafana Portal (Multi-User, 1 Grafana Instanz)

Kleines Laravel-Portal mit Login/Signup, das nach dem Einloggen ein **Grafana** per **iframe** anzeigt.  
Wichtig: Jeder Benutzer sieht **nur seine eigenen Dashboards** – und das mit **nur einer Grafana-Instanz**.

Die Dashboards sind **nicht öffentlich**. Sichtbarkeit passiert über **Grafana-Authentifizierung** (Service Account Token), nicht über Laravel-Auth.

---

## Idee / Hintergrund

Die Aufgabe war: Laravel-Login bauen und danach ein eingebettetes Grafana anzeigen – aber so, dass Benutzer untereinander **keine** Dashboards sehen können.  
Ich hab das so gelöst, dass pro Benutzer eine eigene Grafana-Organisation + Service Account erstellt wird und Laravel Grafana über einen Proxy einbettet.

---

## Features

- Login/Logout + Signup (Laravel Auth)
- Benutzer in MySQL gespeichert
- Grafana wird im Portal in einem iframe angezeigt
- Trennung pro User über **Grafana Organizations**
- Pro User wird beim Signup automatisch provisioniert:
  - Organization `u{userId}`
  - Service Account (Viewer)
  - Token (wird beim User gespeichert)
- Grafana läuft lokal (eine Instanz)

**Bonus (optional):**
- Pro User eigene MySQL DB + Grafana Datasource + Demo-Dashboard

---

## Tech Stack

- Laravel (PHP)
- Blade
- MySQL
- Grafana

---

## Wie die Auth funktioniert (kurz)

Ich nutze einen Laravel-Proxy (`/grafana/...`), damit das iframe über die gleiche Origin läuft.  
Beim Proxy-Request setzt Laravel Header:

- `Authorization: Bearer <token>`
- `X-Grafana-Org-Id: <orgId>`

So bekommt Grafana die Auth-Daten und zeigt nur die Dashboards aus der passenden Org.

---

## Voraussetzungen

- PHP (8.x)
- Composer
- MySQL
- Grafana (lokal installiert und gestartet)

---

## Setup (lokal)

### 1) Projekt installieren
```bash
composer install
cp .env.example .env
php artisan key:generate
```


### 2) Datenbank einrichten
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=secret

```
Migrationen:

```bash
php artisan migrate
```
(Optional, falls Seeds vorhanden):
```bash
php artisan db:seed
```
### 3) Grafana starten

Grafana läuft bei mir lokal unter:
- http://localhost:3000



In `.env` die Grafana Daten setzen:


```env
GRAFANA_URL=http://localhost:3000
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=admin

```
### 4) Laravel starten

```bash
php artisan serve
```

## Troubleshooting

- **Im iframe kommt ein Grafana-Login statt Dashboard:**  
  Prüfen ob `GRAFANA_URL` stimmt und ob beim User `grafana_token` / `grafana_org_id` gesetzt sind.

- **Signup/Provisioning schlägt fehl:**  
  Grafana muss laufen + Admin-Zugangsdaten in `.env` müssen stimmen.

