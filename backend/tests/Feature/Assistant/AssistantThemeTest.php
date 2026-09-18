<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\SetEventThemeTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * "Ponela rosa": colours by name or hex, background derived from the accent
 * when not given, MODIFICAR on a published event.
 */
class AssistantThemeTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private AssistantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string)config('filesystems.public'));
        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');
        $this->actingAs($this->mine->user, 'api');

        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $this->context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user, accountId: $this->mine->account->id, organizerId: $this->mine->organizer->id,
        );
    }

    private function tool(): Tool
    {
        return $this->app->make(SetEventThemeTool::class, ['context' => $this->context]);
    }

    private function runTool(mixed ...$arguments): array
    {
        $output = $this->tool()->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function theme(int $eventId): array
    {
        return (array)EventSetting::where('event_id', $eventId)->first()->homepage_theme_settings;
    }

    private function draft(): int
    {
        $output = $this->app->make(CreateDraftEventTool::class, ['context' => $this->context])
            ->handle(title: 'Rock del Puerto', start_date: '2026-11-13 20:00', confirm: true);

        return json_decode((string)$output, true, flags: JSON_THROW_ON_ERROR)['event']['id'];
    }

    public function test_preview_changes_nothing_and_shows_current_and_new(): void
    {
        $eventId = $this->draft();
        $before = $this->theme($eventId);

        $preview = $this->runTool(event_id: $eventId, accent: 'rosa');

        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame('#ff6fb5', $preview['would_apply']['accent']);
        $this->assertSame($before['accent'] ?? null, $preview['current']['accent']);
        $this->assertSame($before, $this->theme($eventId));
    }

    public function test_a_named_colour_gets_a_background_derived_from_its_hue(): void
    {
        $eventId = $this->draft();

        $done = $this->runTool(event_id: $eventId, accent: 'rosa', confirm: true);

        $this->assertSame('applied', $done['status']);
        $theme = $this->theme($eventId);
        $this->assertSame('#ff6fb5', $theme['accent']);
        $this->assertSame('dark', $theme['mode']);
        $this->assertNotSame('#0b0b0e', $theme['background'], 'the background follows the pink hue');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $theme['background']);
    }

    public function test_hex_light_mode_and_shade_words(): void
    {
        $eventId = $this->draft();

        $this->runTool(event_id: $eventId, accent: '#123ABC', background: 'azul claro', mode: 'light', confirm: true);
        $theme = $this->theme($eventId);

        $this->assertSame('#123abc', $theme['accent']);
        $this->assertSame('light', $theme['mode']);
        $this->assertNotSame('#4dabf7', $theme['background'], '"claro" lightens the named blue');

        $invalid = $this->runTool(event_id: $eventId, accent: 'chartreuse-ish');
        $this->assertSame('invalid_arguments', $invalid['error']);

        $nothing = $this->runTool(event_id: $eventId);
        $this->assertSame('invalid_arguments', $nothing['error']);
    }

    public function test_flyer_background_needs_a_cover(): void
    {
        $eventId = $this->draft();

        $result = $this->runTool(event_id: $eventId, page_background: 'flyer', confirm: true);

        $this->assertSame('no_cover', $result['error']);
    }

    public function test_a_published_event_needs_the_word_modificar(): void
    {
        $eventId = $this->draft();
        Event::withoutEvents(fn() => Event::where('id', $eventId)->update(['status' => 'LIVE']));

        $refused = $this->runTool(event_id: $eventId, accent: 'verde', confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);

        $done = $this->runTool(event_id: $eventId, accent: 'verde', confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('applied', $done['status']);
        $this->assertSame('#4ade80', $this->theme($eventId)['accent']);
    }

    public function test_cannot_theme_another_tenants_event(): void
    {
        $result = $this->runTool(event_id: $this->theirs->event->id, accent: 'rosa', confirm: true);

        $this->assertSame('event_not_found', $result['error']);
    }
}
