<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;

class StatusChangeController extends AbstractController implements PushInterface
{
    public function push(AbstractModel ...$models): array
    {
        return $models;
    }
}