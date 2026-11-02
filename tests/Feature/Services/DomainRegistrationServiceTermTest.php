<?php

namespace Tests\Feature\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DomainRegistrationServiceTermTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_registrar_uses_one_year_selection(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->pendingRegistrarService('1');

        self::assertSame(
            '2027-11-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );
    }

    public function test_pending_registrar_uses_two_year_selection(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->pendingRegistrarService('2');

        self::assertSame(
            '2028-11-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );

        self::assertStringContainsString(
            '2028',
            $service->description,
        );
    }

    public function test_pending_registrar_uses_five_year_selection(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->pendingRegistrarService('5');

        self::assertSame(
            '2031-11-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );

        self::assertStringContainsString(
            '2031',
            $service->description,
        );
    }

    public function test_pending_hosting_keeps_monthly_plan(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->serviceWithPlan(
            status: Service::STATUS_PENDING,
            billingPeriod: 1,
            billingUnit: 'month',
        );

        $this->property(
            $service,
            'hosting_domain_link_role',
            'hosting',
        );

        $this->property(
            $service,
            'registration_period',
            '5',
        );

        self::assertSame(
            '2026-12-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );
    }

    public function test_ordinary_pending_service_keeps_plan_term(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->serviceWithPlan(
            status: Service::STATUS_PENDING,
            billingPeriod: 3,
            billingUnit: 'month',
        );

        $this->property(
            $service,
            'registration_period',
            '5',
        );

        self::assertSame(
            '2027-02-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );
    }

    public function test_active_registrar_renewal_uses_plan_term(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->serviceWithPlan(
            status: Service::STATUS_ACTIVE,
            billingPeriod: 1,
            billingUnit: 'year',
            expiresAt: '2031-11-02 12:00:00',
        );

        $this->property(
            $service,
            'hosting_domain_link_role',
            'registrar',
        );

        $this->property(
            $service,
            'registration_period',
            '5',
        );

        self::assertSame(
            '2032-11-02 00:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );
    }

    public function test_invalid_registration_period_falls_back_to_plan(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');

        $service = $this->pendingRegistrarService(
            'invalid',
        );

        self::assertSame(
            '2027-11-02 12:00:00',
            $service->calculateNextDueDate()
                ->toDateTimeString(),
        );
    }

    private function pendingRegistrarService(
        string $registrationPeriod,
    ): Service {
        $service = $this->serviceWithPlan(
            status: Service::STATUS_PENDING,
            billingPeriod: 1,
            billingUnit: 'year',
        );

        $this->property(
            $service,
            'hosting_domain_link_role',
            'registrar',
        );

        $this->property(
            $service,
            'registration_period',
            $registrationPeriod,
        );

        return $service;
    }

    private function serviceWithPlan(
        string $status,
        int $billingPeriod,
        string $billingUnit,
        ?string $expiresAt = null,
    ): Service {
        $user = User::factory()->create();
        $fixture = $this->createProduct();

        $fixture->plan->update([
            'type' => 'recurring',
            'billing_period' => $billingPeriod,
            'billing_unit' => $billingUnit,
        ]);

        return Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => $status,
            'expires_at' => $expiresAt,
            'currency_code' => 'USD',
            'price' => 10.00,
        ])->fresh([
            'plan',
            'product',
        ]);
    }
    private function property(
        Service $service,
        string $key,
        string $value,
    ): void {
        $service->properties()->updateOrCreate(
            [
                'key' => $key,
            ],
            [
                'value' => $value,
            ],
        );

        $service->unsetRelation('properties');
    }
}