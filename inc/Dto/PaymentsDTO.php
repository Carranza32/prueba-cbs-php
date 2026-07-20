<?php

namespace CBSNorthStar\Dto;

class PaymentsDTO extends PaymentDTO
{
    public function toArray(): array
    {
        $remainingTip = $this->tip;

        if ($this->paymentType === '3') {
            return [
                'Payments' => $this->setGiftCardData($this->giftCards, $remainingTip)
            ];
        }

        $giftCardArray = $this->setGiftCardData($this->giftCards, $remainingTip);
        $tipToApply = $remainingTip > $this->total ? 0.0 : $remainingTip;
        $newTotal= $this->total;
        if($remainingTip == 0 && $this->grandTotal > $this->total) {
            $newTotal = $this->grandTotal;
        }

        $paymentsArray = [
            'Payments' => [
                [
                    'PaymentType' => $this->paymentType,
                    'Amount' => $newTotal,
                    'Tip' => empty($this->giftCards) ? $this->tip : $tipToApply,
                    'ReferenceCode' => $this->transactionNumber,
                    'ApprovalCode' => $this->checkId,
                    'PaymentCard' => [
                        'CardNumber' => '************' . $this->pan
                    ]
                ]
            ]
        ];

        if (!empty($giftCardArray)) {
            $paymentsArray['Payments'] = array_merge($paymentsArray['Payments'], $giftCardArray);
        }

        return $paymentsArray;
    }

    public function setGiftCardData(?array $giftCardArray, &$remainingTip)
    {
        if (!$giftCardArray) {
            return [];
        }

        $payments = [];

        foreach ($giftCardArray as $giftCard) {
            $giftcardDiscount = abs($giftCard['giftcardReduce']);
            $calculatedTotal = bcsub($giftcardDiscount, $remainingTip, 2);

            $amount = $calculatedTotal> 0 ? $calculatedTotal : abs($giftCard['giftcardReduce']);
            $tipApplied = $calculatedTotal > 0 ? $remainingTip : 0.0;

            if ($calculatedTotal > 0) {
                $remainingTip -= $tipApplied;
            }

            $payments[] = [
                'PaymentType' => 3,
                'Amount' => $amount,
                'Tip' => $tipApplied,
                'PaymentCard' => [
                    'CardNumber' => $giftCard['giftCardNumber'],
                ],
            ];
        }

        return $payments;
    }
}


