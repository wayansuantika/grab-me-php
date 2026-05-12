<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\PaymentController;
use App\Controllers\ServiceController;
use App\Controllers\SettingsController;
use App\Controllers\TherapistController;
use App\Controllers\TherapistPanelController;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uriPath, $scriptDir)) {
    $uriPath = substr($uriPath, strlen($scriptDir)) ?: '/';
}

$uriPath = '/' . ltrim($uriPath, '/');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$routes = [
    'GET' => [
        '#^/api/auth/me$#' => [AuthController::class, 'me'],
        '#^/api/areas$#' => [ServiceController::class, 'areas'],
        '#^/api/services$#' => [ServiceController::class, 'categoriesWithServices'],
        '#^/api/therapists$#' => [TherapistController::class, 'list'],
        '#^/api/therapists/(\d+)$#' => [TherapistController::class, 'detail'],
        '#^/api/bookings/my$#' => [BookingController::class, 'myBookings'],
        '#^/api/therapist/dashboard$#' => [TherapistPanelController::class, 'dashboard'],
        '#^/api/therapist/bookings$#' => [TherapistPanelController::class, 'bookings'],
        '#^/api/therapist/profile$#' => [TherapistPanelController::class, 'profile'],
        '#^/api/therapist/files$#' => [TherapistPanelController::class, 'listFiles'],
        '#^/api/admin/dashboard$#' => [AdminController::class, 'dashboard'],
        '#^/api/admin/bookings$#' => [AdminController::class, 'bookings'],
        '#^/api/admin/therapists$#' => [AdminController::class, 'therapists'],
        '#^/api/admin/services$#' => [AdminController::class, 'services'],
        '#^/api/admin/areas$#' => [AdminController::class, 'areas'],
        '#^/api/admin/customers$#' => [AdminController::class, 'customers'],
        '#^/api/admin/payments$#' => [AdminController::class, 'payments'],
        '#^/api/admin/audit$#' => [AdminController::class, 'audits'],
        '#^/api/admin/files$#' => [AdminController::class, 'listFiles'],
        '#^/api/settings$#' => [SettingsController::class, 'get'],
    ],
    'POST' => [
        '#^/api/auth/register$#' => [AuthController::class, 'register'],
        '#^/api/auth/login$#' => [AuthController::class, 'login'],
        '#^/api/auth/logout$#' => [AuthController::class, 'logout'],
        '#^/api/bookings$#' => [BookingController::class, 'create'],
        '#^/api/payments/create-intent/?$#' => [PaymentController::class, 'createIntent'],
        '#^/api/payments/paypal/capture/?$#' => [PaymentController::class, 'capturePayPalOrder'],
        '#^/api/payments/webhook/?$#' => [PaymentController::class, 'webhook'],
        '#^/api/payments/paypal/webhook/?$#' => [PaymentController::class, 'paypalWebhook'],
        '#^/api/therapist/schedule$#' => [TherapistPanelController::class, 'updateSchedule'],
        '#^/api/therapist/profile-photo$#' => [TherapistPanelController::class, 'updateProfilePhoto'],
        '#^/api/therapist/profile-photo/select$#' => [TherapistPanelController::class, 'selectProfilePhoto'],
        '#^/api/therapist/profile$#' => [TherapistPanelController::class, 'updateProfile'],
        '#^/api/therapist/availability$#' => [TherapistPanelController::class, 'setAvailability'],
        '#^/api/admin/therapists/save$#' => [AdminController::class, 'saveTherapist'],
        '#^/api/admin/therapists/save-areas$#' => [AdminController::class, 'saveTherapistAreas'],
        '#^/api/admin/services/save$#' => [AdminController::class, 'saveService'],
        '#^/api/admin/areas/save$#' => [AdminController::class, 'saveArea'],
        '#^/api/admin/payments/confirm$#' => [AdminController::class, 'confirmPayment'],
        '#^/api/admin/payments/sync-stripe$#' => [AdminController::class, 'syncStripePayment'],
        '#^/api/admin/bookings/cancel$#' => [AdminController::class, 'cancelBooking'],
        '#^/api/admin/files/upload$#' => [AdminController::class, 'uploadFile'],
        '#^/api/admin/files/(\d+)/delete$#' => [AdminController::class, 'deleteFile'],
        '#^/api/admin/services/(\d+)/image$#' => [AdminController::class, 'uploadServiceImage'],
        '#^/api/admin/settings$#' => [SettingsController::class, 'save'],
    ],
];

if (str_starts_with($uriPath, '/api/')) {
    // Public CSRF token endpoint — allows guests to get a fresh token
    if ($uriPath === '/api/csrf' && $method === 'GET') {
        json_response(['success' => true, 'csrf_token' => csrf_token()]);
    }

    $methodRoutes = $routes[$method] ?? [];

    foreach ($methodRoutes as $pattern => $handler) {
        if (preg_match($pattern, $uriPath, $matches) === 1) {
            array_shift($matches);
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->{$action}(...array_map('intval', $matches));
            exit;
        }
    }

    foreach ($routes as $routeMethod => $routeList) {
        if ($routeMethod === $method) {
            continue;
        }
        foreach ($routeList as $pattern => $_handler) {
            if (preg_match($pattern, $uriPath) === 1) {
                json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
            }
        }
    }

    json_response(['success' => false, 'message' => 'Endpoint not found.'], 404);
}

$csrf = csrf_token();
$appName = config('app.name', 'GrabMas Spa');
$cssVersion = (string) (@filemtime(__DIR__ . '/assets/css/app.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/assets/js/app.js') ?: time());
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) $appName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="Luxury home-service spa reservation platform in Bali.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Marcellus&family=Mulish:wght@300;400;600;700&family=Manrope:wght@500;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= rawurlencode($cssVersion) ?>" rel="stylesheet">
</head>
<body>
    <div class="bg-shape bg-shape-1"></div>
    <div class="bg-shape bg-shape-2"></div>

    <header class="navbar navbar-expand-lg navbar-luxury sticky-top">
        <div class="container-fluid px-4 px-lg-5 header-shell">
            <div class="header-note d-none d-lg-flex">Bali Home Service Spa</div>
            <a class="navbar-brand" href="#/home">GrabMas</a>
            <div class="nav-right d-flex align-items-center gap-2 ms-auto">
                <div data-nav="booking"><a href="#/booking" class="btn btn-sm btn-book rounded-pill px-3">Book</a></div>
                <div data-nav="contact"><a href="#/contact" class="btn btn-sm btn-outline-dark rounded-pill px-3 d-none d-md-inline-flex">Concierge</a></div>
                <div class="d-none" data-nav="logout"><a href="#" id="navLogout" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Logout</a></div>
            </div>
        </div>
    </header>

    <main id="spa-view" class="container py-4" style="flex:1 0 auto;min-height:60vh;"></main>

    <footer class="footer-luxury">
        <div class="container py-5">
            <div class="row g-5">
                <div class="col-lg-4">
                    <h4 class="mb-3">GrabMas</h4>
                    <p class="small footer-muted lh-lg">Luxury home-service spa in Bali. Certified therapists. Signature treatments. Delivered to your villa, hotel, or home.</p>
                </div>
                <div class="col-6 col-lg-2">
                    <h6 class="footer-heading mb-3">Explore</h6>
                    <ul class="list-unstyled d-grid gap-1 small">
                        <li><a href="#/about">About</a></li>
                        <li><a href="#/booking">Booking</a></li>
                        <li><a href="#/auth">Login</a></li>
                    </ul>
                </div>
                <div class="col-6 col-lg-3">
                    <h6 class="footer-heading mb-3">Treatments</h6>
                    <ul class="list-unstyled d-grid gap-1 small">
                        <li><a href="#/services">Wellness Massage</a></li>
                        <li><a href="#/services">Pain &amp; Recovery</a></li>
                        <li><a href="#/services">Special Therapy</a></li>
                        <li><a href="#/booking">Book Now</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h6 class="footer-heading mb-3">Contact</h6>
                    <ul class="list-unstyled d-grid gap-2 small footer-muted">
                        <li>&#128205; Ubud · Canggu · Kuta · Sanur · Seminyak · Denpasar · Tabanan</li>
                        <li>&#128222; WhatsApp Concierge Available</li>
                        <li>&#128336; Open 7 Days &middot; 9 AM &ndash; 6 PM</li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom d-flex justify-content-between flex-wrap gap-2 mt-5 pt-4">
                <small class="footer-dim">&copy; 2026 GrabMas Spa. All rights reserved.</small>
                <small class="footer-dim">Bali, Indonesia</small>
            </div>
        </div>
    </footer>

    <script>
        window.APP_BOOTSTRAP = {
            csrfToken: <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>,
            appUrl: <?= json_encode((string) config('app.url', ''), JSON_UNESCAPED_SLASHES) ?>,
            stripePublishableKey: <?= json_encode((string) config('stripe.publishable_key', ''), JSON_UNESCAPED_SLASHES) ?>,
            stripeEnabled: <?= json_encode(((string) config('stripe.publishable_key', '') !== '' && (string) config('stripe.secret_key', '') !== '')) ?>,
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://js.stripe.com/v3/"></script>
    <script src="assets/js/app.js?v=<?= rawurlencode($jsVersion) ?>"></script>
</body>
</html>
