<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Http\ResponseCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class AssistantStreamTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('assistant.enabled', true);

        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');

        $login = $this->postJson('/auth/login', [
            'email' => $this->mine->user->email,
            'password' => $this->mine->password,
        ]);

        $this->token = $login->headers->get('X-Auth-Token');

        // The login above leaves the user on the cached JWT guard; drop it so each
        // request below authenticates only with the header it actually sends.
        $this->app['auth']->forgetGuards();
    }

    private function stream(int $organizerId, array $body, ?string $token = null): TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this->postJson("/organizers/{$organizerId}/assistant/chat/stream", $body, $headers);
    }

    private function userSays(string $text): array
    {
        return ['messages' => [['role' => 'user', 'content' => $text]]];
    }

    /**
     * Parses our SSE protocol into [['event' => string, 'data' => array], ...].
     *
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function events(string $sse): array
    {
        $events = [];

        foreach (preg_split('/\n\n+/', trim($sse)) as $block) {
            $event = null;
            $data = '';

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data .= substr($line, 6);
                }
            }

            $events[] = ['event' => $event, 'data' => json_decode($data, true, 512, JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    private function eventsOfType(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn(array $e): bool => $e['event'] === $type));
    }

    public function test_requires_authentication(): void
    {
        $this->stream($this->mine->organizer->id, $this->userSays('hola'))
            ->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_cannot_stream_about_another_accounts_organizer(): void
    {
        $fake = Prism::fake();

        $this->stream($this->theirs->organizer->id, $this->userSays('hola'), $this->token)
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);

        $fake->assertCallCount(0);
    }

    public function test_validates_the_message_list_before_opening_the_stream(): void
    {
        Prism::fake();

        $this->stream($this->mine->organizer->id, ['messages' => []], $this->token)
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_streams_deltas_and_ends_with_the_full_reply(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()
                ->withText('hola')
                ->withUsage(new Usage(promptTokens: 1200, completionTokens: 40)),
        ]);

        $response = $this->stream($this->mine->organizer->id, $this->userSays('hola'), $this->token);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);

        $events = $this->events($content);
        $deltas = $this->eventsOfType($events, 'delta');
        $done = $this->eventsOfType($events, 'done');

        $this->assertNotEmpty($deltas);
        $this->assertSame('hola', implode('', array_column(array_column($deltas, 'data'), 'text')));

        $this->assertCount(1, $done);
        $this->assertSame('done', end($events)['event'], 'done is the last event');
        $this->assertSame('hola', $done[0]['data']['reply']);
        $this->assertSame([], $done[0]['data']['tool_calls']);
        $this->assertSame(1200, $done[0]['data']['input_tokens']);
        $this->assertSame(40, $done[0]['data']['output_tokens']);
        $this->assertSame([], $this->eventsOfType($events, 'error'));

        $fake->assertCallCount(1);
        $fake->assertRequest(function (array $requests): void {
            $this->assertStringContainsString('Org A', $requests[0]->messages()[count($requests[0]->messages()) - 1]->content);
            $this->assertStringNotContainsString('Org B', $requests[0]->messages()[count($requests[0]->messages()) - 1]->content);
        });
    }

    public function test_tool_calls_are_announced_as_they_happen_and_listed_at_the_end(): void
    {
        $call = new ToolCall(id: 'toolu_1', name: 'get_ticket_ranking', arguments: ['event_id' => 7]);

        Prism::fake([
            TextResponseFake::make()
                ->withSteps(collect([
                    new Step(
                        text: '',
                        finishReason: FinishReason::ToolCalls,
                        toolCalls: [$call],
                        toolResults: [new ToolResult(toolCallId: 'toolu_1', toolName: 'get_ticket_ranking', args: ['event_id' => 7], result: '{}')],
                        providerToolCalls: [],
                        usage: new Usage(10, 5),
                        meta: new Meta('fake', 'fake'),
                        messages: [],
                        systemPrompts: [],
                    ),
                    new Step(
                        text: 'Tu entrada más vendida es General.',
                        finishReason: FinishReason::Stop,
                        toolCalls: [],
                        toolResults: [],
                        providerToolCalls: [],
                        usage: new Usage(10, 5),
                        meta: new Meta('fake', 'fake'),
                        messages: [],
                        systemPrompts: [],
                    ),
                ]))
                ->withText('Tu entrada más vendida es General.')
                ->withUsage(new Usage(promptTokens: 20, completionTokens: 10)),
        ]);

        $events = $this->events(
            $this->stream($this->mine->organizer->id, $this->userSays('¿cuál vendió más?'), $this->token)
                ->assertOk()
                ->streamedContent()
        );

        $tools = $this->eventsOfType($events, 'tool');
        $this->assertCount(1, $tools);
        $this->assertSame(['name' => 'get_ticket_ranking', 'arguments' => ['event_id' => 7]], $tools[0]['data']);

        $finished = $this->eventsOfType($events, 'tool_done');
        $this->assertCount(1, $finished, 'the client learns when a tool returns, never what it returned');
        $this->assertSame('get_ticket_ranking', $finished[0]['data']['name']);
        $this->assertTrue($finished[0]['data']['success']);
        $this->assertArrayHasKey('entities', $finished[0]['data']);
        $this->assertArrayNotHasKey('result', $finished[0]['data']);

        $done = $this->eventsOfType($events, 'done')[0]['data'];
        $this->assertSame('Tu entrada más vendida es General.', $done['reply']);
        $this->assertSame([['name' => 'get_ticket_ranking', 'arguments' => ['event_id' => 7]]], $done['tool_calls']);
    }

    public function test_disabled_assistant_is_an_error_event_with_404(): void
    {
        config()->set('assistant.enabled', false);
        Prism::fake();

        $events = $this->events(
            $this->stream($this->mine->organizer->id, $this->userSays('hola'), $this->token)
                ->assertOk()
                ->streamedContent()
        );

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['event']);
        $this->assertSame(ResponseCodes::HTTP_NOT_FOUND, $events[0]['data']['status']);
    }

    public function test_provider_failure_is_an_error_event_with_503(): void
    {
        // A fake with a finite response list throws once it runs out.
        Prism::fake([TextResponseFake::make()->withText('ok')]);

        $this->stream($this->mine->organizer->id, $this->userSays('hola'), $this->token)->assertOk()->streamedContent();

        $events = $this->events(
            $this->stream($this->mine->organizer->id, $this->userSays('de nuevo'), $this->token)
                ->assertOk()
                ->streamedContent()
        );

        $errors = $this->eventsOfType($events, 'error');
        $this->assertCount(1, $errors);
        $this->assertSame(ResponseCodes::HTTP_SERVICE_UNAVAILABLE, $errors[0]['data']['status']);
        $this->assertSame('The assistant is temporarily unavailable.', $errors[0]['data']['message']);
        $this->assertSame([], $this->eventsOfType($events, 'done'));
    }

    public function test_the_daily_token_budget_stops_the_account(): void
    {
        config()->set('assistant.daily_token_limit', 100);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('ok')->withUsage(new Usage(promptTokens: 90, completionTokens: 20)),
            TextResponseFake::make()->withText('no deberia llegar')->withUsage(new Usage(promptTokens: 10, completionTokens: 10)),
        ]);

        $first = $this->events(
            $this->stream($this->mine->organizer->id, $this->userSays('primera'), $this->token)
                ->assertOk()
                ->streamedContent()
        );
        $this->assertSame('done', end($first)['event']);

        // 110 tokens spent, limit is 100: the next question is refused before the provider is called.
        $second = $this->events(
            $this->stream($this->mine->organizer->id, $this->userSays('segunda'), $this->token)
                ->assertOk()
                ->streamedContent()
        );

        $this->assertCount(1, $second);
        $this->assertSame('error', $second[0]['event']);
        $this->assertSame(ResponseCodes::HTTP_TOO_MANY_REQUESTS, $second[0]['data']['status']);
        $this->assertSame('You have reached today\'s assistant usage limit. Please try again tomorrow.', $second[0]['data']['message']);

        $fake->assertCallCount(1);
    }
}
