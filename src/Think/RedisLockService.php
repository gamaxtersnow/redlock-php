<?php
namespace RedisLock\Think;

use RedisLock\Lock;
use think\Service;
class RedisLockService extends Service
{
    public function register(): void
    {
        $config = config('lock');
        $this->app->bind('lock', new Lock($config));
    }

    public function boot()
    {
        // 服务启动
    }
}


