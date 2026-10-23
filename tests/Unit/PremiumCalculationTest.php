<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\SumInsured;
use App\Services\Pricing\PremiumCalculationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculationTest extends TestCase
{
    protected PremiumCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PremiumCalculationService();
    }

    /**
     * Requirement 5 & 11: Mandatory pricing sample test
     * PLUS (₹20), 5 travel days (2026-11-10 to 2026-11-14), DOB 1998-04-10 (age 28 -> 1.00),
     * INTERNATIONAL (1.80), ₹300,000 (1.25) -> ₹225.00
     */
    public function test_mandatory_sample_premium_calculation_equals_225(): void
    {
        $result = $this->service->calculate(
            planCode: 'PLUS',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            dob: '1998-04-10',
            destination: 'INTERNATIONAL',
            sumInsured: 300000
        );

        $this->assertEquals('225.00', $result['premium_amount']);
        $this->assertEquals(5, $result['snapshot']['travel_days']);
        $this->assertEquals('1.00', $result['snapshot']['age_multiplier']);
        $this->assertEquals('1.80', $result['snapshot']['destination_multiplier']);
        $this->assertEquals('1.25', $result['snapshot']['coverage_multiplier']);
        $this->assertEquals('20.00', $result['snapshot']['base_daily_rate']);
    }

    /**
     * Travel days: Count both start date and end date (inclusive)
     */
    public function test_inclusive_travel_day_calculation(): void
    {
        // 1 day (same day start and end)
        $sameDay = $this->service->calculate(
            planCode: 'BASIC',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-01',
            dob: '2000-01-01',
            destination: 'DOMESTIC',
            sumInsured: 100000
        );
        $this->assertEquals(1, $sameDay['snapshot']['travel_days']);
        $this->assertEquals('12.00', $sameDay['premium_amount']); // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00

        // 10 days
        $tenDays = $this->service->calculate(
            planCode: 'BASIC',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-10',
            dob: '2000-01-01',
            destination: 'DOMESTIC',
            sumInsured: 100000
        );
        $this->assertEquals(10, $tenDays['snapshot']['travel_days']);
        $this->assertEquals('120.00', $tenDays['premium_amount']); // 12 * 10 = 120.00
    }

    /**
     * Age multiplier boundaries:
     * 0-17: 1.10
     * 18-60: 1.00
     * 61-70: 1.50
     * 71+: 2.00
     */
    public function test_age_multiplier_boundaries(): void
    {
        // Boundary: 0 years old
        $this->assertEquals('1.10', $this->service->getAgeMultiplier(0));
        // Boundary: 17 years old
        $this->assertEquals('1.10', $this->service->getAgeMultiplier(17));
        // Boundary: 18 years old
        $this->assertEquals('1.00', $this->service->getAgeMultiplier(18));
        // Boundary: 60 years old
        $this->assertEquals('1.00', $this->service->getAgeMultiplier(60));
        // Boundary: 61 years old
        $this->assertEquals('1.50', $this->service->getAgeMultiplier(61));
        // Boundary: 70 years old
        $this->assertEquals('1.50', $this->service->getAgeMultiplier(70));
        // Boundary: 71 years old
        $this->assertEquals('2.00', $this->service->getAgeMultiplier(71));
        // Boundary: 85 years old
        $this->assertEquals('2.00', $this->service->getAgeMultiplier(85));
    }

    /**
     * Destination multiplier tests: DOMESTIC (1.00), INTERNATIONAL (1.80)
     */
    public function test_destination_multipliers(): void
    {
        $domestic = $this->service->calculate(
            planCode: 'PREMIUM',
            tripStart: '2026-10-01',
            tripEnd: '2026-10-02', // 2 days
            dob: '1990-01-01',     // age 36 -> 1.00
            destination: 'DOMESTIC', // 1.00
            sumInsured: 100000     // 1.00
        );
        // 30 * 2 * 1.00 * 1.00 * 1.00 = 60.00
        $this->assertEquals('60.00', $domestic['premium_amount']);

        $intl = $this->service->calculate(
            planCode: 'PREMIUM',
            tripStart: '2026-10-01',
            tripEnd: '2026-10-02', // 2 days
            dob: '1990-01-01',     // age 36 -> 1.00
            destination: 'INTERNATIONAL', // 1.80
            sumInsured: 100000     // 1.00
        );
        // 30 * 2 * 1.00 * 1.80 * 1.00 = 108.00
        $this->assertEquals('108.00', $intl['premium_amount']);
    }

    /**
     * Coverage multiplier tests:
     * 100,000 -> 1.00
     * 300,000 -> 1.25
     * 500,000 -> 1.50
     */
    public function test_coverage_multipliers(): void
    {
        $tier1 = $this->service->calculate('BASIC', '2026-10-01', '2026-10-01', '1995-01-01', 'DOMESTIC', 100000);
        $this->assertEquals('12.00', $tier1['premium_amount']); // 12 * 1 * 1.00 = 12.00

        $tier2 = $this->service->calculate('BASIC', '2026-10-01', '2026-10-01', '1995-01-01', 'DOMESTIC', 300000);
        $this->assertEquals('15.00', $tier2['premium_amount']); // 12 * 1 * 1.25 = 15.00

        $tier3 = $this->service->calculate('BASIC', '2026-10-01', '2026-10-01', '1995-01-01', 'DOMESTIC', 500000);
        $this->assertEquals('18.00', $tier3['premium_amount']); // 12 * 1 * 1.50 = 18.00
    }

    /**
     * Invalid travel dates must throw an exception
     */
    public function test_trip_end_before_trip_start_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate('BASIC', '2026-10-10', '2026-10-05', '1995-01-01', 'DOMESTIC', 100000);
    }

    /**
     * Unsupported sum insured must throw an exception
     */
    public function test_unsupported_coverage_amount_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate('BASIC', '2026-10-01', '2026-10-05', '1995-01-01', 'DOMESTIC', 200000);
    }
}
