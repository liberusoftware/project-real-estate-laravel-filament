<?php

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Liberu\RealEstate\Viewings\Application\CreateViewing;
use Liberu\RealEstate\Viewings\Domain\ViewingStatus;
use Liberu\RealEstate\Viewings\Models\Viewing;
use Livewire\Livewire;

it('books an available viewing and clears the slot when the date changes', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $startsAt = now()->next('Monday')->setTime(10, 0);
    app(CreateViewing::class)->handle(10, $user->id, ['subject' => 'Existing appointment', 'starts_at' => $startsAt]);
    $this->actingAs($user);

    Livewire::test('module-real-estate-viewings::viewing-booking')
        ->set('date', $startsAt->toDateString())
        ->set('subject', 'New appointment')
        ->set('selectedSlot', $startsAt->toIso8601String())
        ->call('book')
        ->assertHasErrors('selectedSlot')
        ->assertSet('booked', false)
        ->set('selectedSlot', $startsAt->copy()->addHour()->toIso8601String())
        ->call('book')
        ->assertHasNoErrors()
        ->assertSee('Viewing requested.')
        ->assertSet('selectedSlot', null)
        ->set('date', $startsAt->copy()->addDay()->toDateString())
        ->assertSet('booked', false);

    $viewing = Viewing::query()->where('subject', 'New appointment')->sole();
    expect($viewing->team_id)->toEqual(10)
        ->and($viewing->created_by)->toEqual($user->id)
        ->and($viewing->starts_at->toIso8601String())->toBe($startsAt->copy()->addHour()->toIso8601String())
        ->and($viewing->ends_at->toIso8601String())->toBe($startsAt->copy()->addHours(2)->toIso8601String());
});

it('lists and advances viewings only in the active team', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateViewing::class);
    $first = $create->handle(10, $user->id, ['subject' => 'First appointment', 'starts_at' => now()->addDays(2)]);
    $second = $create->handle(10, $user->id, ['subject' => 'Second appointment', 'starts_at' => now()->addDays(3)]);
    $outside = $create->handle(20, $user->id, ['subject' => 'Other appointment', 'starts_at' => now()->addDays(2)]);
    $this->actingAs($user);

    Livewire::test('module-real-estate-viewings::viewing-list')
        ->assertSee('First appointment')
        ->assertSee('Second appointment')
        ->assertDontSee('Other appointment')
        ->set('search', 'First')
        ->assertDontSee('Second appointment')
        ->call('confirmViewing', $outside->id)
        ->assertSet('error', fn (?string $error): bool => filled($error))
        ->call('confirmViewing', $first->id)
        ->assertSet('error', null)
        ->assertSee('First appointment (confirmed)')
        ->call('markNoShow', $first->id)
        ->assertSet('error', null)
        ->set('search', '')
        ->call('cancelViewing', $second->id)
        ->assertSee('Second appointment (cancelled)');

    expect($first->fresh()->status)->toBe(ViewingStatus::NoShow)
        ->and($second->fresh()->status)->toBe(ViewingStatus::Cancelled)
        ->and($outside->fresh()->status)->toBe(ViewingStatus::Requested);
});

it('rejects a booking when another viewing claims the slot after availability is checked', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $startsAt = now()->next('Monday')->setTime(10, 0);
    $this->actingAs($user);
    $booking = Livewire::test('module-real-estate-viewings::viewing-booking')
        ->set('date', $startsAt->toDateString())
        ->set('subject', 'New appointment')
        ->set('selectedSlot', $startsAt->toIso8601String());
    $claimed = false;

    DB::listen(function (QueryExecuted $query) use (&$claimed, $startsAt, $user): void {
        if ($claimed || ! str_starts_with($query->sql, 'select "starts_at", "ends_at" from "real_estate_viewings"')) {
            return;
        }

        $claimed = true;
        app(CreateViewing::class)->handle(10, $user->id, [
            'subject' => 'Concurrent appointment',
            'starts_at' => $startsAt,
        ]);
    });

    $booking->call('book')
        ->assertHasErrors('starts_at')
        ->assertSet('booked', false);

    expect($claimed)->toBeTrue()
        ->and(Viewing::query()->sole()->subject)->toBe('Concurrent appointment');
});

it('renders the viewing components and rejects actions without a team context', function (): void {
    $future = now()->next('Monday')->setTime(10, 0);

    Livewire::test('module-real-estate-viewings::viewing-list')
        ->assertSee('No viewings found.')
        ->call('confirmViewing', 1)
        ->assertSet('error', 'A team context is required.');

    Livewire::test('module-real-estate-viewings::viewing-booking')
        ->assertSee('No viewing slots are available for this date.')
        ->set('date', $future->toDateString())
        ->set('subject', 'Appointment')
        ->set('selectedSlot', $future->toIso8601String())
        ->call('book')
        ->assertSet('error', 'A team context is required.')
        ->assertSet('booked', false);

    expect(Viewing::query()->exists())->toBeFalse();
});
