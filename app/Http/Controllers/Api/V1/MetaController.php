<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/** Reference lists for dropdowns: enum options, configured currencies/methods and assignable users. */
class MetaController extends Controller
{
    public function __invoke(SettingsService $settings): JsonResponse
    {
        $s = $settings->all();

        return response()->json([
            'booking_statuses' => Enums\BookingStatus::options(),
            'trip_types' => Enums\TripType::options(),
            'supplier_types' => Enums\SupplierType::options(),
            'passenger_types' => Enums\PassengerType::options(),
            'flight_statuses' => Enums\FlightStatus::options(),
            'cabin_classes' => Enums\CabinClass::options(),
            'ticket_statuses' => Enums\TicketStatus::options(),
            'document_types' => Enums\DocumentType::options(),
            'document_sources' => Enums\DocumentSource::uploadOptions(),
            'verification_statuses' => Enums\VerificationStatus::options(),
            'quotation_statuses' => Enums\QuotationStatus::options(),
            'baggage_statuses' => Enums\BaggageStatus::options(),
            'refundabilities' => Enums\Refundability::options(),
            'yes_no_unknown' => Enums\YesNoUnknown::options(),
            'issue_types' => Enums\IssueType::options(),
            'issue_sources' => Enums\IssueSource::options(),
            'issue_severities' => Enums\IssueSeverity::options(),
            'issue_statuses' => Enums\IssueStatus::options(),
            'payment_directions' => Enums\PaymentDirection::options(),
            'payment_statuses' => Enums\PaymentStatus::options(),
            'user_roles' => Enums\UserRole::options(),
            'currencies' => $s['currencies'],
            'default_currency' => $s['default_currency'],
            'payment_methods' => $s['payment_methods'],
            'expense_categories' => $s['expense_categories'],
            'agency_name' => $s['agency_name'],
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
