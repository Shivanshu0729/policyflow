<?php

namespace App\Services\Pricing;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\SumInsured;
use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculationService
{

    public function calculate(
        string|PlanCode $planCode,
        string|Carbon $tripStart,
        string|Carbon $tripEnd,
        string|Carbon $dob,
        string|Destination $destination,
        int|string $sumInsured
    ): array {
        $plan = $planCode instanceof PlanCode ? $planCode : PlanCode::from($planCode);
        $dest = $destination instanceof Destination ? $destination : Destination::from($destination);

        $startDate = $tripStart instanceof Carbon ? $tripStart->copy()->startOfDay() : Carbon::parse($tripStart)->startOfDay();
        $endDate = $tripEnd instanceof Carbon ? $tripEnd->copy()->startOfDay() : Carbon::parse($tripEnd)->startOfDay();
        $birthDate = $dob instanceof Carbon ? $dob->copy()->startOfDay() : Carbon::parse($dob)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException("trip_end ({$endDate->toDateString()}) cannot be before trip_start ({$startDate->toDateString()}).");
        }

        if ($birthDate->gt($startDate)) {
            throw new InvalidArgumentException("date_of_birth cannot be after trip_start.");
        }

        $travelDays = (int) $startDate->diffInDays($endDate) + 1;
        if ($travelDays < 1) {
            $travelDays = 1;
        }

        $ageAtTripStart = (int) $birthDate->diffInYears($startDate);
        if ($ageAtTripStart < 0) {
            $ageAtTripStart = 0;
        }

        $baseDailyRate = $plan->baseDailyRate();
        $ageMultiplier = $this->getAgeMultiplier($ageAtTripStart);
        $destMultiplier = $dest->multiplier();
        $coverageMultiplier = SumInsured::getMultiplier($sumInsured);

        $step1 = bcmul($baseDailyRate, (string) $travelDays, 6);
        $step2 = bcmul($step1, $ageMultiplier, 6);
        $step3 = bcmul($step2, $destMultiplier, 6);
        $step4 = bcmul($step3, $coverageMultiplier, 6);

        $finalPremium = number_format((float) $step4, 2, '.', '');

        $snapshot = [
            'plan_code' => $plan->value,
            'base_daily_rate' => $baseDailyRate,
            'travel_days' => $travelDays,
            'trip_start' => $startDate->toDateString(),
            'trip_end' => $endDate->toDateString(),
            'date_of_birth' => $birthDate->toDateString(),
            'age_at_trip_start' => $ageAtTripStart,
            'age_multiplier' => $ageMultiplier,
            'destination' => $dest->value,
            'destination_multiplier' => $destMultiplier,
            'sum_insured' => (int) $sumInsured,
            'coverage_multiplier' => $coverageMultiplier,
            'formula' => 'base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier',
            'calculated_at' => Carbon::now()->toIso8601String(),
        ];

        return [
            'premium_amount' => $finalPremium,
            'snapshot' => $snapshot,
        ];
    }

    public function getAgeMultiplier(int $age): string
    {
        if ($age <= 17) {
            return '1.10';
        }

        if ($age <= 60) {
            return '1.00';
        }

        if ($age <= 70) {
            return '1.50';
        }

        return '2.00';
    }
}
