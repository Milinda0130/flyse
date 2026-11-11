<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_passport_numbers_are_encrypted_masked_and_absent_from_lists(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $customer = $this->postJson('/api/v1/customers', ['full_name' => 'Pass Holder', 'passport_number' => 'N7654321'])->assertCreated()->json();

        $raw = DB::table('passengers')->whereNotNull('passport_number')->value('passport_number');
        $this->assertNotSame('N1234567', $raw);
        $this->assertStringNotContainsString('N1234567', json_encode(AuditLog::all()->toArray()));

        $this->getJson("/api/v1/bookings/{$booking->id}")->assertJsonPath('passengers.0.passport_number', 'N1234567');
        $this->getJson('/api/v1/customers')->assertJsonMissingPath('data.0.passport_number');
        $this->getJson('/api/v1/bookings')->assertJsonMissingPath('data.0.passengers');

        $this->actingAsRole('readonly');
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertJsonPath('passengers.0.passport_number', '••••••67');
        $this->getJson("/api/v1/customers/{$customer['id']}")->assertJsonPath('customer.passport_number', '••••••21');
    }

    public function test_only_owner_manages_users_and_last_owner_is_protected(): void
    {
        $owner = $this->actingAsRole('owner');
        $this->postJson('/api/v1/users', ['name' => 'New Staff', 'email' => 'New@Agency.test', 'password' => 'Password1234', 'role' => 'staff'])
            ->assertCreated()->assertJsonPath('email', 'new@agency.test');
        $this->putJson("/api/v1/users/{$owner->id}", ['role' => 'staff'])->assertStatus(422);

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->putJson('/api/v1/settings', ['agency_name' => 'X'])->assertForbidden();
    }

    public function test_customer_suggestions_rank_names_starting_with_the_text_first(): void
    {
        $this->actingAsRole('staff');
        foreach (['Kasun Silva', 'Saman Perera', 'Nimal Samarasinghe', 'Ashan Dias'] as $name) {
            Customer::create(['full_name' => $name]);
        }

        $names = array_column($this->getJson('/api/v1/customers?q=s&per_page=8')->assertOk()->json('data'), 'full_name');
        $this->assertSame('Saman Perera', $names[0]);                 // starts with "s"
        $this->assertSame(['Kasun Silva', 'Nimal Samarasinghe'], array_slice($names, 1, 2)); // a word starts with "s"
        $this->assertContains('Ashan Dias', $names);                   // contains "s"

        $this->assertCount(4, $this->getJson('/api/v1/customers?per_page=8')->json('data')); // empty search lists customers
        $this->assertCount(0, $this->getJson('/api/v1/customers?q=%25')->json('data'));      // "%" is literal
    }
}
