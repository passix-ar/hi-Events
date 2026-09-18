<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantToolRegistry;
use HiEvents\Assistant\Domain\Tools\CreateSeatingSectionTool;
use HiEvents\Assistant\Domain\Tools\ReorderSeatingSectionsTool;
use HiEvents\Assistant\Domain\Tools\SetCheckoutSettingsTool;
use HiEvents\Assistant\Domain\Tools\SetEventLocationTool;
use HiEvents\Assistant\Domain\Tools\SetEventThemeTool;
use HiEvents\Assistant\Domain\Tools\SetOfflinePaymentTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * Hostile or sloppy arguments the model may send: every tool must answer with
 * a JSON error the model can act on, never an exception, and never touch
 * another tenant. Also: every registered tool must be constructible.
 */
class AssistantEdgeCasesTest extends TestCase
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

    private function runTool(string $class, mixed ...$arguments): array
    {
        /** @var Tool $tool */
        $tool = $this->app->make($class, ['context' => $this->context]);
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_registered_tool_builds_and_has_a_unique_name(): void
    {
        $tools = $this->app->make(AssistantToolRegistry::class)->forContext($this->context);
        $names = array_map(static fn(Tool $t): string => $t->name(), $tools);

        $this->assertSame(AssistantToolRegistry::toolNames(), $names);
        $this->assertSame(count($names), count(array_unique($names)));
        foreach ($tools as $tool) {
            $this->assertNotSame('', $tool->description(), $tool->name() . ' has no description');
        }
    }

    public function test_garbage_arguments_become_json_errors_not_exceptions(): void
    {
        $event = $this->mine->event->id;

        $cases = [
            [SetEventThemeTool::class, ['event_id' => $event, 'accent' => "<script>alert(1)</script>"]],
            [SetEventThemeTool::class, ['event_id' => 0, 'accent' => 'rosa']],
            [SetOfflinePaymentTool::class, ['event_id' => $event, 'enabled' => true, 'instructions' => str_repeat('x', 5000)]],
            [SetCheckoutSettingsTool::class, ['event_id' => $event, 'support_email' => 'not-an-email']],
            [SetCheckoutSettingsTool::class, ['event_id' => $event, 'reservation_minutes' => 999]],
            [SetEventLocationTool::class, ['event_id' => $event, 'country' => 'Argentina', 'address_line_1' => 'x', 'city' => 'y', 'postcode' => 'z']],
            [SetEventLocationTool::class, ['event_id' => $event, 'maps_url' => 'javascript:alert(1)', 'address_line_1' => 'x', 'city' => 'y', 'postcode' => 'z']],
            [CreateSeatingSectionTool::class, ['event_id' => $event, 'name' => '', 'ticket_id' => 1, 'rows' => 1, 'seats_per_row' => 1]],
            [CreateSeatingSectionTool::class, ['event_id' => $event, 'name' => 'X', 'ticket_id' => 1, 'rows' => 101, 'seats_per_row' => 1]],
            [ReorderSeatingSectionsTool::class, ['event_id' => $event, 'rows' => []]],
            [ReorderSeatingSectionsTool::class, ['event_id' => $event, 'rows' => [['a']]]],
        ];

        foreach ($cases as [$class, $args]) {
            $result = $this->runTool($class, ...$args);
            $this->assertArrayHasKey('error', $result, $class . ' with ' . json_encode($args));
            $this->assertNotSame('tool_failed', $result['error'], $class . ' leaked an unexpected failure: ' . json_encode($result));
        }
    }

    public function test_tenant_isolation_holds_for_every_event_scoped_write(): void
    {
        $foreign = $this->theirs->event->id;

        $attempts = [
            [SetEventThemeTool::class, ['event_id' => $foreign, 'accent' => 'rosa', 'confirm' => true]],
            [SetOfflinePaymentTool::class, ['event_id' => $foreign, 'enabled' => true, 'instructions' => 'x', 'confirm' => true]],
            [SetCheckoutSettingsTool::class, ['event_id' => $foreign, 'support_email' => 'a@b.com', 'confirm' => true]],
            [SetEventLocationTool::class, ['event_id' => $foreign, 'online' => true, 'confirm' => true]],
            [ReorderSeatingSectionsTool::class, ['event_id' => $foreign, 'rows' => [[1]], 'confirm' => true]],
        ];

        foreach ($attempts as [$class, $args]) {
            $this->assertSame('event_not_found', $this->runTool($class, ...$args)['error'], $class);
        }
    }
}
