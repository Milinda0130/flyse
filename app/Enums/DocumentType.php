<?php

namespace App\Enums;

enum DocumentType: string
{
    use HasOptions;

    case ETicket = 'e_ticket';
    /** A ticket PDF not received through the agency's normal process (customer-provided etc.). Never an official e-ticket. */
    case AlternativeTicket = 'alternative_ticket';
    case SupplierInvoice = 'supplier_invoice';
    case Confirmation = 'confirmation';
    case AirlineEmail = 'airline_email';
    case CustomerInvoice = 'customer_invoice';
    case CancellationNotice = 'cancellation_notice';
    case RefundDocument = 'refund_document';
    case PassportCopy = 'passport_copy';
    case ExpenseReceipt = 'expense_receipt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ETicket => 'E-ticket',
            self::AlternativeTicket => 'Alternative ticket PDF',
            self::SupplierInvoice => 'Supplier invoice',
            self::Confirmation => 'Booking confirmation',
            self::AirlineEmail => 'Airline email',
            self::CustomerInvoice => 'Customer invoice / receipt',
            self::CancellationNotice => 'Cancellation notice',
            self::RefundDocument => 'Refund document',
            self::PassportCopy => 'Passport copy',
            self::ExpenseReceipt => 'Expense receipt',
            self::Other => 'Other',
        };
    }
}
