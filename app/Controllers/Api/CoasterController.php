<?php

namespace App\Controllers\Api;

use React\EventLoop\Loop;
use App\Helpers\RedisSingleton;
use Config\Services;
use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use Illuminate\Support\Collection;
use Config\Coaster as CoasterConfig;

class CoasterController extends BaseController
{
    use ResponseTrait;

    protected $format = 'json';
    
    private $loop;
    private $clientPromise;

    const QUEUE_PREFIX = 'coasters';

    /**
     * Initializes the Redis connection using the event loop.
     */
    private function setRedisConnection()
    {
        $this->loop = Loop::get();
        $this->clientPromise = RedisSingleton::getInstance()->getClient();
    }

    private function checkCoasterExists($client, $coasterId)
    {
        return $client->exists(self::QUEUE_PREFIX .":$coasterId");
    }

    /**
     * Handles the creation of a new coaster record in Redis.
     */
    public function create()
    {
        $allowed = ['id', 'personel_quantity', 'customer_quantity', 'route_length', 'time_from', 'time_to'];

        $input = $this->request->getJSON(true);
        $input = collect($input)->filter(fn($value, $key) => in_array($key, $allowed))->all();

        $validation = Services::validation();

        $rules = [
            'id' => 'permit_empty|integer',
            'personel_quantity' => 'required|integer|positive_number',
            'customer_quantity' => 'required|integer|positive_number',
            'route_length' => 'required|integer|positive_number',
            'time_from' => 'required|valid_time',
            'time_to' => 'required|valid_time|valid_time_period['.$input['time_from'].']',
        ];

        if (!$this->validate($rules)) {
            return $this->fail($validation->getErrors());
        }

        $coasterId = (isset($input['id'])) ? 'coaster_' . $input['id'] : uniqid('coaster_');

        $response = [
            'status' => 'error',
            'message' => 'Failed to save data.',
        ];

        $this->setRedisConnection();

        $this->clientPromise->then(function ($client) use ($coasterId, $input, &$response) {
            $this->checkCoasterExists($client, $coasterId)->then(function ($exists) use ($client, $coasterId, $input, &$response) {
                if ($exists) {
                    $response['message'] = 'Coaster already exist.';
                    $this->loop->stop();
                    return;
                }

                $client->set(self::QUEUE_PREFIX .':'. $coasterId, json_encode($input))
                    ->then(function () use ($coasterId, &$response) {
                        $response['status'] = 'success';
                        $response['coasterId'] = $coasterId;
                        $response['message'] = 'The data has been successfully saved.';
                        $this->loop->stop(); 
                    });
                });
            })
            ->otherwise(function ($e) use (&$response) {
                $response['message'] = 'Failed to connect to Redis: ' . $e->getMessage();
                $this->loop->stop(); 
            });

        $this->loop->run(); 

        return $this->respond($response);
    }

    /**
     * Retrieves a list of all coasters stored in Redis.
     */
    public function list()
    {
        $this->setRedisConnection();

        $response = ['status' => 'error', 'message' => 'Failed to retrieve data.'];

        $this->clientPromise->then(function ($client) use (&$response) {
            $client->keys(self::QUEUE_PREFIX . ':*')->then(function ($keys) use ($client, &$response) {
                if (empty($keys)) {
                    $response = ['status' => 'success', 'data' => []];
                    $this->loop->stop();
                    return;
                }

                $coasters = [];
                $promises = [];

                foreach ($keys as $key) {
                    // Oddzielamy kolejki od wagoników
                    if (strpos($key, ':wagons:') !== false) {
                        continue;
                    }

                    $promises[] = $client->get($key)->then(function ($data) use ($key, &$coasters, $client) {
                        $coasterId = str_replace(self::QUEUE_PREFIX . ':', '', $key);
                        $coasterData = json_decode($data, true);

                        // Pobranie wagonów powiązanych z danym coasterem
                        $wagonKeyPattern = self::QUEUE_PREFIX . ':' . $coasterId . ':wagons:*';
                        return $client->keys($wagonKeyPattern)->then(function ($wagonKeys) use ($client, $coasterId, $coasterData, &$coasters) {
                            $wagonPromises = [];

                            foreach ($wagonKeys as $wagonKey) {
                                $wagonPromises[] = $client->get($wagonKey)->then(function ($wagonData) use ($wagonKey) {
                                    return [
                                        'id' => str_replace(self::QUEUE_PREFIX . ":", "", $wagonKey),
                                        'data' => json_decode($wagonData, true)
                                    ];
                                });
                            }

                            return \React\Promise\all($wagonPromises)->then(function ($wagons) use ($coasterId, $coasterData, &$coasters) {
                                $coasters[] = [
                                    'id' => $coasterId,
                                    'data' => $coasterData,
                                    'wagons' => $wagons
                                ];
                            });
                        });
                    });
                }

                \React\Promise\all($promises)->then(function () use (&$response, &$coasters) {
                    $response = ['status' => 'success', 'data' => $coasters];
                    $this->loop->stop();
                })->otherwise(function ($e) use (&$response) {
                    $response['message'] = 'Failed to fetch values: ' . $e->getMessage();
                    $this->loop->stop();
                });
            })->otherwise(function ($e) use (&$response) {
                $response['message'] = 'Failed to fetch keys: ' . $e->getMessage();
                $this->loop->stop();
            });
        })->otherwise(function ($e) use (&$response) {
            $response['message'] = 'Failed to connect to Redis: ' . $e->getMessage();
            $this->loop->stop();
        });

        $this->loop->run();

        return $this->respond($response);
    }
    
    /**
     * Updates an existing coaster record in Redis.
     */
    public function store($id = null)
    {
        if ($id === null) {
            return $this->fail('Invalid ID provided.');
        }

        $allowed = ['personel_quantity', 'customer_quantity', 'time_from', 'time_to'];

        $input = $this->request->getJSON(true);
        $input = collect($input)->filter(fn($value, $key) => in_array($key, $allowed))->all();

        $validation = Services::validation();

        $rules = [
            'personel_quantity' => 'required|integer|positive_number',
            'customer_quantity' => 'required|integer|positive_number',
            'time_from' => 'required|valid_time',
            'time_to' => 'required|valid_time|valid_time_period[' . $input['time_from'] . ']'
        ];

        if (!$this->validate($rules)) {
            return $this->fail($validation->getErrors());
        }

        $this->setRedisConnection();
        
        $response = ['status' => 'error', 'message' => 'Failed to update record.'];

        $this->clientPromise->then(function ($client) use ($id, $input, &$response) {
            $key = self::QUEUE_PREFIX .':'. $id;
            $client->exists($key)->then(function ($exists) use ($client, $key, $input, &$response, $id) {
                if (!$exists) {
                    $response['message'] = 'Record not found.';
                    $this->loop->stop();
                    return;
                }

                $client->set($key, json_encode($input))
                    ->then(function () use ($id, &$response) {
                        $response = ['status' => 'success', 'message' => 'Record updated.', 'updated_id' => $id];
                        $this->loop->stop();
                    });
            });
        });
        
        $this->loop->run();

        return $this->respond($response);
    }

    /**
     * Deletes a coaster record from Redis.
     */
    public function delete($id = null)
    {
        if ($id === null) {
            return $this->fail('Invalid ID provided.');
        }

        $this->setRedisConnection();

        $response = ['status' => 'error', 'message' => 'Failed to delete record.'];
        $coasterKey = self::QUEUE_PREFIX . ':' . $id;
        $wagonPattern = self::QUEUE_PREFIX . ':' . $id . ':' . WagonController::WAGON_PREFIX . ':*';

        $this->clientPromise->then(function ($client) use ($coasterKey, $wagonPattern, $id, &$response) {
            $client->keys($wagonPattern)->then(function ($wagonKeys) use ($client, $coasterKey, $id, &$response) {
                if (!empty($wagonKeys)) {
                    $client->del(...$wagonKeys)->then(function () use ($client, $coasterKey, $id, &$response) {
                        $this->deleteCoaster($client, $coasterKey, $id, $response);
                    });
                } else {
                    $this->deleteCoaster($client, $coasterKey, $id, $response);
                }
            })->otherwise(function ($e) use (&$response) {
                $response['message'] = 'Failed to fetch wagons: ' . $e->getMessage();
                $this->loop->stop();
            });
        });

        $this->loop->run();

        return $this->respond($response);
    }

    /**
     * Helper function to delete coaster after wagons are removed.
     */
    private function deleteCoaster($client, $coasterKey, $id, &$response)
    {
        $client->del($coasterKey)->then(function ($result) use ($id, &$response) {
            $response = $result ?
                ['status' => 'success', 'message' => 'Coaster and its wagons deleted.', 'deleted_id' => $id]
                :
                ['status' => 'error', 'message' => 'Coaster not found.'];
            $this->loop->stop();
        });
    }
}
