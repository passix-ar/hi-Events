<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Services\Domain\Order\DTO\ApplicationFeeValuesDTO;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoPreferenceService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository as Config;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class MercadoPagoPreferenceServiceTest extends TestCase
{
    private function makeService(Config $config, OrderApplicationFeeCalculationService $feeService): MercadoPagoPreferenceService
    {
        return new MercadoPagoPreferenceService(
            $config,
            Mockery::mock(LoggerInterface::class),
            $feeService,
        );
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject())->setCurrency('ARS')->setTotalGross(18000);
    }

    public function test_returns_zero_when_saas_mode_disabled(): void
    {
        $config = new Config(['app' => ['saas_mode_enabled' => false]]);
        $feeService = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $feeService->shouldNotReceive('calculateApplicationFee');

        $accountConfiguration = (new AccountConfigurationDomainObject())->setBypassApplicationFees(false);

        $fee = $this->makeService($config, $feeService)
            ->calculateMarketplaceFee($accountConfiguration, $this->order());

        $this->assertSame(0.0, $fee);
    }

    public function test_returns_zero_when_account_bypasses_fees(): void
    {
        $config = new Config(['app' => ['saas_mode_enabled' => true]]);
        $feeService = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $feeService->shouldNotReceive('calculateApplicationFee');

        $accountConfiguration = (new AccountConfigurationDomainObject())->setBypassApplicationFees(true);

        $fee = $this->makeService($config, $feeService)
            ->calculateMarketplaceFee($accountConfiguration, $this->order());

        $this->assertSame(0.0, $fee);
    }

    public function test_returns_calculated_gross_fee(): void
    {
        $config = new Config(['app' => ['saas_mode_enabled' => true]]);
        $feeService = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $feeService->shouldReceive('calculateApplicationFee')->once()->andReturn(
            new ApplicationFeeValuesDTO(
                grossApplicationFee: MoneyValue::fromFloat(100.0, 'ARS'),
                netApplicationFee: MoneyValue::fromFloat(100.0, 'ARS'),
            )
        );

        $accountConfiguration = (new AccountConfigurationDomainObject())->setBypassApplicationFees(false);

        $fee = $this->makeService($config, $feeService)
            ->calculateMarketplaceFee($accountConfiguration, $this->order());

        $this->assertSame(100.0, $fee);
    }
}
