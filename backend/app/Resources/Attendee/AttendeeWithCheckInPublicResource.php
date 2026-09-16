<?php

namespace HiEvents\Resources\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Resources\CheckInList\AttendeeCheckInPublicResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendeeDomainObject
 */
class AttendeeWithCheckInPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'public_id' => $this->getPublicId(),
            'product_id' => $this->getProductId(),
            'product_price_id' => $this->getProductPriceId(),
            'status' => $this->getStatus(),
            'locale' => $this->getLocale(),
            'order_id' => $this->getOrderId(),
            'seat_label' => $this->getSeatLabel(),
            // Only the single-attendee lookup loads the product: the roster resolves titles against
            // the check-in list's own products, and 250 of these per page would be weight for
            // nothing on the request that runs over the venue's connection.
            'product_title' => $this->when(
                !is_null($this->getProduct()),
                fn() => $this->getProduct()->getTitle(),
            ),
            $this->mergeWhen($this->getCheckIn() !== null, [
                'check_in' => new AttendeeCheckInPublicResource($this->getCheckIn()),
            ]),
            'other_check_ins' => ($this->getOtherCheckIns() ?? collect())->map(fn($checkIn) => [
                'check_in_list_name' => $checkIn->getCheckInList()?->getName(),
                'checked_in_at' => $checkIn->getCreatedAt(),
            ])->values(),
        ];
    }
}
