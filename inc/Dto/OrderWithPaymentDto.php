<?php
namespace CBSNorthStar\Dto;
use CBSNorthStar\Dto\GratuitiesDto;
use CBSNorthStar\Dto\PaymentsDTO;

class OrderWithPaymentDto extends OrderDto
{
    protected $paymentDto;

    public function __construct(array $data, PaymentsDTO $paymentDto, ?GratuitiesDto $gratuitiesDto = null)
    {
        parent::__construct($data, $gratuitiesDto);
        $this->paymentDto = $paymentDto;
    }

    public function toArray(): array
    {
        $order = parent::toArray();

        $order = array_merge(
            $order,
            $this->paymentDto->toArray()
        );

        return $order;
    }
}
