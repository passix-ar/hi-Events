<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\Assistant\Domain\Tools\AttachFlyerToEventTool;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\Image;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Media\Image as PrismImage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * The flyer flow: an image dropped into the chat is parked per account, read by
 * the model on that turn only, and can become the cover of a draft event - and
 * never anything belonging to another account.
 */
class AssistantFlyerTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('assistant.enabled', true);
        Storage::fake((string)config('filesystems.private'));
        Storage::fake((string)config('filesystems.public'));

        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');

        $login = $this->postJson('/auth/login', [
            'email' => $this->mine->user->email,
            'password' => $this->mine->password,
        ]);
        $this->token = $login->headers->get('X-Auth-Token');
        $this->app['auth']->forgetGuards();
    }

    /**
     * A real PNG of the given size, built without GD (absent in the dev
     * container): the dimensions rule reads it with getimagesize(), which only
     * needs the file to be a well-formed image.
     */
    private function flyer(int $width = 1080, int $height = 1350): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'flyer-') . '.png';
        file_put_contents($path, $this->png($width, $height));

        return new UploadedFile($path, 'flyer.png', 'image/png', null, true);
    }

    private function png(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $row = "\0" . str_repeat("\xd6\xff\x3d", $width); // filter byte + lime pixels
        $raw = str_repeat($row, $height);

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 6))
            . $chunk('IEND', '');
    }

    private function upload(UploadedFile $file, ?int $organizerId = null)
    {
        return $this->post(
            '/organizers/' . ($organizerId ?? $this->mine->organizer->id) . '/assistant/attachments',
            ['image' => $file],
            ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
        );
    }

    // ── upload endpoint ───────────────────────────────────────────────────

    public function test_a_flyer_can_be_uploaded_and_gets_an_id(): void
    {
        $response = $this->upload($this->flyer());

        $response->assertStatus(ResponseCodes::HTTP_CREATED)
            ->assertJsonStructure(['data' => ['id', 'name', 'size']]);

        $id = $response->json('data.id');
        $store = $this->app->make(AssistantAttachmentStore::class);

        $this->assertNotNull($store->find($id, $this->mine->account->id));
    }

    public function test_the_upload_is_rejected_for_another_accounts_organizer(): void
    {
        $this->upload($this->flyer(), $this->theirs->organizer->id)
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
    }

    public function test_non_images_and_tiny_images_are_rejected(): void
    {
        $this->upload(UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);

        $this->upload($this->flyer(100, 100))
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ── the store ─────────────────────────────────────────────────────────

    public function test_an_attachment_cannot_be_read_from_another_account(): void
    {
        $id = $this->upload($this->flyer())->json('data.id');
        $store = $this->app->make(AssistantAttachmentStore::class);

        $this->assertNotNull($store->find($id, $this->mine->account->id));
        $this->assertNull($store->find($id, $this->theirs->account->id), 'the account folder is part of the path');
        $this->assertNull($store->find('not-a-uuid', $this->mine->account->id));
    }

    // ── the model sees the image, once ────────────────────────────────────

    public function test_the_image_rides_on_the_last_user_message_only(): void
    {
        $id = $this->upload($this->flyer())->json('data.id');
        $fake = Prism::fake([TextResponseFake::make()->withText('Veo un flyer.')]);

        $this->postJson("/organizers/{$this->mine->organizer->id}/assistant/chat", [
            'messages' => [
                ['role' => 'user', 'content' => 'hola'],
                ['role' => 'assistant', 'content' => 'hola, ¿qué armamos?'],
                ['role' => 'user', 'content' => 'armá este evento'],
            ],
            'context' => ['attachment_id' => $id],
        ], ['Authorization' => 'Bearer ' . $this->token])->assertOk();

        $fake->assertRequest(function (array $requests): void {
            $messages = $requests[0]->messages();
            $first = $messages[0];
            $last = $messages[2];

            $this->assertInstanceOf(UserMessage::class, $first);
            $this->assertCount(0, $first->images(), 'earlier turns were answered without the image');
            $this->assertInstanceOf(UserMessage::class, $last);
            $this->assertCount(1, $last->images());
            $this->assertInstanceOf(PrismImage::class, $last->images()[0]);
        });
    }

    public function test_a_foreign_attachment_id_is_silently_dropped(): void
    {
        // Uploaded by tenant B, presented by tenant A.
        $theirLogin = $this->postJson('/auth/login', [
            'email' => $this->theirs->user->email,
            'password' => $this->theirs->password,
        ]);
        $this->app['auth']->forgetGuards();
        $theirId = $this->post(
            "/organizers/{$this->theirs->organizer->id}/assistant/attachments",
            ['image' => $this->flyer()],
            ['Authorization' => 'Bearer ' . $theirLogin->headers->get('X-Auth-Token'), 'Accept' => 'application/json'],
        )->json('data.id');
        $this->app['auth']->forgetGuards();

        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        $this->postJson("/organizers/{$this->mine->organizer->id}/assistant/chat", [
            'messages' => [['role' => 'user', 'content' => 'armá este evento']],
            'context' => ['attachment_id' => $theirId],
        ], ['Authorization' => 'Bearer ' . $this->token])->assertOk();

        $fake->assertRequest(function (array $requests): void {
            $this->assertCount(0, $requests[0]->messages()[0]->images(), 'another account\'s image never reaches the model');
        });
    }

    // ── the tool ──────────────────────────────────────────────────────────

    private function runTool(Tool $tool, mixed ...$arguments): array
    {
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function toolWithAttachment(?string $attachmentId): AttachFlyerToEventTool
    {
        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));

        $context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user,
            accountId: $this->mine->account->id,
            organizerId: $this->mine->organizer->id,
            attachmentId: $attachmentId,
        );

        return $this->app->make(AttachFlyerToEventTool::class, ['context' => $context]);
    }

    private function draftEventId(): int
    {
        $this->actingAs($this->mine->user, 'api');
        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user, accountId: $this->mine->account->id, organizerId: $this->mine->organizer->id,
        );
        $tool = $this->app->make(CreateDraftEventTool::class, ['context' => $context]);

        return $this->runTool($tool, title: 'Borrador Con Flyer', start_date: '2026-12-20 22:00', confirm: true)['event']['id'];
    }

    public function test_without_an_attachment_the_tool_explains_instead_of_failing(): void
    {
        $result = $this->runTool($this->toolWithAttachment(null), event_id: $this->mine->event->id, confirm: true);

        $this->assertSame('no_attachment', $result['error']);
    }

    public function test_the_flyer_is_refused_on_a_published_event(): void
    {
        $id = $this->upload($this->flyer())->json('data.id');

        $result = $this->runTool($this->toolWithAttachment($id), event_id: $this->mine->event->id, confirm: true);

        $this->assertSame('event_not_draft', $result['error']);
        $this->assertSame(0, Image::where('entity_id', $this->mine->event->id)->where('type', ImageType::EVENT_COVER->name)->count());
    }

    public function test_the_flyer_becomes_the_cover_of_a_draft_after_confirmation(): void
    {
        $eventId = $this->draftEventId();
        $id = $this->upload($this->flyer())->json('data.id');
        $tool = $this->toolWithAttachment($id);

        $preview = $this->runTool($tool, event_id: $eventId);
        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame(0, Image::where('entity_id', $eventId)->where('type', ImageType::EVENT_COVER->name)->count());

        $result = $this->runTool($tool, event_id: $eventId, confirm: true);

        $this->assertSame('created', $result['status']);
        $cover = Image::where('entity_id', $eventId)->where('type', ImageType::EVENT_COVER->name)->first();
        $this->assertNotNull($cover);
        $this->assertSame($this->mine->account->id, $cover->account_id);
    }

    public function test_the_flyer_never_lands_on_another_tenants_event(): void
    {
        $id = $this->upload($this->flyer())->json('data.id');

        $result = $this->runTool($this->toolWithAttachment($id), event_id: $this->theirs->event->id, confirm: true);

        $this->assertSame(['error' => 'event_not_found'], $result);
        $this->assertSame(0, Image::where('entity_id', $this->theirs->event->id)->count());
    }
}
