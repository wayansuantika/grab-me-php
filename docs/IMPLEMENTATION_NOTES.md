# Implementation Notes

## SPA Pattern

This platform uses a hybrid SPA approach:

- Apache rewrites all non-file requests to `public/index.php`
- API routes are handled server-side in same entrypoint
- Frontend navigation is hash-based for shared-hosting compatibility

## Security Controls Implemented

- Password hashing (`password_hash`, `password_verify`)
- Session regeneration on login
- CSRF token issuance and verification on write APIs
- PDO prepared statements throughout controllers
- Upload MIME/size validation for image uploads
- Stripe webhook signature verification via HMAC SHA-256

## Therapist Coverage Design

- `coverage_areas` stores Bali service areas
- `therapist_coverage_areas` maps therapists to areas
- `therapist_services` maps specialties
- `therapist_schedules` stores day/time availability

Filtering endpoint:

- `GET /api/therapists?area_id=&service_id=&date=&time=`

## Role Model

- Customer: book and pay
- Therapist: dashboard, schedule, profile updates
- Admin: reporting and management visibility

## Future Enhancements

- Google Maps geolocation auto-area suggestion
- Stripe Elements checkout UI in frontend
- Full CRUD for admin service and therapist management
- Real calendar scheduler for therapist panel
- Automated reports export
