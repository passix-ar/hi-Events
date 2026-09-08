<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;

class SeatingPlanDTO extends BaseDataObject
{
    public function __construct(
        public int $stage_x,
        public int $stage_y,
        public bool $stage_visible,
        public Collection $sections,
    ) {}
}
