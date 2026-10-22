# Saudi HR Settlement Simulator

## Initial source release

This repository contains application source, migrations, tests and documentation. It excludes employee data, the local database, uploaded documents, backups, logs, dependencies and environment secrets. Install dependencies and create your own `.env` after cloning.

For a **new local installation** (PowerShell, PHP 8.2+, Composer and a Node.js version supported by Vite):

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File database/database.sqlite
php artisan migrate
```

The following optional command creates development sample accounts with the passwords listed below. Use it only in a new development database; change all sample passwords before any shared deployment:

```powershell
php artisan db:seed
```

Start the backend using `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/serve-local.ps1`. In a second terminal:

```powershell
cd frontend
npm ci
npm run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173`. Developer settings accounts are created interactively using `php artisan developer:create` from the project root. See [system configuration](docs/SYSTEM_CONFIGURATION.md), [development standards](docs/DEVELOPMENT_STANDARDS.md), and [implementation report](docs/SYSTEM_CONFIGURATION_IMPLEMENTATION.md).

Validation: `php artisan test` from the root; `npm run build` and `npm run lint` from `frontend`. Browser integration tests require their isolated test fixtures and services; see `frontend/e2e/README.md`.

A professional Laravel 12 + React project for simulating and calculating employee final settlements according to Saudi labor requirements.

## Architecture

- Laravel 12 backend API for all legal and accounting logic
- React frontend for form entry, interaction, and result presentation
- MySQL-ready schema for employees, contracts, salary components, rules, settlements, and audit logs
- Role-based access for admin, hr, and reviewer users
- Sanctum-based authentication for API access

## Backend modules

- Authentication
- Users
- Employees
- Contracts
- Salary Components
- Termination Types
- Labor Rules
- Settlements
- Settlement Calculator
- Reports
- Audit Logs

## Core legal calculation service

The settlement engine is kept in the Laravel backend to respect the architecture requirement:

- app/Services/Settlement/SettlementEngine.php
- app/Services/Settlement/ServicePeriodCalculator.php

React is limited to input, rendering, and interaction; legal calculations remain in Laravel services.

## Run locally

Backend:

```bash
cd c:/xampp/htdocs/Settlement
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```bash
cd c:/xampp/htdocs/Settlement/frontend
npm run dev
```

Seeded users:

- admin@saudihr.local / password123
- hr@saudihr.local / password123
- reviewer@saudihr.local / password123

## Notes

The database schema and service structure are aligned with the requested Saudi settlement specification. Future legal amendments can be implemented in the backend services without changing the React UI layer.
