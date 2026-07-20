<?php

namespace CBSNorthStar\Dto;

use CBSNorthStar\Logger\CBSLogger;

class PaymentDTO extends BaseDto
{
    protected $paymentType;
    protected $pan;
    protected $tip;
    protected $transactionNumber;
    protected $checkId;
    protected $total;
    protected $giftCards;
    protected $grandTotal;

    public function __construct(
        string $paymentType='',
        ?string $pan ='',
        ?string $transactionNumber ='',
        ?string $checkId ='',
        float $total =0.0,
        ?float $tip = 0.0,
        ?array $giftCards = null
    )
    {
        $this->paymentType = $paymentType;
        $this->pan = $pan;

        $tipNormalized   = number_format( is_null( $tip ) ? 0.0 : (float) $tip, 2, '.', '' );
        $totalNormalized = number_format( (float) $total, 2, '.', '' );

        $this->tip        = (float) $tipNormalized;
        $this->grandTotal = (float) $totalNormalized;

        if ( $this->tip > (float) $totalNormalized ) {
            $this->total = (float) $totalNormalized;
        } else {
            $this->total = (float) bcsub( $totalNormalized, $tipNormalized, 2 );
        }
        $this->transactionNumber = $transactionNumber;
        $this->checkId = $checkId;
        CBSLogger::payments()->debug('PaymentDTO constructed', [
            'tip'   => $this->tip,
            'total' => $this->total,
        ]);
        $this->giftCards = $giftCards;

    }

    public function toArray(): array
    {
        return [
            'Payment' => [
                'PaymentType' => $this->paymentType,
                'Amount' => $this->total,
                'Tip' => $this->tip > $this->total ? 0.0 : $this->tip,
                'ReferenceCode' => $this->transactionNumber,
                'ApprovalCode' => $this->checkId,
                'PaymentCard' => [
                    'CardNumber' => '************' . $this->pan
                ]
            ]
        ];
    }
}
