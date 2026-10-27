# Event Registration System

Laravel 12 API and Vue 3/Vite frontend for attendee registration, QR passes, and event check-in.

## Run locally

### Backend

```powershell
cd backend
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
- `backend/app/Models`: users, registrations, and check-ins
- `backend/app/Services`: registration, QR payload, and check-in workflows
- `frontend/src/components`: reusable registration, QR, status, and user UI
- `frontend/src/views`: public, attendee, and admin routes
- `frontend/src/stores`: Pinia state for auth, registration, and check-in
## Test

```powershell
cd backend
php artisan test        # feature tests: auth, registration, check-in, reports, settings

cd ../frontend
npm run build           # production build; must succeed before deploying
```

## Access rules

- Roles that can open the admin workspace are listed once in `User::STAFF_ROLES` (mirrored in `frontend/src/stores/auth.js`).
- Master Admins (`admin`) see every event; other staff roles only see and check in to events they created (`EventPolicy`).
- Attendees can only view, edit, cancel, or self check-in their own registration. A registration's QR payload is the check-in credential, so it is never served publicly.
- Sign-in, sign-up and password changes are rate limited to 10 requests per minute per IP.
