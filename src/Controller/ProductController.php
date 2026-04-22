<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Logger\LoggerService;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use Psr\Log\LoggerInterface;

class ProductController extends AbstractController implements DeleteInterface
{
    /*****************************************************
     * We only use ProductStockLevelController for push! *
     *****************************************************/

    public function __construct(CoreConfigInterface $config, LoggerInterface $logger, LoggerService $loggerService)
    {
        parent::__construct($config, $logger, $loggerService);
    }

    public function push(AbstractModel ...$models): array
    {
        return $models;
    }

    /**
     * @inheritDoc
     */
    public function delete(AbstractModel ...$models): array
    {
        return $models;
    }
}