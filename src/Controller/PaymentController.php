<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Payment;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;

class PaymentController extends AbstractController implements PullInterface, StatisticInterface
{
    public function statistic(QueryFilter $queryFilter): int
    {
        return 0;
    }

    public function pull(QueryFilter $queryFilter): array
    {
        return [];
    }
}