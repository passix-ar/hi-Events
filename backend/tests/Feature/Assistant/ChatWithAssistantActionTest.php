<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Http\ResponseCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class ChatWithAssistantActionTest extends TestCase
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

    private function chat(int $organizerId, array $body, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this->postJson("/organizers/{$organizerId}/assistant/chat", $body, $headers);
    }

    private function userSays(string $text): array
    {
        return ['messages' => [['role' => 'user', 'content' => $text]]];
    }

    public function test_requires_authentication(): void
    {
        $this->chat($this->mine->organizer->id, $this->userSays('hola'))
            ->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_returns_404_while_the_assistant_is_disabled(): void
    {
        config()->set('assistant.enabled', false);
        Prism::fake();

        $this->chat($this->mine->organizer->id, $this->userSays('hola'), $this->token)
            ->assertStatus(ResponseCodes::HTTP_NOT_FOUND);
    }

    public function test_cannot_chat_about_another_accounts_organizer(): void
    {
        $fake = Prism::fake();

        $this->chat($this->theirs->organizer->id, $this->userSays('hola'), $this->token)
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);

        $fake->assertCallCount(0);
    }

    public function test_validates_the_message_list(): void
    {
        Prism::fake();

        $this->chat($this->mine->organizer->id, ['messages' => []], $this->token)
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);

        $this->chat($this->mine->organizer->id, ['messages' => [['role' => 'system', 'content' => 'x']]], $this->token)
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);

        $this->chat($this->mine->organizer->id, ['messages' => [['role' => 'assistant', 'content' => 'x']]], $this->token)
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['messages']);

        $this->chat($this->mine->organizer->id, $this->userSays(str_repeat('a', 4001)), $this->token)
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_replies_with_the_model_answer_and_scopes_the_request_to_the_organizer(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()
                ->withText('Vendiste 2 entradas por $10.000.')
                ->withUsage(new Usage(promptTokens: 1200, completionTokens: 40)),
        ]);

        $response = $this->chat($this->mine->organizer->id, [
            'messages' => [
                ['role' => 'user', 'content' => 'hola'],
                ['role' => 'assistant', 'content' => '¡Hola! ¿Qué querés saber?'],
                ['role' => 'user', 'content' => '¿cuánto vendí?'],
            ],
        ], $this->token);

        $response->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonPath('data.reply', 'Vendiste 2 entradas por $10.000.')
            ->assertJsonPath('data.tool_calls', []);

        $fake->assertCallCount(1);
        $fake->assertRequest(function (array $requests): void {
            $request = $requests[0];

            $this->assertCount(3, $request->messages());
            $this->assertCount(5, $request->tools());
            $this->assertStringContainsString('Org A', $request->systemPrompts()[0]->content);
            $this->assertStringNotContainsString('Org B', $request->systemPrompts()[0]->content);
        });
    }

    public function test_provider_failure_is_a_503_not_a_500(): void
    {
        // A fake with a finite response list throws once it runs out: the second
        // call is what a provider outage looks like from the service's point of view.
        Prism::fake([TextResponseFake::make()->withText('ok')]);

        $this->chat($this->mine->organizer->id, $this->userSays('hola'), $this->token)
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->chat($this->mine->organizer->id, $this->userSays('hola de nuevo'), $this->token)
            ->assertStatus(ResponseCodes::HTTP_SERVICE_UNAVAILABLE)
            ->assertJsonPath('message', 'The assistant is temporarily unavailable.');
    }

    public function test_request_carries_the_expected_model_settings(): void
    {
        config()->set('assistant.model', 'claude-opus-5');
        config()->set('assistant.max_steps', 6);
        config()->set('assistant.max_tokens', 2048);

        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        $this->chat($this->mine->organizer->id, $this->userSays('hola'), $this->token)->assertOk();

        $fake->assertRequest(function (array $requests): void {
            $request = $requests[0];

            $this->assertSame('claude-opus-5', $request->model());
            $this->assertSame('anthropic', $request->provider());
            $this->assertSame(6, $request->maxSteps());
            $this->assertSame(2048, $request->maxTokens());
            $this->assertNull($request->temperature(), 'temperature must not be sent: Opus 5 rejects it');
        });
    }
}
