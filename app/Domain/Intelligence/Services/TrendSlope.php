<?php

namespace App\Domain\Intelligence\Services;

/**
 * Deterministic trend slope for BI reports (02-119): ordinary least-squares
 * over the daily value series with a min-sample gate. The method, the
 * sample size and the gate are always part of the result — a trend is
 * never claimed from too little evidence (honesty rule).
 */
class TrendSlope
{
    /** Minimum observation days (days with real data) before claiming a direction. */
    public const MIN_SAMPLE = 7;

    /** Slopes within ±this are reported as flat. */
    public const EPSILON = 0.000001;

    /**
     * @param  list<float>  $values  one value per point (calendar days, zeros included)
     * @param  int  $sample  observation days — points that actually carry data
     * @return array{
     *   status: string,
     *   method: string,
     *   min_sample: int,
     *   sample: int,
     *   points: int,
     *   slope: float|null,
     *   direction: string|null,
     *   reason: string|null,
     * }
     */
    public function of(array $values, int $sample): array
    {
        $n = count($values);

        $base = [
            'method' => 'least_squares',
            'min_sample' => self::MIN_SAMPLE,
            'sample' => $sample,
            'points' => $n,
        ];

        if ($sample < self::MIN_SAMPLE) {
            return $base + [
                'status' => 'insufficient',
                'slope' => null,
                'direction' => null,
                'reason' => sprintf(
                    'Not enough data: %d observation day(s) of %d required.',
                    $sample,
                    self::MIN_SAMPLE,
                ),
            ];
        }

        $sumX = $sumY = $sumXY = $sumX2 = 0.0;
        foreach ($values as $index => $value) {
            $x = (float) $index;
            $y = (float) $value;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        $slope = $denominator == 0.0 ? 0.0 : ($n * $sumXY - $sumX * $sumY) / $denominator;

        $direction = $slope > self::EPSILON
            ? 'up'
            : ($slope < -self::EPSILON ? 'down' : 'flat');

        return $base + [
            'status' => 'ok',
            'slope' => round($slope, 4),
            'direction' => $direction,
            'reason' => null,
        ];
    }
}
