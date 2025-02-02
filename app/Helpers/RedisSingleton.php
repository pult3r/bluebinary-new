<?php

namespace App\Helpers;

use React\EventLoop\Loop;
use Clue\React\Redis\Factory;
use React\Promise\PromiseInterface;
use Config\Coaster as CoasterConfig;

class RedisSingleton
{
    private static ?RedisSingleton $instance = null;
    private ?PromiseInterface $client = null;
    private $config; 

    private function __construct()
    {
        $this->config = new CoasterConfig();
        $this->connect();
    }

    public static function getInstance(): RedisSingleton
    {
        if (self::$instance === null) {
            self::$instance = new RedisSingleton();
        }
        return self::$instance;
    }

    private function connect(): void
    {
        $loop = Loop::get();
        $factory = new Factory($loop);

        $this->client = $factory->createClient('redis://'.$this->config->redisHostName.':'.$this->config->redisPort);
    }

    public function getClient(): PromiseInterface
    {
        return $this->client;
    }
}
