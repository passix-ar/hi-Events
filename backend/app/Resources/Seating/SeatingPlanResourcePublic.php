<?php

namespace HiEvents\Resources\Seating;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\SeatingPlanDTO;
use Illuminate\Http\Request;

/**
 * @mixin SeatingPlanDTO
 */
class SeatingPlanResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage_x' => $this->stage_x,
            'stage_y' => $this->stage_y,
            'sections' => SeatingSectionResourcePublic::collection($this->sections),
        ];
    }
}
