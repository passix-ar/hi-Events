<?php

namespace Tests\Unit\Services\Domain\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Product\EventProductValidationService;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class EventProductValidationServiceTest extends TestCase
{
    private EventProductValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findWhere')
            ->with(['event_id' => 1])
            ->andReturn(new Collection([
                (new ProductDomainObject)->setId(10),
                (new ProductDomainObject)->setId(11),
            ]));

        $this->service = new EventProductValidationService($products);
    }

    public function test_an_empty_list_is_valid(): void
    {
        // Crear y editar preguntas ahora pasa por esta validacion, y las
        // preguntas a nivel orden no llevan productos (infra#4).
        $this->service->validateProductIds([], 1);

        $this->assertTrue(true);
    }

    public function test_products_of_the_event_are_valid(): void
    {
        $this->service->validateProductIds([10, 11], 1);

        $this->assertTrue(true);
    }

    public function test_a_product_of_another_event_is_rejected(): void
    {
        $this->expectException(UnrecognizedProductIdException::class);

        $this->service->validateProductIds([10, 77], 1);
    }
}
