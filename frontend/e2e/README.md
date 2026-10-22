# Phase 6 real backend audit

Tests use installed Chrome, real Laravel HTTP responses, and a dedicated SQLite file. Success paths do not mock APIs. Only the explicit network-failure test aborts a request.

From the project root, prepare the isolated database and start its API in terminal 1:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE='C:/xampp/htdocs/Settlement/database/phase6-e2e.sqlite'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
if (!(Test-Path -LiteralPath database/phase6-e2e.sqlite)) { New-Item -ItemType File -Path database/phase6-e2e.sqlite | Out-Null }
php frontend/e2e/seed.php
php -S 127.0.0.1:8001 -t public public/index.php
```

The seeder checks both environment and database filename before migrating or creating fixtures. It never resets a database. Run this only in a new terminal; close it afterward to discard its test environment variables.

Terminal 2:

```powershell
cd frontend
npm run build
$env:VITE_PROXY_TARGET='http://127.0.0.1:8001'
npm run preview -- --host 127.0.0.1 --port 5174 --strictPort
```

Terminal 3:

```powershell
cd frontend
$env:E2E_BASE_URL='http://127.0.0.1:5174'
npx playwright test
```

Chrome defaults to `C:/Program Files/Google/Chrome/Application/chrome.exe`; set `CHROME_PATH` for another installation. The ordinary developer frontend defaults to proxying `/api` to `127.0.0.1:8000`. A production host must likewise proxy `/api` to Laravel, or build with `VITE_API_BASE_URL` set appropriately.

The fixtures contain only synthetic audit employees and accounts. The dedicated database and generated browser outputs are ignored. The normal PHPUnit suite uses its separate in-memory SQLite database.
