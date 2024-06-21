<?php
namespace RedisLock;
use RedisException;

class Lock
{
    private int $retryDelay;
    private int $retryCount;
    private float $clockDriftFactor = 0.01;

    private int $quorum;

    private array $servers = [];
    private array $instances = [];

    function __construct(array $servers, int $retryDelay = 200, int $retryCount = 3)
    {
        $this->servers = $servers;

        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;

        $this->quorum  = min(count($servers), (count($servers) / 2 + 1));
    }

    /**
     * @throws RedisException
     */
    public function lock($resource, $ttl): false|array
    {
        $this->initInstances();

        $token = uniqid();
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token'    => $token,
                ];

            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = mt_rand(floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);

            $retry--;

        } while ($retry > 0);

        return false;
    }

    /**
     * @throws RedisException
     */
    public function unlock(array $lock): void
    {
        $this->initInstances();
        $resource = $lock['resource'];
        $token    = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    /**
     * @throws RedisException
     */
    private function initInstances(): void
    {
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                $host = $server['host'];
                $port = $server['port'];
                $timeout = $server['timeout'];
                $password = $server['password']??"";
                $redis = new \Redis();
                $redis->connect($host, $port, $timeout);
                if(!empty($password)){
                    $redis->auth($password);
                }
                $this->instances[] = $redis;
            }
        }
    }

    private function lockInstance($instance, $resource, $token, $ttl)
    {
        return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
    }

    private function unlockInstance($instance, $resource, $token): void
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        $instance->eval($script, [$resource, $token], 1);
    }
}
