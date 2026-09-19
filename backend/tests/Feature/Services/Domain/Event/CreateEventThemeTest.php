<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Event;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Domain\Organizer\CreateDefaultOrganizerSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Passix is a dark platform. An organizer's theme (ColorTheme) carries the
 * homepage_*_color keys and none of mode/accent/background, so a new event used
 * to fall back to the upstream light theme (#f5f3ff) - the one thing on the
 * page that did not look like Passix.
 */
class CreateEventThemeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_new_event_opens_in_the_passix_dark_theme(): void
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1, 'name' => 'Default', 'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);
        Storage::fake((string)config('filesystems.public'));

        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $account = $user->accounts()->first();
        $this->actingAs($user, 'api');

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Org Oscura',
            'email' => 'oscura@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
        app(CreateDefaultOrganizerSettingsService::class)->createOrganizerSettings(
            OrganizerDomainObject::hydrateFromModel($organizer),
        );

        $event = app(CreateEventHandler::class)->handle(CreateEventDTO::fromArray([
            'title' => 'Evento Nuevo',
            'organizer_id' => $organizer->id,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'start_date' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]));

        $theme = EventSetting::where('event_id', $event->getId())->first()->homepage_theme_settings;

        $this->assertSame('dark', $theme['mode']);
        $this->assertSame('#0b0b0e', $theme['background']);
        $this->assertSame('#d6ff3d', $theme['accent']);
    }
}
