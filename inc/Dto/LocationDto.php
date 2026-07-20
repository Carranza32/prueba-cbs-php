<?php

namespace CBSNorthStar\Dto;

class LocationDto extends BaseDto
{
    protected string $tableId;
    protected string $areaId;

    public function __construct(string $table, string $area)
    {
        $this->tableId = $table;
        $this->areaId = $area;
    }

    public function toArray(): array
    {
        return [
            'AreaExternalCode' => $this->areaId,
            'LocationExternalCode' => $this->tableId
        ];
    }
}
