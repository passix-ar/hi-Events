<?php

namespace Tests\Unit\Services\Domain\Question;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Product\EventProductValidationService;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Question\CreateQuestionService;
use HiEvents\Services\Domain\Question\EditQuestionService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class QuestionProductOwnershipTest extends TestCase
{
    private QuestionRepositoryInterface $questions;

    private DatabaseManager $database;

    private HtmlPurifierService $purifier;

    private EventProductValidationService $validation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->questions = m::mock(QuestionRepositoryInterface::class);
        $this->database = m::mock(DatabaseManager::class);
        $this->database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
        $this->purifier = m::mock(HtmlPurifierService::class);
        $this->purifier->shouldReceive('purify')->andReturnUsing(fn ($html) => $html);
        $this->validation = m::mock(EventProductValidationService::class);
    }

    public function test_creating_a_question_on_products_of_another_event_is_rejected(): void
    {
        $this->validation->shouldReceive('validateProductIds')
            ->once()
            ->with([77], 1)
            ->andThrow(new UnrecognizedProductIdException('Invalid product ids: 77'));
        $this->questions->shouldNotReceive('create');

        $this->expectException(UnrecognizedProductIdException::class);
        $this->createService()->createQuestion($this->question(eventId: 1), [77]);
    }

    public function test_an_order_level_question_without_products_can_still_be_created(): void
    {
        // Las preguntas a nivel orden no llevan productos: la validacion nueva no
        // puede romper ese camino, que es el que usa todo checkout con preguntas.
        $this->validation->shouldReceive('validateProductIds')->once()->with([], 1);
        $this->questions->shouldReceive('create')->once()->andReturn(new QuestionDomainObject);

        $this->createService()->createQuestion($this->question(eventId: 1), []);

        $this->assertTrue(true);
    }

    public function test_editing_a_question_from_another_event_is_rejected_before_relinking_products(): void
    {
        // updateQuestion() borra los vinculos producto-pregunta sin filtrar por
        // evento: con un id ajeno sacaria la pregunta del checkout de otro.
        $this->questions->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->questions->shouldNotReceive('updateQuestion');
        $this->validation->shouldNotReceive('validateProductIds');

        $this->expectException(ResourceNotFoundException::class);
        $this->editService()->editQuestion($this->question(id: 99, eventId: 1), [10]);
    }

    public function test_editing_a_question_with_products_of_another_event_is_rejected(): void
    {
        $this->questions->shouldReceive('findFirstWhere')->once()->andReturn(new QuestionDomainObject);
        $this->validation->shouldReceive('validateProductIds')
            ->once()
            ->with([77], 1)
            ->andThrow(new UnrecognizedProductIdException('Invalid product ids: 77'));
        $this->questions->shouldNotReceive('updateQuestion');

        $this->expectException(UnrecognizedProductIdException::class);
        $this->editService()->editQuestion($this->question(id: 5, eventId: 1), [77]);
    }

    private function createService(): CreateQuestionService
    {
        return new CreateQuestionService($this->questions, $this->database, $this->purifier, $this->validation);
    }

    private function editService(): EditQuestionService
    {
        return new EditQuestionService($this->questions, $this->database, $this->purifier, $this->validation);
    }

    private function question(int $eventId, ?int $id = null): QuestionDomainObject
    {
        $question = (new QuestionDomainObject)
            ->setEventId($eventId)
            ->setTitle('Talle')
            ->setBelongsTo('ORDER')
            ->setType('SINGLE_LINE_TEXT')
            ->setRequired(false)
            ->setIsHidden(false)
            ->setDescription(null);

        return $id === null ? $question : $question->setId($id);
    }
}
