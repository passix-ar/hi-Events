<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Enums\SeatState;

class SeatDomainObject extends Generated\SeatDomainObjectAbstract
{
    protected ?string $state = null;

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->state === SeatState::AVAILABLE->name;
    }
}
