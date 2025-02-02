<?php

namespace App\Controllers\Api;

use React\EventLoop\Loop;
use App\Helpers\RedisSingleton;
use Config\Services;
use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use Config\Coaster as CoasterConfig;

class WagonController extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';
    
    private $loop;
    private $clientPromise;

    const QUEUE_PREFIX = 'coasters';
    const WAGON_PREFIX = 'wagons';

    public function __construct()
    {
        $this->config = new CoasterConfig();
    }

    private function setRedisConnection()
    {
        $this->loop = Loop::get();
        $this->clientPromise = RedisSingleton::getInstance()->getClient();
    }

    private function checkCoasterExists($client, $coasterId)
    {
        return $client->exists(self::QUEUE_PREFIX .":$coasterId");
    }

    private function checkWagonExists($client, $coasterId, $wagonId)
    {
        return $client->exists(self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":$wagonId");
    }

    public function create($coasterId)
    {
        $allowed = ['seat_quantity', 'speed'];

        $input = $this->request->getJSON(true);
        $input = collect($input)->filter(fn($value, $key) => in_array($key, $allowed))->all();
        
        $validation = Services::validation();
        
        $rules = [
            'seat_quantity' => 'required|integer|positive_number',
            'speed' => 'required|decimal|positive_number',
        ];

        if (!$this->validate($rules)) {
            return $this->fail($validation->getErrors());
        }

        $wagonId = uniqid('wagon_');
        $wagonKey = self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":$wagonId";
        
        $response = ['status' => 'error', 'message' => 'Failed to save data.'];

        $this->setRedisConnection();

        $this->clientPromise->then(function ($client) use ($wagonKey, $input, $wagonId, $coasterId, &$response) {
            $client->get(self::QUEUE_PREFIX . ":$coasterId")
                ->then(function ($coasterData) use ($client, $wagonKey, $input, $wagonId, &$response, $coasterId) {
                    
                    $coasterData = json_decode($coasterData);
                    $routeLength = (float) $coasterData?->route_length; 
                
                    error_log("routeLength: " . $routeLength);

                    $client->keys(self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":*")
                        ->then(function ($wagonKeys) use ($client, $wagonKey, $input, $wagonId, &$response, $routeLength, $coasterId) {
                            $totalWagonLength = 0;
                            $safeDistance = $this->config->safeDistance;
                            $wagonLength = $input['seat_quantity'] * $this->config->seatsToLengthConverter;
                            
                            error_log("New wagonLength: " . $wagonLength);
                            error_log("safeDistance: " . $safeDistance);

                            $promises = [];
                            
                            foreach ($wagonKeys as $key) {
                                $promises[] = $client->get($key)->then(function ($wagonData) use (&$totalWagonLength, $safeDistance) {
                                    $wagon = json_decode($wagonData, true);
                                    $totalWagonLength += (float) $wagon['seat_quantity'] * $this->config->seatsToLengthConverter + $safeDistance;
                                });
                            }
                            
                            \React\Promise\all($promises)->then(function () use (&$totalWagonLength, $wagonLength, $safeDistance, $routeLength, &$response, $client, $wagonKey, $input, $wagonId) {
                                error_log("Total wagon length before adding new: " . $totalWagonLength);

                                if ($totalWagonLength + $wagonLength + $safeDistance > $routeLength) {
                                    $response['message'] = 'Not enough space on the track for this wagon, considering safe distances.';
                                    error_log("Error: Not enough space");
                                    $this->loop->stop();
                                    return;
                                }

                                $client->set($wagonKey, json_encode($input))
                                    ->then(function () use ($wagonId, &$response) {
                                        $response['status'] = 'success';
                                        $response['wagonId'] = $wagonId;
                                        $response['message'] = 'The wagon has been successfully added.';
                                        error_log("Success: Wagon added with ID " . $wagonId);
                                        $this->loop->stop();
                                    });
                            });
                        });
                });
            
        })->otherwise(function ($e) use (&$response) {
            $response['message'] = 'Failed to connect to Redis: ' . $e->getMessage();
            error_log("Redis Connection Error: " . $e->getMessage());
            $this->loop->stop();
        });

        $this->loop->run();

        return $this->respond($response);
    }


    public function store($coasterId, $wagonId)
    {
        $allowed = ['seat_quantity', 'speed'];

        $input = $this->request->getJSON(true);
        $input = collect($input)->filter(fn($value, $key) => in_array($key, $allowed))->all();

        $validation = Services::validation();

        $rules = [
            'seat_quantity' => 'required|integer|positive_number',
            'speed' => 'required|decimal|positive_number',
        ];

        if (!$this->validate($rules)) {
            return $this->fail($validation->getErrors());
        }

        $this->setRedisConnection();
        
        $response = ['status' => 'error', 'message' => 'Failed to update wagon.'];
        
        $wagonKey = self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":$wagonId";
        
        $this->clientPromise->then(function ($client) use ($wagonKey, $input, &$response, $wagonId, $coasterId) {
            $client->exists($wagonKey)->then(function ($exists) use ($client, $wagonKey, $input, &$response, $wagonId, $coasterId) {
                if (!$exists) {
                    $response['message'] = 'Wagon not found.';
                    $this->loop->stop();
                    return;
                }
                
                $client->get(self::QUEUE_PREFIX . ":$coasterId")
                    ->then(function ($coasterData) use ($client, $wagonKey, $input, $wagonId, &$response, $coasterId) {
                        $coasterData = json_decode($coasterData);
                        $routeLength = (float) $coasterData?->route_length; 
                
                        error_log("RAW Coaster Data: " . ($routeLength));
                        $client->keys(self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":*")
                            ->then(function ($wagonKeys) use ($client, $wagonKey, $input, $wagonId, &$response, $routeLength, $coasterId) {
                                $totalWagonLength = 0;
                                $safeDistance = $this->config->safeDistance;
                                $wagonLength = $input['seat_quantity'] * $this->config->seatsToLengthConverter;
                                
                                foreach ($wagonKeys as $key) {
                                    $client->get($key)->then(function ($wagonData) use (&$totalWagonLength, $safeDistance) {
                                        $wagon = json_decode($wagonData, true);
                                        $totalWagonLength += (float) $wagon['seat_quantity'] * $this->config->seatsToLengthConverter + $safeDistance;
                                    });
                                }
                                
                                if ($totalWagonLength + $wagonLength + $safeDistance > $routeLength) {
                                    $response['message'] = 'Not enough space on the track for this wagon, considering safe distances.';
                                    $this->loop->stop();
                                    return;
                                }
                                
                                $client->set($wagonKey, json_encode($input))
                                    ->then(function () use ($wagonId, &$response) {
                                        $response = ['status' => 'success', 'message' => 'Wagon updated.', 'updated_wagon_id' => $wagonId];
                                        $this->loop->stop();
                                    });
                            });
                    });
            });
        });
        
        
        $this->loop->run();
        
        return $this->respond($response);
    }


    public function list($coasterId)
    {
        $this->setRedisConnection();
        
        $response = ['status' => 'error', 'message' => 'Failed to retrieve wagons.'];
        
        $this->clientPromise->then(function ($client) use ($coasterId, &$response) {
            $this->checkCoasterExists($client, $coasterId)->then(function ($exists) use ($client, $coasterId, &$response) {
                if (!$exists) {
                    $response['message'] = 'Coaster not found.';
                    $this->loop->stop();
                    return;
                }
                $client->keys(self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":*")
                    ->then(function ($keys) use ($client, &$response) {
                        if (empty($keys)) {
                            $response = ['status' => 'success', 'data' => []];
                            $this->loop->stop();
                            return;
                        }

                        $wagons = [];
                        $promises = [];
                        
                        foreach ($keys as $key) {
                            $promises[] = $client->get($key)->then(function ($data) use ($key, &$wagons) {
                                $wagons[] = ['id' => str_replace(self::QUEUE_PREFIX .":", '', $key), 'data' => json_decode($data, true)];
                            });
                        }
                        
                        \React\Promise\all($promises)->then(function () use (&$response, &$wagons) {
                            $response = ['status' => 'success', 'data' => $wagons];
                            $this->loop->stop();
                        });
                    });
            });
        });
        
        $this->loop->run();
        
        return $this->respond($response);
    }




    public function delete($coasterId, $wagonId)
    {
        $this->setRedisConnection();

        $response = ['status' => 'error', 'message' => 'Failed to delete wagon.'];
        $wagonKey = self::QUEUE_PREFIX .":$coasterId:" . self::WAGON_PREFIX .":$wagonId";

        $this->clientPromise->then(function ($client) use ($coasterId, $wagonId, $wagonKey, &$response) {
            $this->checkCoasterExists($client, $coasterId)->then(function ($coasterExists) use ($client,  $coasterId, $wagonId, $wagonKey, &$response) {
                if (!$coasterExists) {
                    $response['message'] = 'Coaster not found.';
                    $this->loop->stop();
                    return;
                }
                $this->checkWagonExists($client, $coasterId, $wagonId)->then(function ($wagonExists) use ($client, $wagonKey, $wagonId, &$response) {
                    if (!$wagonExists) {
                        $response['message'] = 'Wagon not found.';
                        $this->loop->stop();
                        return;
                    }
                    $client->del($wagonKey)->then(function ($result) use ($wagonId, &$response) {
                        $response = $result ?
                            ['status' => 'success', 'message' => 'Wagon deleted.', 'deleted_wagon_id' => $wagonId]
                            :
                            ['status' => 'error', 'message' => 'Failed to delete wagon.'];
                        $this->loop->stop();
                    });
                });
            });
        });
        
        $this->loop->run();
        
        return $this->respond($response);
    }
}

