<?php

namespace fkooman\VPN\Server;

class Pools
{
    /** @var array */
    private $pools;

    public function __construct(array $poolsData)
    {
        // XXX listen cannot be the same for different Pool
        // XXX also, if there is more than one pool, listen cannot be '::' or 0.0.0.0

        $this->pools = [];

        $i = 0;
        foreach ($poolsData as $poolName => $poolData) {
            $poolData['name'] = $poolName;
            $this->pools[] = new Pool($i, $poolData);
            ++$i;
        }
    }

    public function getPools()
    {
        return $this->pools;
    }

    public function getManagementSockets()
    {
        $managementSockets = [];

        $i = 0;
        foreach ($this->pools as $pool) {
            foreach ($pool->getInstances() as $instance) {
                $managementSockets[sprintf('%s-%d', $pool->getName(), $i)] = sprintf('tcp://%s:%d', $pool->getManagementIp(), $instance->getManagementPort());
                ++$i;
            }
        }

        return $managementSockets;
    }

    public function getInfo()
    {
        $poolInfo = [];

        foreach ($this->pools as $pool) {
            $routesArray = [];
            foreach ($pool->getRoutes() as $route) {
                $routesArray[] = $route->getRange();
            }

            $poolInfo[] = [
                'name' => $pool->getName(),
                'range' => $pool->getRange()->getRange(),
                'range6' => $pool->getRange6()->getRange(),
                'defaultGateway' => $pool->getDefaultGateway(),
#                'hostName' => $pool->getHostName(),
                'connectInfo' => $pool->getConnectInfo(),
                'dns' => $pool->getDns(),
                'routes' => $routesArray,
                'twoFactor' => $pool->getTwoFactor(),
                'clientToClient' => $pool->getClientToClient(),
                'numberOfInstances' => count($pool->getInstances()),    // XXX optimize!
            ];
        }

        return $poolInfo;
    }
}
