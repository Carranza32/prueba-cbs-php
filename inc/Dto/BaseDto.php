<?php

namespace CBSNorthStar\Dto;

abstract class BaseDto
{
    abstract public function toArray();

    public function toJson()
    {
        $prev = ini_set( 'serialize_precision', -1 );
        $json = json_encode( $this->toArray() );
        ini_set( 'serialize_precision', $prev );
        return $json;
    }

}
