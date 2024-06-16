<?php

class LogMonitoring
{
    public function check(): array
    {
        return [
            'server_time' => $this->serverTime(),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'network' => $this->network(),
            'storage' => $this->storage(),
            'databases' => [
                'redis' => $this->redis(),
                'mongodb' => $this->mongoDB(),
                'mysql' => $this->mySQL(),
            ],
        ];
    }

    protected function serverTime(): array
    {
        return [
            'current_time' => trim(exec('date +"%Y-%m-%d %H:%M"'))
        ];
    }

    protected function cpu(): array
    {
        $cpuCores = (int)trim(exec('nproc'));
        $load = explode(' ', trim(exec('cat /proc/loadavg')));
        $cpuUsage = ($load[0] / $cpuCores) * 100;
        $coresUsed = min($cpuCores, round($load[0]));
        return [
            'total' => $cpuCores,
            'usage' => $coresUsed,
            'percentage_used' => round($cpuUsage, 0)
        ];
    }

    protected function memory(): array
    {
        $memoryInfo = trim(exec("free -k | grep Mem"));
        if (!$memoryInfo) {
            return [
                'total' => 'unknown',
                'usage' => 'unknown',
                'percentage_used' => 'unknown'
            ];
        }
        $memoryInfo = preg_split('/\s+/', $memoryInfo);
        $totalMemory = $memoryInfo[1] * 1024;
        $usedMemory = $memoryInfo[2] * 1024;
        $ramUsagePercentage = ($usedMemory / $totalMemory) * 100.0;
        return [
            'total' => $this->bytesToHuman($totalMemory),
            'usage' => $this->bytesToHuman($usedMemory),
            'percentage_used' => round($ramUsagePercentage, 0)
        ];
    }

    protected function network(): array
    {
        $pingCommand = 'ping -c 1 google.com';
        $pingResult = trim(exec($pingCommand));
        $networkConnection = strpos($pingResult, '1 received') !== false ? 'connected' : 'disconnected';
        $networkUsage = trim(exec("cat /proc/net/dev | grep 'eth0'"));
        $columns = preg_split('/\s+/', $networkUsage);
        if (count($columns) >= 17) {
            return [
                'status' => $networkConnection,
                'download' => $this->bytesToHuman($columns[1]),
                'upload' => $this->bytesToHuman($columns[9])
            ];
        }
        return [
            'status' => $networkConnection,
            'download' => 'unknown',
            'upload' => 'unknown'
        ];
    }

    protected function storage(): array
    {
        $totalSpace = trim(exec('df --block-size=1 / | tail -1 | awk \'{print $2}\''));
        $usedSpace = trim(exec('df --block-size=1 / | tail -1 | awk \'{print $3}\''));
        $percentageUsed = ($usedSpace / $totalSpace) * 100;
        $swapInfo = $this->getSwapInfo();
        return [
            'disk_total' => $this->bytesToHuman($totalSpace),
            'disk_usage' => $this->bytesToHuman($usedSpace),
            'disk_percentage_used' => round($percentageUsed, 0),
            'swap_total' => $swapInfo['total'],
            'swap_usage' => $swapInfo['usage'],
            'swap_percentage_used' => $swapInfo['percentage_used'],
        ];
    }

    protected function getSwapInfo(): ?array
    {
        $swapInfoOutput = trim(exec('free -k | grep Swap'));
        if (empty($swapInfoOutput)) {
            return [
                'total' => 0,
                'usage' => 0,
                'percentage_used' => 0,
            ];
        }
        $swapInfo = preg_split('/\s+/', $swapInfoOutput);
        if (count($swapInfo) < 3) {
            return [
                'total' => 0,
                'usage' => 0,
                'percentage_used' => 0,
            ];
        }
        $totalSwap = $swapInfo[1] * 1024;
        $usedSwap = $swapInfo[2] * 1024;
        if ($totalSwap == 0) {
            return [
                'total' => 0,
                'usage' => 0,
                'percentage_used' => 0,
            ];
        }
        $percentageUsed = ($usedSwap / $totalSwap) * 100;
        return [
            'total' => $this->bytesToHuman($totalSwap),
            'usage' => $this->bytesToHuman($usedSwap),
            'percentage_used' => round($percentageUsed, 0),
        ];
    }

    protected function redis(): array
    {
        $redisStatusCommand = "docker ps --filter 'name=redis' --filter 'status=running' --format '{{.Names}}'";
        $redisStatusOutput = exec($redisStatusCommand);
        $redisStatus = trim($redisStatusOutput);
        if (!empty($redisStatus)) {
            $redisContainers = explode("\n", $redisStatus);
            foreach ($redisContainers as $container) {
                $container = trim($container);
                if (empty($container)) continue;
                $redisCliPing = trim(exec("docker exec $container redis-cli ping"));
                if ($redisCliPing === 'PONG') return ['status' => 'running', 'message' => "Redis container is active and running"];
            }
            return ['status' => 'not active', 'message' => 'Redis containers are running but none responded to ping'];
        }
        $redisCliPing = trim(exec("redis-cli ping"));
        if ($redisCliPing === 'PONG') return ['status' => 'running', 'message' => "Redis service is active and running on Ubuntu"];
        return ['status' => 'not running', 'message' => 'Redis service is not running on Docker or Ubuntu'];
    }

    protected function mongoDB(): array
    {
        $mongoStatusCommand = "docker ps --filter 'name=mongodb' --filter 'status=running' --format '{{.Names}}'";
        $mongoStatusOutput = exec($mongoStatusCommand);
        $mongoStatus = trim($mongoStatusOutput);
        if (!empty($mongoStatus)) {
            $mongoPing = trim(exec("docker exec $mongoStatus mongo --eval 'db.runCommand({ping: 1})' --quiet"));
            if (strpos($mongoPing, '"ok" : 1') !== false) return ['status' => 'running', 'message' => "MongoDB container is active and running"];
            return ['status' => 'not active', 'message' => 'MongoDB container is running but not responding to ping'];
        }
        $mongoPing = trim(exec("mongo --eval 'db.runCommand({ping: 1})' --quiet"));
        if (strpos($mongoPing, '"ok" : 1') !== false) return ['status' => 'running', 'message' => "MongoDB service is active and running on Ubuntu"];
        return ['status' => 'not running', 'message' => 'MongoDB service is not running on Docker or Ubuntu'];
    }

    protected function mySQL(): array
    {
        $mysqlStatusCommand = "docker ps --filter 'name=mysql' --filter 'status=running' --format '{{.Names}}'";
        $mysqlStatusOutput = exec($mysqlStatusCommand);
        $mysqlStatus = trim($mysqlStatusOutput);
        if (!empty($mysqlStatus)) {
            $mysqlPing = trim(exec("docker exec mysql mysqladmin --user=root --password=mZZbq0AsUeeukmet --silent ping"));
            if (strpos($mysqlPing, 'mysqld is alive') !== false) return ['status' => 'running', 'message' => "MySQL container is active and running"];
            return ['status' => 'not active', 'message' => 'MySQL containers are running but none responded to ping'];
        }
        $mysqlPing = trim(exec("mysqladmin --user=root --password=mZZbq0AsUeeukmet --silent ping"));
        if (strpos($mysqlPing, 'mysqld is alive') !== false) return ['status' => 'running', 'message' => "MySQL service is active and running on Ubuntu"];
        return ['status' => 'not running', 'message' => 'MySQL service is not running on Docker or Ubuntu'];
    }

    protected function bytesToHuman($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / pow(1024, $power), 2, '.', ',') . $units[$power];
    }
}

header('Content-Type: application/json');
$logMonitoring = new LogMonitoring();
echo json_encode($logMonitoring->check(), JSON_PRETTY_PRINT);
