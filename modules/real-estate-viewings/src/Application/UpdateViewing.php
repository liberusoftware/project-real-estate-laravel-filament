<?php

declare(strict_types=1);

namespace Liberu\RealEstate\Viewings\Application;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Liberu\RealEstate\Viewings\Domain\ViewingStatus;
use Liberu\RealEstate\Viewings\Models\Viewing;

final class UpdateViewing
{
    public function handle(Viewing $viewing, int|string $teamId, array $attributes): Viewing
    {
        abort_unless((string) $viewing->team_id === (string) $teamId, 404);
        if (array_key_exists('subject', $attributes) && trim((string) $attributes['subject']) === '') {
            throw ValidationException::withMessages(['subject' => 'A viewing subject is required.']);
        }
        $startsAt = isset($attributes['starts_at']) ? CarbonImmutable::parse($attributes['starts_at']) : $viewing->starts_at;
        $changesStart = ! $startsAt->equalTo($viewing->starts_at);
        if ((array_key_exists('starts_at', $attributes) && $attributes['starts_at'] === null) || ($changesStart && now()->gte($startsAt))) {
            throw ValidationException::withMessages(['starts_at' => 'A viewing must start in the future.']);
        }
        if (array_key_exists('property_id', $attributes) && (string) $attributes['property_id'] !== (string) $viewing->property_id) {
            throw ValidationException::withMessages(['property_id' => 'A viewing cannot be moved to another property.']);
        }
        if (filled($attributes['ends_at'] ?? null)) {
            $endsAt = CarbonImmutable::parse($attributes['ends_at'])->setTimezone(config('app.timezone'));
            if ($endsAt->lte($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'The end time must be after the start time.']);
            }
            $attributes['ends_at'] = $endsAt;
        }
        if ($changesStart && ! $viewing->canBeRescheduled()) {
            throw ValidationException::withMessages(['starts_at' => 'This viewing cannot be rescheduled.']);
        }
        if (array_key_exists('status', $attributes)) {
            $status = ViewingStatus::tryFrom((string) $attributes['status']);
            if ($status === null || ($status !== $viewing->status && ! $viewing->canTransitionTo($status))) {
                throw ValidationException::withMessages(['status' => 'That viewing status transition is not allowed.']);
            }
        }
        if (! $changesStart) {
            unset($attributes['starts_at']);
        }
        $viewing->fill($attributes);
        $viewing->save();

        return $viewing->fresh();
    }
}
