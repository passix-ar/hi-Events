<?php

namespace HiEvents\Resources\Seating;

use HiEvents\DomainObjects\SeatingLayoutDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SeatingLayoutDomainObject
 */
class SeatingLayoutResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage_x' => $this->getStageX(),
            'stage_y' => $this->getStageY(),
        ];
    }
}
