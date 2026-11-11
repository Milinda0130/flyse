<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::get('me', [V1\AuthController::class, 'me']);
        Route::put('me/password', [V1\AuthController::class, 'changePassword']);
        Route::get('meta', V1\MetaController::class);
        Route::get('dashboard/summary', [V1\DashboardController::class, 'summary']);

        Route::get('customers', [V1\CustomerController::class, 'index']);
        Route::post('customers', [V1\CustomerController::class, 'store']);
        Route::get('customers/{customer}', [V1\CustomerController::class, 'show']);
        Route::put('customers/{customer}', [V1\CustomerController::class, 'update']);
        Route::delete('customers/{customer}', [V1\CustomerController::class, 'destroy']);

        Route::get('suppliers', [V1\SupplierController::class, 'index']);
        Route::post('suppliers', [V1\SupplierController::class, 'store']);
        Route::put('suppliers/{supplier}', [V1\SupplierController::class, 'update']);

        Route::get('bookings', [V1\BookingController::class, 'index']);
        Route::post('bookings', [V1\BookingController::class, 'store']);
        Route::get('bookings/{booking}', [V1\BookingController::class, 'show']);
        Route::put('bookings/{booking}', [V1\BookingController::class, 'update']);
        Route::get('bookings/{booking}/activity', [V1\BookingController::class, 'activity']);
        Route::post('bookings/{booking}/status', [V1\BookingController::class, 'transition']);

        // Emailing ticket PDFs to the customer
        Route::get('bookings/{booking}/ticket-email', [V1\TicketEmailController::class, 'show']);
        Route::post('bookings/{booking}/ticket-email/preview', [V1\TicketEmailController::class, 'preview']);
        Route::post('bookings/{booking}/ticket-email', [V1\TicketEmailController::class, 'store'])->middleware('throttle:ticket-email');
        Route::get('bookings/{booking}/generated-tickets/preview', [V1\GeneratedTicketController::class, 'preview']);
        Route::post('bookings/{booking}/generated-tickets', [V1\GeneratedTicketController::class, 'store'])->middleware('throttle:ticket-email');
        Route::get('bookings/{booking}/emails', [V1\TicketEmailController::class, 'history']);
        Route::post('booking-emails/{bookingEmail}/retry', [V1\TicketEmailController::class, 'retry'])->middleware('throttle:ticket-email');

        // Flight quotations (manual; never a reservation)
        Route::get('flight-quotations', [V1\FlightQuotationController::class, 'index']);
        Route::post('flight-quotations', [V1\FlightQuotationController::class, 'store']);
        Route::get('flight-quotations/{flightQuotation}', [V1\FlightQuotationController::class, 'show']);
        Route::put('flight-quotations/{flightQuotation}', [V1\FlightQuotationController::class, 'update']);
        Route::delete('flight-quotations/{flightQuotation}', [V1\FlightQuotationController::class, 'destroy']);
        Route::post('flight-quotations/{flightQuotation}/duplicate', [V1\FlightQuotationController::class, 'duplicate']);
        Route::post('flight-quotations/{flightQuotation}/status', [V1\FlightQuotationController::class, 'status']);
        Route::get('flight-quotations/{flightQuotation}/preview', [V1\FlightQuotationController::class, 'preview']);
        Route::post('flight-quotations/{flightQuotation}/pdf', [V1\FlightQuotationController::class, 'generatePdf']);
        Route::get('flight-quotations/{flightQuotation}/pdf', [V1\FlightQuotationController::class, 'download']);
        Route::get('flight-quotations/{flightQuotation}/pdfs/{pdf}', [V1\FlightQuotationController::class, 'downloadVersion']);
        Route::get('flight-quotations/{flightQuotation}/email', [V1\FlightQuotationController::class, 'emailInfo']);
        Route::post('flight-quotations/{flightQuotation}/email/preview', [V1\FlightQuotationController::class, 'emailPreview']);
        Route::post('flight-quotations/{flightQuotation}/email', [V1\FlightQuotationController::class, 'sendEmail'])->middleware('throttle:ticket-email');
        Route::get('flight-quotations/{flightQuotation}/emails', [V1\FlightQuotationController::class, 'emailHistory']);
        Route::post('quotation-emails/{quotationEmail}/retry', [V1\FlightQuotationController::class, 'retryEmail'])->middleware('throttle:ticket-email');

        // Airlines & logos
        Route::get('airlines', [V1\AirlineController::class, 'index']);
        Route::post('airlines', [V1\AirlineController::class, 'store']);
        Route::put('airlines/{airline}', [V1\AirlineController::class, 'update']);
        Route::delete('airlines/{airline}', [V1\AirlineController::class, 'destroy']);
        Route::get('airlines/{airline}/logo', [V1\AirlineController::class, 'logo']);
        Route::post('airlines/{airline}/logo', [V1\AirlineController::class, 'uploadLogo']);
        Route::delete('airlines/{airline}/logo', [V1\AirlineController::class, 'deleteLogo']);

        Route::post('bookings/{booking}/passengers', [V1\PassengerController::class, 'store']);
        Route::put('passengers/{passenger}', [V1\PassengerController::class, 'update']);
        Route::delete('passengers/{passenger}', [V1\PassengerController::class, 'destroy']);

        Route::post('bookings/{booking}/flights', [V1\BookingFlightController::class, 'store']);
        Route::put('booking-flights/{bookingFlight}', [V1\BookingFlightController::class, 'update']);
        Route::delete('booking-flights/{bookingFlight}', [V1\BookingFlightController::class, 'destroy']);

        Route::post('bookings/{booking}/tickets', [V1\TicketController::class, 'store']);
        Route::put('tickets/{ticket}', [V1\TicketController::class, 'update']);
        Route::delete('tickets/{ticket}', [V1\TicketController::class, 'destroy']);

        Route::post('bookings/{booking}/documents', [V1\DocumentController::class, 'store']);
        Route::get('documents/{document}/download', [V1\DocumentController::class, 'download']);
        Route::delete('documents/{document}', [V1\DocumentController::class, 'destroy']);
        Route::patch('documents/{document}/verification', [V1\DocumentController::class, 'verify']);

        Route::get('issues', [V1\BookingIssueController::class, 'index']);
        Route::post('bookings/{booking}/issues', [V1\BookingIssueController::class, 'store']);
        Route::get('issues/{issue}', [V1\BookingIssueController::class, 'show']);
        Route::patch('issues/{issue}', [V1\BookingIssueController::class, 'update']);
        Route::post('issues/{issue}/updates', [V1\BookingIssueController::class, 'addUpdate']);

        Route::get('payments', [V1\PaymentController::class, 'index']);
        Route::get('bookings/{booking}/payments', [V1\PaymentController::class, 'bookingIndex']);
        Route::post('bookings/{booking}/payments', [V1\PaymentController::class, 'store']);
        Route::patch('payments/{payment}', [V1\PaymentController::class, 'update']);

        Route::get('expenses', [V1\ExpenseController::class, 'index']);
        Route::post('expenses', [V1\ExpenseController::class, 'store']);
        Route::post('expenses/{expense}', [V1\ExpenseController::class, 'update']); // POST: multipart with optional receipt
        Route::delete('expenses/{expense}', [V1\ExpenseController::class, 'destroy']);

        Route::get('reports/overview', [V1\ReportController::class, 'overview']);
        Route::get('reports/sales', [V1\ReportController::class, 'sales']);
        Route::get('reports/outstanding', [V1\ReportController::class, 'outstanding']);
        Route::get('reports/issues', [V1\ReportController::class, 'issues']);
        Route::get('reports/export', [V1\ReportController::class, 'export']);

        Route::get('users', [V1\UserController::class, 'index']);
        Route::post('users', [V1\UserController::class, 'store']);
        Route::put('users/{user}', [V1\UserController::class, 'update']);

        Route::get('settings', [V1\SettingsController::class, 'show']);
        Route::put('settings', [V1\SettingsController::class, 'update']);
        Route::get('settings/logo', [V1\SettingsController::class, 'logo']);
        Route::post('settings/logo', [V1\SettingsController::class, 'uploadLogo']);
        Route::delete('settings/logo', [V1\SettingsController::class, 'deleteLogo']);
        Route::get('audit-logs', [V1\AuditLogController::class, 'index']);
    });
});
