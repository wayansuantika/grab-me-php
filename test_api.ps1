$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginBody = '{"email":"admin@grabmas.local","password":"Admin123!"}'
$loginResp = Invoke-WebRequest -Uri "http://localhost/grabmas/public/api/auth/login" -Method Post -Body $loginBody -ContentType "application/json" -SessionVariable session -UseBasicParsing
$csrfToken = ($loginResp.Content | ConvertFrom-Json).data.csrf_token
Write-Host "Admin CSRF: $csrfToken"
Write-Host "Login status: $($loginResp.StatusCode)"

$confirmBody = '{"payment_id":1,"target_status":"paid"}'
$confirmResp = Invoke-WebRequest -Uri "http://localhost/grabmas/public/api/admin/payments/confirm" -Method Post -Body $confirmBody -ContentType "application/json" -WebSession $session -Headers @{"X-CSRF-Token"=$csrfToken} -UseBasicParsing
Write-Host "Confirm status: $($confirmResp.StatusCode)"
Write-Host "Confirm response: $($confirmResp.Content)"

$bookingsResp = Invoke-WebRequest -Uri "http://localhost/grabmas/public/api/admin/bookings" -WebSession $session -UseBasicParsing
$bookings = ($bookingsResp.Content | ConvertFrom-Json).data
$booking2 = $bookings | Where-Object { $_.id -eq 2 }
Write-Host "Booking 2 payment_status: $($booking2.payment_status)"
Write-Host "Booking 2 booking_status: $($booking2.booking_status)"
Write-Host "Booking 2 order_details: $($booking2.order_details)"

$tLoginBody = '{"email":"putu@grabmassage.com","password":"Admin123!"}'
try {
    $tLoginResp = Invoke-WebRequest -Uri "http://localhost/grabmas/public/api/auth/login" -Method Post -Body $tLoginBody -ContentType "application/json" -SessionVariable tSession -UseBasicParsing
    Write-Host "Therapist login status: $($tLoginResp.StatusCode)"
    Write-Host "Therapist login response: $($tLoginResp.Content)"
    
    $tCsrf = ($tLoginResp.Content | ConvertFrom-Json).data.csrf_token
    $tBookingsResp = Invoke-WebRequest -Uri "http://localhost/grabmas/public/api/therapist/bookings" -WebSession $tSession -UseBasicParsing
    Write-Host "Therapist bookings: $($tBookingsResp.Content)"
} catch {
    Write-Host "Therapist login failed: $_"
}
