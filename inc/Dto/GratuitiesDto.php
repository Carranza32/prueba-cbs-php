<?php
namespace CBSNorthStar\Dto;

class GratuitiesDto extends BaseDto
{
    protected $gratuities;

    public function __construct( int $externalCode , float $amount )
    {
        if($externalCode === 0){
            $this->gratuities = [];
            return;
        }
        $this->gratuities = [
            [
                'GratuityExternalCode' => $externalCode,
                'Amount' => $amount
            ]
        ];
        
    }

    public function toArray(): array
    {
        return [
            'Gratuities' => $this->gratuities
        ];
    }
}