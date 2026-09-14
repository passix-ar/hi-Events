<?php

namespace Tests\Unit\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DeleteQuestionHandler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class DeleteQuestionHandlerTest extends TestCase
{
    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = m::mock(DatabaseManager::class);
        $this->database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
    }

    public function test_a_question_from_another_event_is_rejected_before_deleting_anything(): void
    {
        $questions = m::mock(QuestionRepositoryInterface::class);
        $questions->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 99, 'event_id' => 1])
            ->andReturnNull();
        $questions->shouldNotReceive('deleteWhere');

        $answers = m::mock(QuestionAnswerRepositoryInterface::class);
        $answers->shouldNotReceive('findWhere');
        $answers->shouldNotReceive('deleteWhere');

        $handler = new DeleteQuestionHandler($questions, $answers, $this->database);

        $this->expectException(ResourceNotFoundException::class);
        $handler->handle(99, 1);
    }

    public function test_a_question_of_the_event_is_deleted(): void
    {
        $questions = m::mock(QuestionRepositoryInterface::class);
        $questions->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 5, 'event_id' => 1])
            ->andReturn(new QuestionDomainObject);
        $questions->shouldReceive('deleteWhere')
            ->once()
            ->with(['id' => 5, 'event_id' => 1]);

        $answers = m::mock(QuestionAnswerRepositoryInterface::class);
        $answers->shouldReceive('findWhere')->once()->andReturn(new Collection);
        $answers->shouldReceive('deleteWhere')->once();

        $handler = new DeleteQuestionHandler($questions, $answers, $this->database);
        $handler->handle(5, 1);

        $this->assertTrue(true);
    }
}
