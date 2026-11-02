# Event Registration System

Laravel 12 API and Vue 3/Vite frontend for attendee registration, QR passes, and event check-in.

## Run locally

### Backend

```powershell
cd backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The seeded admin account is `admin@example.com` / `password` for local development only.

### Frontend

```powershell
cd frontend
npm install
npm run dev
```

Copy `frontend/.env.example` to `frontend/.env` if the API is hosted somewhere other than `http://localhost:8000/api`.

## Structure

- `backend/app/Http`: controllers, requests, and API resources
- `backend/app/Models`: users, events, registrations, check-ins, and personnel records
- `backend/app/Services`: registration, QR payload, and check-in workflows
- `frontend/src/components`: registration form, QR tools, and `personnel/` portal pages
- `frontend/src/views`: public, attendee, and admin routes
- `frontend/src/stores`: Pinia state for auth, registration, and check-in

## Test

```powershell
cd backend
php artisan test

cd ../frontend
npm run build           # must succeed before deploying
```

## Deploy

1. Backend `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, the database, and `ADMIN_EMAIL` / `ADMIN_PASSWORD` for the first Master Admin.
2. `composer install --no-dev --optimize-autoloader`, `php artisan key:generate` (first time only), `php artisan migrate --force`, `php artisan db:seed --force` (first time only), `php artisan storage:link`, `php artisan config:cache`, `php artisan route:cache`.
3. Frontend `.env`: `VITE_API_URL` and `VITE_PUBLIC_APP_URL`, then `npm ci` and `npm run build`. Serve `dist/` with all unknown paths falling back to `index.html`.
4. Serve both over HTTPS: browsers only allow the QR scanner's camera on HTTPS.

## Access rules

- Roles that can open the admin workspace are listed once in `User::STAFF_ROLES` (mirrored in `frontend/src/stores/auth.js`).
- The event pages in the admin workspace are for Master Admins and Event Admins. All staff get the Personnel Portal; supervisors (`admin`, `manager`) approve requests addressed to them, and Master Admins act as HR.
- Master Admins (`admin`) see every event; other staff roles only see and check in to events they created (`EventPolicy`).
- Attendees can only view, edit, cancel, or self check-in their own registration. A registration's QR payload is the check-in credential, so it is never served publicly.
- Sign-in, sign-up and password changes are rate limited to 10 requests per minute per IP.
