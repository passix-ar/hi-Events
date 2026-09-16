<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreatePromoCodeTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\PromoCode;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantPromoCodeTest extends TestCase
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
        return $this->app->make(CreatePromoCodeTool::class, ['context' => $this->context]);
    }

    private function runTool(Tool $tool, mixed ...$arguments): array
    {
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function validArgs(array $overrides = []): array
    {
        return array_merge([
            'event_id' => $this->mine->event->id,
            'code' => 'early15',
            'discount_type' => 'PERCENTAGE',
            'discount' => 15,
            'max_uses' => 50,
            'expiry_date' => now()->addDays(30)->format('Y-m-d'),
        ], $overrides);
    }

    public function test_preview_writes_nothing(): void
    {
        $before = PromoCode::count();

        $result = $this->runTool($this->tool(), ...$this->validArgs());

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('EARLY15', $result['would_create']['code']);
        $this->assertSame($before, PromoCode::count());
    }

    public function test_confirmed_creation_is_persisted_uppercase_on_a_live_event(): void
    {
        $this->assertSame('LIVE', $this->mine->event->status);

        $result = $this->runTool($this->tool(), ...$this->validArgs(['confirm' => true]));

        $this->assertSame('created', $result['status'], json_encode($result));
        $this->assertSame('EARLY15', $result['promo_code']['code']);
        $this->assertStringContainsString('checkout', $result['next_steps']);

        $this->assertDatabaseHas('promo_codes', [
            'event_id' => $this->mine->event->id,
            'code' => 'EARLY15',
            'discount_type' => 'PERCENTAGE',
            'discount' => 15,
            'max_allowed_usages' => 50,
        ]);

        $stored = PromoCode::where('code', 'EARLY15')->where('event_id', $this->mine->event->id)->firstOrFail();
        $this->assertNotNull($stored->expiry_date);
        $this->assertTrue($stored->expiry_date->isFuture());
    }

    public function test_percentage_above_50_is_rejected(): void
    {
        $result = $this->runTool($this->tool(), ...$this->validArgs(['discount' => 60, 'confirm' => true]));

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertDatabaseMissing('promo_codes', ['code' => 'EARLY15', 'event_id' => $this->mine->event->id]);
    }

    public function test_fixed_discount_must_be_lower_than_cheapest_price(): void
    {
        $rejected = $this->runTool($this->tool(), ...$this->validArgs([
            'code' => 'MENOS5000', 'discount_type' => 'FIXED', 'discount' => 5000, 'confirm' => true,
        ]));

        $this->assertSame('invalid_arguments', $rejected['error']);
        $this->assertDatabaseMissing('promo_codes', ['code' => 'MENOS5000', 'event_id' => $this->mine->event->id]);

        $accepted = $this->runTool($this->tool(), ...$this->validArgs([
            'code' => 'MENOS1000', 'discount_type' => 'FIXED', 'discount' => 1000, 'confirm' => true,
        ]));

        $this->assertSame('created', $accepted['status'], json_encode($accepted));
        $this->assertDatabaseHas('promo_codes', [
            'event_id' => $this->mine->event->id,
            'code' => 'MENOS1000',
            'discount_type' => 'FIXED',
            'discount' => 1000,
        ]);
    }

    public function test_missing_max_uses_is_rejected(): void
    {
        $args = $this->validArgs(['confirm' => true]);
        unset($args['max_uses']);

        $result = $this->runTool($this->tool(), ...$args);

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertDatabaseMissing('promo_codes', ['code' => 'EARLY15', 'event_id' => $this->mine->event->id]);
    }

    public function test_expiry_in_the_past_is_rejected(): void
    {
        $result = $this->runTool($this->tool(), ...$this->validArgs([
            'expiry_date' => now()->subDay()->format('Y-m-d'), 'confirm' => true,
        ]));

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertStringContainsString('future', $result['details']);
        $this->assertDatabaseMissing('promo_codes', ['code' => 'EARLY15', 'event_id' => $this->mine->event->id]);
    }

    public function test_expiry_too_far_ahead_is_rejected(): void
    {
        $result = $this->runTool($this->tool(), ...$this->validArgs([
            'expiry_date' => now()->addDays(200)->format('Y-m-d'), 'confirm' => true,
        ]));

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertStringContainsString('180', $result['details']);
        $this->assertDatabaseMissing('promo_codes', ['code' => 'EARLY15', 'event_id' => $this->mine->event->id]);
    }

    public function test_duplicate_code_returns_already_exists_without_writing(): void
    {
        $first = $this->runTool($this->tool(), ...$this->validArgs(['confirm' => true]));
        $this->assertSame('created', $first['status']);
        $count = PromoCode::count();

        $second = $this->runTool($this->tool(), ...$this->validArgs(['code' => 'Early15', 'confirm' => true]));

        $this->assertSame('already_exists', $second['status']);
        $this->assertSame('EARLY15', $second['promo_code']['code']);
        $this->assertSame($count, PromoCode::count());
    }

    public function test_cross_tenant_event_is_not_found(): void
    {
        $before = PromoCode::count();

        $result = $this->runTool($this->tool(), ...$this->validArgs([
            'event_id' => $this->theirs->event->id, 'confirm' => true,
        ]));

        $this->assertSame(['error' => 'event_not_found'], $result);
        $this->assertSame($before, PromoCode::count());
    }
}
