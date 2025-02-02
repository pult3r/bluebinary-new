<?php

namespace App\Validation;

class BluebinaryRules
{
    public function valid_time_period(string|int $str, string $fields): bool
    {
        return ($str > $fields);
    }

    public function valid_time(string|int $str): bool
    {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $str) === 1;
    }

    public function positive_number(float $number): bool
    {
        return ($number > 0);
    }
    
}