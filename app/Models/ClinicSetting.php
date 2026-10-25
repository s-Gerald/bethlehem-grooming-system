<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ClinicSetting extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    public const SINGLETON_ID = 1;

    public const MIN_GROOMERS_ON_DUTY = 0;

    public const MAX_GROOMERS_ON_DUTY = 5;

    public const DEFAULT_CLINIC_OPEN_TIME = '08:00:00';

    public const DEFAULT_CLINIC_CLOSE_TIME = '17:00:00';

    public const DEFAULT_GROOMING_OPEN_TIME = '08:00:00';

    public const DEFAULT_GROOMING_CLOSE_TIME = '17:00:00';

    protected $fillable = [
        'clinic_open_time',
        'clinic_close_time',
        'grooming_open_time',
        'grooming_close_time',
    ];

    protected $casts = [
        'groomers_on_duty' => 'integer',
    ];

    public static function current(bool $lockForUpdate = false): self
    {
        $query = static::query()->select('clinic_settings.*')->selectSub(
            PetGroomer::selectRaw('count(*)')->where('on_duty', true), 'groomers_on_duty',
        )->whereKey(self::SINGLETON_ID);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $settings = $query->first();
        if ($settings) return $settings;

        static::query()->forceCreate([
            'id' => self::SINGLETON_ID,
            ...self::availabilityDefaults(),
        ]);
        return $query->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    public static function availabilityDefaults(): array
    {
        return [
            'clinic_open_time' => self::DEFAULT_CLINIC_OPEN_TIME,
            'clinic_close_time' => self::DEFAULT_CLINIC_CLOSE_TIME,
            'grooming_open_time' => self::DEFAULT_GROOMING_OPEN_TIME,
            'grooming_close_time' => self::DEFAULT_GROOMING_CLOSE_TIME,
        ];
    }

    /**
     * @return array{
     *     clinic: array<string, string>,
     *     grooming: array<string, string>
     * }
     */
    public function availabilityPayload(): array
    {
        return [
            'clinic' => $this->serviceAvailability('clinic'),
            'grooming' => $this->serviceAvailability('grooming'),
        ];
    }

    /**
     * @return array{
     *     open_time: string,
     *     close_time: string,
     *     operating_hours_label: string
     * }
     */
    public function serviceAvailability(string $service): array
    {
        $this->guardService($service);

        $openTime = $this->configuredTime(
            "{$service}_open_time",
            self::availabilityDefaults()["{$service}_open_time"],
        );
        $closeTime = $this->configuredTime(
            "{$service}_close_time",
            self::availabilityDefaults()["{$service}_close_time"],
        );

        return [
            'open_time' => substr($openTime, 0, 5),
            'close_time' => substr($closeTime, 0, 5),
            'operating_hours_label' => $this->formatTime($openTime).' – '.$this->formatTime($closeTime),
        ];
    }

    public function isWithinOperatingHours(string $service, DateTimeInterface $dateTime): bool
    {
        $availability = $this->serviceAvailability($service);
        $currentTime = $dateTime->format('H:i');

        return $currentTime >= $availability['open_time']
            && $currentTime < $availability['close_time'];
    }

    public function isWindowWithinOperatingHours(
        string $service,
        string $startTime,
        string $endTime,
    ): bool {
        $startTime = substr($startTime, 0, 8);
        $endTime = substr($endTime, 0, 8);

        return collect($this->desiredTimeWindows($service))->contains(
            fn (array $window) => $window['start_time'] === $startTime
                && $window['end_time'] === $endTime,
        );
    }

    /**
     * Build complete one-hour windows within operating hours,
     * excluding the 12:00 PM to 1:00 PM break.
     *
     * @return array<int, array{start_time: string, end_time: string, window_label: string}>
     */
    public function desiredTimeWindows(string $service): array
    {
        $availability = $this->serviceAvailability($service);
        $cursor = Carbon::createFromFormat('H:i', $availability['open_time']);
        $closingTime = Carbon::createFromFormat('H:i', $availability['close_time']);
        $breakStart = Carbon::createFromFormat('H:i', '12:00');
        $breakEnd = Carbon::createFromFormat('H:i', '13:00');
        $windows = [];

        while ($cursor->copy()->addHour()->lessThanOrEqualTo($closingTime)) {
            $windowEnd = $cursor->copy()->addHour();

            if ($cursor->lessThan($breakEnd) && $windowEnd->greaterThan($breakStart)) {
                $cursor = $breakEnd->copy();

                continue;
            }

            $windows[] = [
                'start_time' => $cursor->format('H:i:s'),
                'end_time' => $windowEnd->format('H:i:s'),
                'window_label' => $cursor->format('g:i A').' - '.$windowEnd->format('g:i A'),
            ];
            $cursor = $windowEnd;
        }

        return $windows;
    }

    private function configuredTime(string $attribute, string $default): string
    {
        $value = trim((string) $this->getAttribute($attribute));

        return $value !== '' ? $value : $default;
    }

    private function formatTime(string $value): string
    {
        return Carbon::createFromFormat('H:i', substr($value, 0, 5))->format('g:i A');
    }

    private function guardService(string $service): void
    {
        if (! in_array($service, ['clinic', 'grooming'], true)) {
            throw new InvalidArgumentException("Unsupported availability service [{$service}].");
        }
    }
}
