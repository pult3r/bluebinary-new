<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Coaster extends BaseConfig
{
    public string $redisHostName = (ENVIRONMENT === 'production') ? 'bluebinary-redis-prod' : 'bluebinary-redis-dev';
    public int $redisPort = (ENVIRONMENT === 'production') ? 6379 : 6379;
    public int $neddedCoasterPersons = 1 ;
    public int $neddedWagonPersons = 2 ;

    public int $waitTime = 5 * 60; //time in seconds
    public int $safeDistance = 50 ;
    public float $seatsToLengthConverter = 1 ;  // one seat = 1 meter long
}