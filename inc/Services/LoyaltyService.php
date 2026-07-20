<?php
namespace CBSNorthStar\Services;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Logger\CBSLogger;

class LoyaltyService {
    protected $siteId;
    protected $phoneNumber;
    protected $loyaltyData;
    protected $availableProgramsUrl;
    protected $membershipByPhoneUrl;
    protected $payload;
    protected $availablePrograms;

    public function __construct(string $siteId, string $phoneNumber){

            $this->siteId = $siteId;
            $this->phoneNumber = $phoneNumber;
            $this->loyaltyData = [];
            $this->availableProgramsUrl = "/loyalty/availableprograms";
            $this->membershipByPhoneUrl = "/membership/phonenumber/".$this->phoneNumber;
            $this->payload = (object)[];
            $this->availablePrograms = [];
    }

    public function getLoyaltyData() {

        return $this->loyaltyData;
    }
    public function getQualifyingOrderItemIds( $response, string $programId): array
    {

        if (empty($response) ) {
            return [];
        }

        foreach ($response as $program) {

            if (($program->ProgramId ?? null) === $programId) {

                return $program->QualifyingOrderItemIds ?? [];
            }
        }


        return [];
    }

    

    public function getAvailableLoyaltyPrograms($payload) {

        $this->payload = $payload;
        $this->payload->PhoneNumber = $this->phoneNumber;
        $availablePrograms = (new Connection())->postData($this->siteId, $this->availableProgramsUrl, 'Token' , json_encode($this->payload));

        if ($availablePrograms->Ok) {
            $this->availablePrograms = $availablePrograms->Data->AvailablePrograms ?? [];
            CBSLogger::transactions()->debug('Available loyalty programs retrieved', ['payload' => $this->payload, 'response' => $availablePrograms]);
            return $this->availablePrograms;
        }
        CBSLogger::transactions()->debug('Failed to retrieve available loyalty programs', ['payload' => $this->payload, 'response' => $availablePrograms]);
        return [];
    }

    public function getUserProgramsByPhone() {

        $userEnrollments = (new Connection())->getData($this->siteId, $this->membershipByPhoneUrl, 'Token');

        if ($userEnrollments->Ok) {
            CBSLogger::transactions()->debug('User enrollments retrieved successfully', ['phone' => $this->phoneNumber, 'response' => $userEnrollments]);
            return $userEnrollments->Data ?? [];
        }
        CBSLogger::transactions()->debug('Failed to retrieve user enrollments', ['phone' => $this->phoneNumber, 'response' => $userEnrollments]);
        return [];
    }

    public function mapCustomerLoyaltyPrograms($checkAvailablePrograms, $userEnrollments, $payload , $loyaltyRewards) {
                $enrollmentMap = [];
                if (!empty($userEnrollments)) {
                    foreach ($userEnrollments as $cust) {
                        $programs = $cust->LoyaltyAccount->ProgramEnrollments ?? [];
                        foreach ($programs as $p) {
                            $adjustmentReasonId = $p->AdjustmentReasonId ?? null;
                            $programName = $p->Name ?? null;
                            $uniqueKey = md5(($p->ProgramId ?? '') . '|' . $adjustmentReasonId . '|' . $programName);
                            $enrollmentMap[$p->ProgramId][$uniqueKey] = (object)[
                                'AdjustmentReasonId' => $p->AdjustmentReasonId ?? null,
                                'name'               => $p->Name ?? null,
                                'points'             => $p->Points ?? null,
                            ];
                        }
                    }
                }

                $availablePrograms = $checkAvailablePrograms ?? [];
                $loyaltyRewardsPrograms = $loyaltyRewards['programs'] ?? [];

                $rewards = [];
                foreach ($availablePrograms as $prog) {
                    $pid = $prog->ProgramId ?? null;
                    if (!$pid || !isset($enrollmentMap[$pid])) {
                        continue;
                    }
                    foreach ($enrollmentMap[$pid] as $match) {
                        
                        $points = (int) ($match->points ?? 0);
                        if ($points > 0) {
                            $adjustmentReasonId = $match->AdjustmentReasonId ?? '';
                            $nameForKey = $match->name ?? '';
                            $uniqueKey = md5($pid . '|' . $adjustmentReasonId . '|' . $nameForKey);
                            $redeem = isset($loyaltyRewardsPrograms[$pid][$uniqueKey]);
                            $rewards[$uniqueKey] = (object)[
                                'QualifyingOrderItemIds' => $prog->QualifyingOrderItemIds ?? [],
                                'ProgramId'              => $pid,
                                'AdjustmentReasonId'     => $match->AdjustmentReasonId,
                                'name'                   => $match->name,
                                'points'                 => $match->points,
                                'redeemed'               => $redeem,
                                'uniqueKey'              => $uniqueKey
                            ];
                        }
                    }
                }
                $loyaltyData['AvailablePrograms'] = array_values($rewards);
                $loyaltyData['OrderItems'] = $payload->OrderItems ?? [];
                $loyaltyData['CustomerId'] = $userEnrollments[0]->CustomerId ?? null;
                $loyaltyData['Phone'] = $userEnrollments[0]->Phone ?? null;

                return $loyaltyData ? $loyaltyData : [];
    }



}