<?php

namespace App\Enums;

enum PaymentDirection: string
{
    use HasOptions;

    case CustomerReceipt = 'customer_receipt';
    case CustomerRefund = 'customer_refund';
    case SupplierPayment = 'supplier_payment';
    case SupplierRefund = 'supplier_refund';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReceipt => 'Customer receipt',
            self::CustomerRefund => 'Refund to customer',
            self::SupplierPayment => 'Supplier payment',
            self::SupplierRefund => 'Refund from supplier',
        };
    }
}
