# App Stats Tracker

*by **VITTHEO***

![CI](https://img.shields.io/badge/CI-GitHub%20Actions-blue?logo=githubactions)
![Site](https://img.shields.io/badge/Site-PHP%208%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![SDKs](https://img.shields.io/badge/SDKs-Python%20%7C%20Java%20%7C%20C%20%7C%20C%23%20%7C%20Rust-4f8cff)

**App Stats Tracker** est une solution légère, open-source et auto-hébergeable composée d'un **site web & API 100 % PHP** (zéro dépendance, compatible hébergement mutualisé) et d'**un seul fichier SDK autonome** pour **Python, Java, C, C# et Rust**.

## Fonctionnalités

- **Métriques suivies** :
  - **Version de l'application** (`app_version`)
  - **Pays** (`country`, code ISO à 2 lettres via en-têtes proxy/CDN ou locale système — **sans géolocalisation précise ni stockage d'IP**)
  - **Système d'exploitation & architecture** (`os`, `os_version`, `arch`)
  - **Nombre d'utilisations** (`total_uses`, historique quotidien) & **appareils uniques** (identifiant anonyme haché SHA-256)
  - **Langage / SDK** (`python`, `java`, `c`, `csharp`, `rust`)
- **Site 100 % PHP pur (PHP 8.0+)** :
  - Aucune dépendance Composer, aucun Python ni Node.js côté serveur.
  - Base **SQLite** intégrée (créée automatiquement dans `web/data/stats.db`) avec support optionnel de **MySQL / MariaDB** dans `web/config.php`.
  - Fonctionne sur n'importe quel hébergement PHP (OVH, o2switch, Hostinger, XAMPP, Laragon, VPS Apache/Nginx, Docker) — même dans un sous-dossier ou sans `mod_rewrite`.
- **5 SDK mono-fichier autonomes** :
  - Faciles à copier-coller, zéro dépendance lourde, non bloquants, tolérants aux pannes.
  - Respectent nativement `DO_NOT_TRACK=1` et `APP_STATS_OPTOUT=1`.

---

## Structure du dépôt

```text
app-stats-tracker/
├── web/                        # Site web + API REST 100 % PHP (à déposer sur votre serveur)
│   ├── index.php               # Dashboard web interactif (FR/EN)
│   ├── api.php                 # API REST (/api/v1/track, /stats, /export.csv, /health)
│   ├── db.php                  # Couche PDO (SQLite par défaut, MySQL optionnel)
│   ├── config.php              # Configuration (BDD, clés API, rate-limit, CORS)
│   ├── router.php              # Routeur pour `php -S` en local
│   ├── .htaccess               # Réécriture d'URL Apache + protection de data/ et config.php
│   ├── styles.css              # Feuille de style du Dashboard
│   └── app.js                  # Graphiques SVG, filtres, export CSV, snippets SDK
├── sdks/
│   ├── python/app_stats.py     # SDK Python (fichier unique, stdlib)
│   ├── java/AppStats.java      # SDK Java 11+ (fichier unique, stdlib)
│   ├── c/app_stats.h           # SDK C single-header (WinHTTP sur Windows, libcurl ou POSIX sur Linux/macOS)
│   ├── csharp/AppStats.cs      # SDK C# .NET 6+ (fichier unique, stdlib)
│   └── rust/app_stats.rs       # SDK Rust (fichier unique, zéro crate)
├── tests/test_php.php          # Tests unitaires PHP
├── Dockerfile                  # Image php:8.3-apache prête à l'emploi
├── docker-compose.yml
└── .github/workflows/ci.yml    # CI GitHub Actions (PHP + les 5 SDK)
```

---

## Installer & lancer le site PHP

### Option 1 — Hébergement web PHP classique (OVH, o2switch, Hostinger, FTP…)

1. Copiez tout le contenu du dossier **`web/`** dans votre dossier public (`public_html/`, `www/` ou un sous-dossier `public_html/stats/`).
2. Vérifiez que PHP **8.0+** avec l'extension `pdo_sqlite` (activée par défaut) est actif et que le dossier `web/data/` est accessible en écriture.
3. Ouvrez votre site : la base SQLite se crée automatiquement dès la première requête !

> Si votre hébergeur ne propose que MySQL/MariaDB, modifiez `db_driver => 'mysql'` et vos identifiants dans [`web/config.php`](web/config.php).

### Option 2 — En local avec PHP

```bash
php -S localhost:8080 -t web web/router.php
```

Ouvrez ensuite **http://localhost:8080/**.

### Option 3 — Avec Docker Compose (`php:8.3-apache`)

```bash
docker compose up -d --build
```

### Configuration (`web/config.php` ou variables d'environnement)

| Variable | Défaut | Description |
| --- | --- | --- |
| `APP_STATS_DB_DRIVER` | `sqlite` | `sqlite` ou `mysql` |
| `APP_STATS_DB` | `web/data/stats.db` | Chemin de la base SQLite |
| `APP_STATS_API_KEY` | *(vide)* | Clé optionnelle exigée dans l'en-tête `X-API-Key` pour `POST /api/v1/track` |
| `APP_STATS_ADMIN_KEY` | *(vide)* | Clé optionnelle (`X-Admin-Key` ou `?key=...`) pour protéger la lecture des stats et l'export CSV |
| `APP_STATS_RATE_LIMIT` | `120` | Requêtes POST max par IP et par minute (`0` = désactivé) |
| `APP_STATS_CORS_ORIGIN` | `*` | En-tête `Access-Control-Allow-Origin` |

> **Nginx** : si vous utilisez Nginx au lieu d'Apache, ajoutez dans votre bloc `server` :
> ```nginx
> location ~ ^/(data/|config\.php|db\.php|router\.php) { deny all; }
> location ~ ^/api(?:/v1)?/(track|stats|export\.csv|health)/?$ { rewrite ^/api(?:/v1)?/(.*)$ /api.php?route=$1 last; }
> ```
> **Sans `mod_rewrite`** : tous les SDK acceptent aussi directement `https://votre-site.com/api.php` comme URL !

---

## Intégrer dans votre code (1 seul fichier par langage)

Remplacez `https://votre-site.com` par l'URL de votre site PHP (ex. `http://localhost:8080` en local).

### Python — `sdks/python/app_stats.py`

Copiez [`sdks/python/app_stats.py`](sdks/python/app_stats.py) dans votre projet :

```python
from app_stats import AppStats

stats = AppStats("https://votre-site.com", app_name="mon-app", app_version="1.0.0")
stats.track()  # Envoi asynchrone non bloquant (ou stats.track_sync() pour un script CLI)
```

Tester l'exemple :
```bash
python sdks/python/example.py
```

### Java — `sdks/java/AppStats.java`

Copiez [`sdks/java/AppStats.java`](sdks/java/AppStats.java) dans votre projet (Java 11+, zéro dépendance) :

```java
AppStats stats = new AppStats("https://votre-site.com", "mon-app", "1.0.0");
stats.track(); // Asynchrone non bloquant (ou stats.trackSync() pour un outil CLI)
```

Tester l'exemple :
```bash
javac sdks/java/AppStats.java sdks/java/Example.java
java -cp sdks/java Example
```

### C — `sdks/c/app_stats.h`

Copiez [`sdks/c/app_stats.h`](sdks/c/app_stats.h) dans votre projet (bibliothèque single-header) :

```c
#define APP_STATS_IMPLEMENTATION
#include "app_stats.h"

int main(void) {
    app_stats_track("https://votre-site.com", "mon-app", "1.0.0", "launch", NULL);
    return 0;
}
```

Compilation :
```bash
# Windows (MSVC)
cl sdks/c/example.c winhttp.lib

# Windows (MinGW / GCC)
gcc sdks/c/example.c -lwinhttp -o example.exe

# Linux / macOS (HTTP + HTTPS via libcurl)
gcc sdks/c/example.c -lcurl -o example

# Linux / macOS sans libcurl (HTTP uniquement)
gcc -DAPP_STATS_NO_CURL sdks/c/example.c -o example
```

### C# — `sdks/csharp/AppStats.cs`

Copiez [`sdks/csharp/AppStats.cs`](sdks/csharp/AppStats.cs) dans votre projet (.NET 6+, zéro package NuGet) :

```csharp
using AppStatsSdk;

var stats = new AppStats("https://votre-site.com", "mon-app", "1.0.0");
await stats.TrackAsync(); // ou stats.TrackInBackground();
```

Tester l'exemple :
```bash
dotnet run --project sdks/csharp/example/Example.csproj
```

### Rust — `sdks/rust/app_stats.rs`

Copiez [`sdks/rust/app_stats.rs`](sdks/rust/app_stats.rs) dans votre dossier `src/` (zéro crate externe) :

```rust
mod app_stats;

fn main() {
    app_stats::AppStats::new("https://votre-site.com", "mon-app", "1.0.0").track();
}
```

Tester l'exemple :
```bash
rustc --edition 2021 sdks/rust/example.rs -o example
./example
```

---

## Documentation de l'API REST PHP

| Route (avec réécriture) | Route directe (sans `mod_rewrite`) | Méthode | Description |
| --- | --- | --- | --- |
| `/api/v1/track` | `/api.php` ou `/api.php?route=track` | `POST` | Enregistre une utilisation JSON |
| `/api/v1/stats?app=all&days=30` | `/api.php?route=stats&app=all&days=30` | `GET` | Retourne les statistiques agrégées |
| `/api/v1/export.csv` | `/api.php?route=export.csv` | `GET` | Télécharge les statistiques en CSV |
| `/api/v1/health` | `/api.php?route=health` | `GET` | Vérification de santé (`{"status":"ok"}`) |

Exemple de payload `POST /api/v1/track` :
```json
{
  "app_name": "mon-app",
  "app_version": "1.2.0",
  "os": "Windows",
  "os_version": "11",
  "arch": "x86_64",
  "country": "FR",
  "client_id": "a1b2c3d4e5f6...",
  "sdk": "python",
  "event": "launch"
}
```

---

## Confidentialité & Opt-out

Les utilisateurs finaux peuvent désactiver l'envoi de statistiques à tout moment en définissant :

```bash
DO_NOT_TRACK=1
# ou
APP_STATS_OPTOUT=1
```

## Licence

Créé par **VITTHEO** — distribué sous licence [MIT](LICENSE).
