<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Event;

use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\PromoCode;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Event\DuplicateEventService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promo codes reference products through a JSON column, not a relation, so a code can keep
 * pointing at a product that was soft-deleted afterwards. Duplication used to look those ids
 * up in the old→new product map without a guard (a 500 on the whole duplicate), and only ran
 * at all when the products were duplicated too. Exercised against the database because the
 * orphan only exists once a real product is deleted underneath a stored code.
 */
class DuplicateEventPromoCodesTest extends TestCase
{
    use DatabaseTransactions;

    private Event $event;

    private Product $generalTicket;

    private Product $deletedTicket;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an event looks the category's default cover up on the public disk.
        Storage::fake(config('filesystems.public'));

        // Event's `creating` hook resolves user_id from the authenticated user.
        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Org Duplicados',
            'email' => 'duplicados@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->event = Event::create([
            'title' => 'Evento original',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]);

        EventSetting::create([
            'event_id' => $this->event->id,
            'order_timeout_in_minutes' => 15,
            'attendee_details_collection_method' => 'PER_TICKET',
        ]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $this->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $this->generalTicket = $this->createTicket($category, 'General');
        $this->deletedTicket = $this->createTicket($category, 'Early bird');

        $this->createPromoCode('TODOS', []);
        $this->createPromoCode('GENERAL', [$this->generalTicket->id, $this->deletedTicket->id]);
        $this->createPromoCode('EARLYBIRD', [$this->deletedTicket->id]);

        $this->deletedTicket->delete();
    }

    public function test_promo_codes_are_copied_unrestricted_when_products_are_not_duplicated(): void
    {
        $copy = $this->duplicate(duplicateProducts: false);

        $codes = $this->promoCodesOf($copy->getId());

        $this->assertSame(['EARLYBIRD', 'GENERAL', 'TODOS'], array_keys($codes));
        $this->assertSame([[], [], []], array_values($codes));
    }

    public function test_promo_codes_follow_the_cloned_products_and_drop_deleted_ones(): void
    {
        $copy = $this->duplicate(duplicateProducts: true);

        $codes = $this->promoCodesOf($copy->getId());
        $newGeneralTicket = Product::query()
            ->where('event_id', $copy->getId())
            ->where('title', 'General')
            ->sole();

        $this->assertSame(['GENERAL', 'TODOS'], array_keys($codes));
        $this->assertSame([], $codes['TODOS']);
        $this->assertSame([$newGeneralTicket->id], $codes['GENERAL']);
    }

    public function test_the_copy_keeps_the_promo_code_attributes(): void
    {
        $copy = $this->duplicate(duplicateProducts: true);

        $general = PromoCode::query()
            ->where('event_id', $copy->getId())
            ->where('code', 'GENERAL')
            ->sole();

        $this->assertSame('PERCENTAGE', $general->discount_type);
        $this->assertSame(10.0, $general->discount);
        $this->assertSame(50, $general->max_allowed_usages);
    }

    private function duplicate(bool $duplicateProducts): \HiEvents\DomainObjects\EventDomainObject
    {
        return $this->app->make(DuplicateEventService::class)->duplicateEvent(
            eventId: (string) $this->event->id,
            accountId: (string) $this->event->account_id,
            title: 'Evento copiado',
            startDate: now()->addMonths(2)->toDateTimeString(),
            duplicateProducts: $duplicateProducts,
            duplicateQuestions: false,
            duplicateSettings: true,
            duplicatePromoCodes: true,
            duplicateCapacityAssignments: false,
            duplicateCheckInLists: false,
            duplicateEventCoverImage: false,
            duplicateTicketLogo: false,
            duplicateWebhooks: false,
            duplicateAffiliates: false,
        );
    }

    /** @return array<string, int[]> code => applicable product ids, sorted by code */
    private function promoCodesOf(int $eventId): array
    {
        return PromoCode::query()
            ->where('event_id', $eventId)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (PromoCode $code) => [$code->code => $code->applicable_product_ids ?? []])
            ->all();
    }

    private function createTicket(ProductCategory $category, string $title): Product
    {
        $product = Product::create([
            'title' => $title,
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'price' => 1000,
            'initial_quantity_available' => 100,
            'quantity_available' => 100,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'order' => 0,
        ]);

        return $product;
    }

    private function createPromoCode(string $code, array $applicableProductIds): void
    {
        PromoCode::create([
            'event_id' => $this->event->id,
            'code' => $code,
            'discount_type' => 'PERCENTAGE',
            'discount' => 10,
            'applicable_product_ids' => $applicableProductIds,
            'max_allowed_usages' => 50,
        ]);
    }
}
