<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\Customer;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;

class CustomerController extends AbstractController implements PullInterface
{
    public function pull(QueryFilter $queryFilter): array
    {
        return [];
    }
}