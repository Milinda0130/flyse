<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A flight quotation no longer needs a customer record (the email address can be typed when sending). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only safe when every quotation has a customer; otherwise keep the column nullable.
        if (! DB::table('flight_quotations')->whereNull('customer_id')->exists()) {
            Schema::table('flight_quotations', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            });
        }
    }
};
