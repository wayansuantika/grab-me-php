# GrabMas Luxury Home-Service Spa (Pure PHP + MySQL)

Modern mobile-first SPA-style reservation platform for Bali home-service spa operations.

## Features Included

- Single-page app style frontend (hash-based routing)
- Pure PHP 8 backend (shared-hosting friendly)
- MySQL schema for all requested core entities
- Customer authentication, booking, booking history
- Therapist filtering by area, service, and schedule
- Therapist panel endpoints (dashboard, schedule, profile photo)
- Admin dashboard endpoints (customers, therapists, bookings, payments)
- Stripe PaymentIntent integration (server-side curl)
- Stripe webhook verification (signature check)
- CSRF protection and session-based authentication
- Prepared statements via PDO
- Upload validation for therapist photo

## Stack

- Backend: PHP 8+
- Database: MySQL 5.7+/8+
- Frontend: HTML5, CSS3, vanilla JS, Bootstrap 5
- Payment: Stripe API
- Hosting target: Apache shared hosting / cPanel

## Quick Start (XAMPP)

1. Copy `.env.example` to `.env` and update DB + Stripe keys.
2. Create database `grabmas`.
3. Import [database/schema.sql](database/schema.sql).
4. Open `http://localhost/grabmas/public`.

## Default Admin Account

- Email: `admin@grabmas.local`
- Password hash in seed corresponds to: `Admin123!`

## Important API Endpoints

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/auth/me`
- `GET /api/areas`
- `GET /api/services`
- `GET /api/therapists?area_id=&service_id=&date=&time=`
- `POST /api/bookings`
- `GET /api/bookings/my`
- `POST /api/payments/create-intent`
- `POST /api/payments/webhook`
- `GET /api/therapist/dashboard`
- `POST /api/therapist/schedule`
- `POST /api/therapist/profile-photo`
- `GET /api/admin/dashboard`

## Stripe Notes

- Currency currently set to `idr`.
- Booking is marked `confirmed` only after `payment_intent.succeeded` webhook.
- Configure webhook URL in Stripe Dashboard:
  - `https://your-domain.com/api/payments/webhook`

## Shared Hosting Deployment

1. Upload project to hosting root.
2. Point document root to `public` directory if possible.
3. If not possible, keep root `.htaccess` rewrite enabled.
4. Set file permissions for upload path:
   - `public/uploads` writable
5. Keep `.env` outside public access where possible.

## Roadmap Alignment

- Phase 1: schema, auth, admin foundation complete
- Phase 2: therapist + service structure complete
- Phase 3: booking engine + filtering complete
- Phase 4: Stripe intent + webhook complete
- Phase 5: frontend polish baseline complete (further UX tuning optional)
