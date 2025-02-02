<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use React\EventLoop\Loop;
use App\Helpers\RedisSingleton;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Config\Coaster as CoasterConfig;

class QueueMonitor extends BaseCommand
{
    protected $group = 'Queue';
    protected $name = 'queue:listen';
    protected $description = 'Listens to events in Redis Pub/Sub';

    const QUEUE_PREFIX = 'coasters';
    const WAGON_PREFIX = 'wagons';

    private $config ; 

    public function __construct()
    {
        $this->config = new CoasterConfig();
    }
    
    /**
     * Initializes the Redis connection using the event loop.
     */
    private function setRedisConnection()
    {
        $this->loop = Loop::get();
        $this->clientPromise = RedisSingleton::getInstance()->getClient();
    }

    public function run(array $params)
    {
        $this->setRedisConnection();
        die($this->config->redisHostName);
        $this->clientPromise->then(function ($client) {

            CLI::write('Connected to Redis.', 'green');

            $client->psubscribe('__keyevent@0__:*')->then(null, function ($error) {
                CLI::error('Subscription error : ' . $error->getMessage());
            });
        
            $client->on('pmessage', function ($pattern, $channel, $message) {
                    if (Str::startsWith($message, self::QUEUE_PREFIX .':')) {


                        $this->displayStats('setcoaster');
                    } 
                });
            })
            ->otherwise(function ($e) {
                CLI::error('Error : cannot connect to Redis :' . $e->getMessage());
            });

        $this->loop->run();
    }

    private function displayStats($eventType) 
    {
        
        $redisConnection = new \Clue\React\Redis\Factory();
        $redisClientPromise = $redisConnection->createClient('redis://'.$this->config->redisHostName.':'.$this->config->redisPort);
    
        $redisClientPromise->then(function ($client) {

            $client->scan(0, 'MATCH', self::QUEUE_PREFIX .':*')->then(function ($result) use ($client) {

                if (!is_array($result) || count($result) < 2) {
                    CLI::error('Not valid response from SCAN');
                    return;
                }
            
                [$cursor, $keys] = $result; 

                $coasterKeys = array_filter($keys, function ($key) {
                    return substr_count($key, ':') === 1; 
                });
            
                if (empty($coasterKeys)) {
                    CLI::error('Brak coasterów w Redis.');
                    return;
                }
            
                $coasters = [];
                $promises = [];
            
                foreach ($coasterKeys as $key) {
                    $promises[] = $client->get($key)->then(function ($data) use ($key, &$coasters) {
                        $coasters[] = [
                            'id' => str_replace(self::QUEUE_PREFIX .':', '', $key), 
                            'data' => json_decode($data, true)
                        ];
                    });
                }
            
                \React\Promise\all($promises)->then(function () use ($client, &$coasters) {
                    $wagonPromises = [];
            
                    foreach ($coasters as &$coaster) {
                        $wagonPattern = self::QUEUE_PREFIX .":{$coaster['id']}:" . self::WAGON_PREFIX .":*";
                        $wagonPromises[] = $client->keys($wagonPattern)->then(function ($wagonKeys) use (&$coaster, $client) {
                            
                            $coaster['wagon_count'] = count($wagonKeys);
                            $coaster['wagons'] = [];

                            $wagonDataPromises = [];
                            
                            foreach ($wagonKeys as $wagonKey) {
                                $wagonDataPromises[] = $client->get($wagonKey)->then(function ($wagonData) use (&$coaster, $wagonKey) {
                                    $wagon = json_decode($wagonData, true);
                                    $coaster['wagons'][] = [
                                        'id' => str_replace(self::QUEUE_PREFIX .":{$coaster['id']}:" . self::WAGON_PREFIX .":", '', $wagonKey),
                                        'seatQuantity' => $wagon['seat_quantity'],
                                        'speed' => $wagon['speed'],
                                    ];
                                });
                            }

                            return \React\Promise\all($wagonDataPromises)->then(function () use (&$coaster) {
                            });

                        });
                    }
            
                    \React\Promise\all($wagonPromises)->then(function () use (&$coasters) {
                        CLI::write("[". Carbon::now()->format('d-m-Y H:i:s')  ."]".PHP_EOL , 'yellow');

                        foreach ($coasters as $coaster) {
                            $wagonCount = $coaster['wagon_count'];
                            $neededPersons = $wagonCount * $this->config->neddedWagonPersons + $this->config->neddedCoasterPersons; 

                            $stuff = $coaster['data']['personel_quantity'] ;
                            $coasterStats = $this->calculateCoasterStats($coaster);

                            $status = [];

                            $staffAvailabilityColor = 'green' ; 
                            if($stuff < $neededPersons) {
                                $staffAvailabilityColor = 'red' ;
                                if(isset($status[$coaster['id']])) {
                                    $status[$coaster['id']] .= "Problem : employees are missing (".  ($stuff - $neededPersons) .")";
                                } else {
                                    $status[$coaster['id']] = "Problem : employees are missing (".  ($stuff - $neededPersons) .")";
                                }
                            } 

                            $customerAvailabilityColor = 'green' ; 
                            if($coasterStats['totalPassengers'] < (int) $coaster['data']['customer_quantity']) {
                                $customerAvailabilityColor = 'red' ;
                                if(isset($status[$coaster['id']])) {
                                    $status[$coaster['id']] .= "Problem : employees are missing (".  ($stuff - $neededPersons) .")";
                                } else {
                                    $status[$coaster['id']] = "Problem : wagons are missing. ".  ((int) $coaster['data']['customer_quantity'] - $coasterStats['totalPassengers'] )." persons will not be served";
                                }
                            } 

                            CLI::write("[Coaster : ". $coaster['id'] ."]".PHP_EOL , 'yellow');
                            CLI::write("1. Hours of operation : ". $coaster['data']['time_from'] . " - ". $coaster['data']['time_to'] , 'cyan');
                            CLI::write("2. Wagon quantity : " . $wagonCount, 'cyan');
                            CLI::write("3. Staff available : ". $neededPersons .'/'. $stuff, $staffAvailabilityColor);
                            CLI::write("4.Customers per day : ".$coasterStats['totalPassengers'] .'/'. $coaster['data']['customer_quantity'] , $customerAvailabilityColor);
                            
                            if(isset($status[$coaster['id']])) { 
                                CLI::write("5. Problem : ".$status[$coaster['id']], 'red');
                            } else {
                                CLI::write("5. Status : OK", 'cyan');
                            }

                            CLI::write(PHP_EOL.PHP_EOL);
                        }
                    });
                })->otherwise(function ($e) {
                    CLI::error('Error : Getting value error : ' . $e->getMessage());
                });
            
            })->otherwise(function ($e) {
                CLI::error('Error : Getting key error : ' . $e->getMessage());
            });
    
        })->otherwise(function ($e) {
            CLI::error('Error : cannot connect to Redis : ' . $e->getMessage());
        });
    }


    function calculateCoasterStats($coaster) 
    {
        $routeLength = $coaster['data']['route_length']; 
        $timeFrom = strtotime($coaster['data']['time_from']);
        $timeTo = strtotime($coaster['data']['time_to']);
        $neddedCoasterPersons = $this->config->neddedCoasterPersons; 
        $neddedWagonPersons = $this->config->neddedWagonPersons;
        $safeDistance = $this->config->safeDistance; 
        $waitTime = $this->config->waitTime; 
        $seatLength = $this->config->seatsToLengthConverter; 
        $wagons = $coaster['wagons']; 
    

        usort($wagons, function ($a, $b) {
            return $b['speed'] <=> $a['speed'];
        });
    
        $wagonStats = [];
        $totalPassengers = 0;
        $currentTime = $timeFrom;
        $activeWagons = []; 
    
        foreach ($wagons as &$wagon) {
            $wagon['nextAvailableTime'] = $timeFrom;
        }
    
        while ($currentTime < $timeTo) {
            foreach ($wagons as &$wagon) {
                if ($wagon['nextAvailableTime'] > $currentTime) {
                    continue;
                }

                $wagonLength = $wagon['seatQuantity'] * $seatLength;
                $travelTime = $routeLength / $wagon['speed'];
    
                $canStart = true;
                $lastEndTime = 0;
                foreach ($activeWagons as $active) {
                    $distanceBetween = ($currentTime - $active['startTime']) * $active['speed'];
                    if ($distanceBetween < $wagonLength + $safeDistance) {
                        $canStart = false;
                        $lastEndTime = max($lastEndTime, $active['endTime']);
                    }
                }
    
                if (!$canStart) {
                    $wagon['nextAvailableTime'] = $lastEndTime + $safeDistance / $wagon['speed'];
                    continue;
                }

                if (!isset($wagonStats[$wagon['id']])) {
                    $wagonStats[$wagon['id']] = ['trips' => 0, 'passengers' => 0];
                }
                $wagonStats[$wagon['id']]['trips']++;
                $wagonStats[$wagon['id']]['passengers'] += $wagon['seatQuantity'];
                $totalPassengers += $wagon['seatQuantity'];
    

                $wagon['nextAvailableTime'] = $currentTime + $travelTime + $waitTime;
                $activeWagons[] = [
                    'id' => $wagon['id'],
                    'startTime' => $currentTime,
                    'endTime' => $currentTime + $travelTime,
                    'speed' => $wagon['speed']
                ];
            }
    
            $currentTime += 1;
            
            $activeWagons = array_filter($activeWagons, function ($w) use ($currentTime) {
                return $w['endTime'] > $currentTime;
            });
        }
    
        return [
            'wagonStats' => $wagonStats,
            'totalPassengers' => $totalPassengers,
            'requiredStaff' => count($wagons) * $neddedWagonPersons + $neddedCoasterPersons
        ];
    }
}