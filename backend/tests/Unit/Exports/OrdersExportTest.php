<?php

namespace Tests\Unit\Exports;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Exports\OrdersExport;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class OrdersExportTest extends TestCase
{
    private function makeExport(): OrdersExport
    {
        return (new OrdersExport(Mockery::mock(QuestionAnswerFormatter::class)))
            ->withData(new LengthAwarePaginator([], 0, 10), new Collection());
    }

    private function baseOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(1)
            ->setStatus('COMPLETED')
            ->setCurrency('USD')
            ->setPublicId('O-123')
            ->setCreatedAt('2026-01-01 00:00:00')
            ->setIsManuallyCreated(false)
            ->setTotalBeforeAdditions(100.00)
            ->setTotalGross(100.00)
            ->setTotalTax(0.00)
            ->setTotalFee(0.00)
            ->setTotalRefunded(0.00);
    }

    public function test_map_appends_total_quantity_and_per_type_breakdown(): void
    {
        $order = $this->baseOrder()->setOrderItems(new Collection([
            (new OrderItemDomainObject())->setItemName('General')->setQuantity(2),
            (new OrderItemDomainObject())->setItemName('VIP')->setQuantity(1),
        ]));

        $row = $this->makeExport()->map($order);

        // With no order-level questions, the two new columns are the last two of the row.
        $this->assertSame(3, $row[count($row) - 2]);
        $this->assertSame('2x General, 1x VIP', $row[count($row) - 1]);
    }

    public function test_map_handles_order_with_no_items(): void
    {
        $order = $this->baseOrder();

        $row = $this->makeExport()->map($order);

        $this->assertSame(0, $row[count($row) - 2]);
        $this->assertSame('', $row[count($row) - 1]);
    }
}
