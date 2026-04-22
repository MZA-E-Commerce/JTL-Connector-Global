<?php

namespace Jtl\Connector\Core\Controller;

use JMS\Serializer\SerializerInterface;
use Jtl\Connector\Core\Application\Application;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Logger\LoggerService;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\ProductPrice;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Serializer\SerializerBuilder;
use Jtl\Connector\Core\Utilities\Validator\Validate;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractController
{
    /**
     * @var string
     */
    public const CUSTOMER_TYPE_B2C = 'c22d4b2da85e5c6154f1ec805b3405c9';

    public const PIMCORE_CUSTOMER_TYPE_B2C = 'B2C';

    /**
     * @var array
     */
    public const CUSTOMER_TYPE_MAPPINGS_REVERSE = [
        self::PIMCORE_CUSTOMER_TYPE_B2C => self::CUSTOMER_TYPE_B2C
    ];

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT = 'bulkSetProductsDataGlobal';

    /**
     * @var CoreConfigInterface
     */
    protected CoreConfigInterface $config;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var LoggerService
     */
    protected LoggerService $loggerService;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * Using direct dependencies for better testing and easier use with a DI container.
     *
     * AbstractController constructor.
     * @param CoreConfigInterface $config
     * @param LoggerInterface $logger
     * @param LoggerService $loggerService
     */
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger, LoggerService $loggerService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->loggerService = $loggerService;

        $this->serializer = SerializerBuilder::create()->build();
    }

    /**
     * Template‑Method for all Controllers
     *
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     * @throws \Exception
     */
    public function push(AbstractModel ...$models): array
    {
        // Always use bulk!

        $errors = [];

        $pushStartTime = microtime(true);

        $products = array_filter($models, fn($m) => $m instanceof Product);
        if (empty($products)) {
            return $models;
        }

        $this->loggerService->get('bulk')->info(sprintf(
            'BULK Push started: %d products.',
            count($products)
        ));

        // Get Pimcore Ids (bulk)
        $skuToProduct = [];
        foreach ($products as $product) {
            $skuToProduct[$product->getSku()] = $product;
        }

        $skus = array_keys($skuToProduct);
        $t0 = microtime(true);
        $pimcoreIds = $this->bulkGetPimcoreIds($skus);
        $this->loggerService->get('bulk')->info(sprintf(
            '[TIMING] bulkGetPimcoreIds (%d SKUs): %.3fs',
            count($skus), microtime(true) - $t0
        ));

        $this->loggerService->get('bulk')->info(sprintf(
            'BULK Got pimcore IDs: %s.',
            print_r($pimcoreIds, true)
        ));

        $existingProducts = [];
        $newProducts = [];

        foreach ($products as $product) {
            /**
             * @var $product Product
             */
            $sku = $product->getSku();

            if (isset($pimcoreIds[$sku]) && $pimcoreIds[$sku] > 0) {
                // Product exists -> Update
                $identity = new Identity($pimcoreIds[$sku], $product->getId()->getHost());
                $product->setId($identity);
                $existingProducts[] = $product;
            }
        }

        if (!empty($existingProducts)) {
            try {
                $t0 = microtime(true);
                $this->bulkUpdateProductsPimcore($existingProducts);
                $this->loggerService->get('bulk')->info(sprintf(
                    '[TIMING] bulkUpdateProductsPimcore (%d products, type=stockLevel): %.3fs',
                    count($existingProducts), microtime(true) - $t0
                ));
            } catch (\Throwable $e) {
                $this->loggerService->get('bulk')->error('BULK Update error: ' . $e->getMessage());
            }
        }

        $this->loggerService->get('bulk')->info(sprintf(
            '[TIMING] Controller::push() total: %.3fs',
            microtime(true) - $pushStartTime
        ));

        $this->loggerService->get('bulk')->info(sprintf(
            'BULK Push finished: %d successful, %d error(s)',
            count($products) - count($errors),
            count($errors)
        ));

        if (!empty($errors)) {
            $errorMessage = 'Errors occurred while processing models: ' . json_encode($errors);
            throw new \RuntimeException($errorMessage);
        }

        return $models;
    }

    /**
     * @param string $endpointKey
     * @return string
     */
    protected function getEndpointUrl(string $endpointKey): string
    {
        $apiKey = $this->config->get('pimcore.api.key');
        if (empty($apiKey)) {
            throw new \RuntimeException('Pimcore API key is not set');
        }

        $fullApiUrl = $this->config->get('pimcore.api.endpoints.' . $endpointKey . '.fullApiUrl');
        if (!empty($fullApiUrl)) {
            return $fullApiUrl;
        }

        return $this->config->get('pimcore.api.url') . $this->config->get('pimcore.api.endpoints.' . $endpointKey . '.url');
    }

    /**
     * @return HttpClientInterface
     */
    protected function getHttpClient(): HttpClientInterface
    {
        $client = HttpClient::create();
        return $client->withOptions([
            'headers' => [
                'X-API-KEY' => $this->config->get('pimcore.api.key'),
                'Accept' => 'application/json',
            ],
            'auth_basic' => [$this->config->get('pimcore.api.auth.username'), $this->config->get('pimcore.api.auth.password')]
        ]);
    }

    /**
     * @param array $skus
     * @return array Map from SKU => Pimcore-ID (0 if not found)
     * @throws \Exception
     */
    protected function bulkGetPimcoreIds(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $this->loggerService->get('bulk')->info('BULK Getting IDs for ' . count($skus) . ' SKUs.');

        $client = $this->getHttpClient();

        $fullApiUrl = $this->getEndpointUrl('bulkGetIds');
        $httpMethod = $this->config->get('pimcore.api.endpoints.bulkGetIds.method');

        try {
            $response = $client->request($httpMethod, $fullApiUrl, [
                'json' => ['skus' => $skus]
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode === 200 && isset($data['success']) && $data['success'] === true) {
                // Format: {"success": true, "ids":{"SKU1": 123, "SKU2": 456, "SKU3": 0}}
                return $data['ids'] ?? [];
            }

            $this->loggerService->get('bulk')->error('BULK GetIds API Error: ' . ($data['message'] ?? 'Unknown error'));
            return [];

        } catch (\Throwable $e) {
            $this->loggerService->get('bulk')->error('BULK GetIds HTTP error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array $products
     * @return void
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Throwable
     */
    protected function bulkUpdateProductsPimcore(array $products): void
    {
        if (empty($products)) {
            return;
        }

        $this->loggerService->get('bulk')->info('BULK Update (stockLevel): ' . count($products) . ' product(s)');

        $client = $this->getHttpClient();

        $fullApiUrl = $this->getEndpointUrl(self::UPDATE_TYPE_PRODUCT);
        $httpMethod = $this->config->get('pimcore.api.endpoints.' . self::UPDATE_TYPE_PRODUCT . '.method');

        // Prepare Products for Bulk-Request
        $bulkData = [];
        foreach ($products as $product) {
            /**
             * @var $product Product
             */
            $productData = [
                'id' => $product->getId()->getEndpoint(),
                'jtlId' => (string)$product->getId()->getHost(),
                'sku' => $product->getSku(),
                'stockLevel' => $product->getStockLevel(),
            ];

            $bulkData[] = $productData;
        }

        $jsonData = [
            'products' => $bulkData,
            'updateType' => self::UPDATE_TYPE_PRODUCT,
        ];

        $this->loggerService->get('bulk')->info(sprintf(
            'BULK post data to send: %s (%s)',
            json_encode($jsonData), $fullApiUrl
        ));

        try {
            $response = $client->request($httpMethod, $fullApiUrl, [
                'json' => $jsonData
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode === 200 && isset($data['success']) && $data['success'] === true) {
                $this->loggerService->get('bulk')->info(sprintf(
                    'BULK Update successful: %d updated, %d error(s)',
                    count($products),
                    $data['errors'] ?? 0
                ));
                return;
            }

            throw new \RuntimeException('BULK Update API Error: ' . ($data['error'] ?? 'Unknown error'));

        } catch (\Throwable $e) {
            $this->loggerService->get('bulk')->error('BULK Update error: ' . $e->getMessage());
            throw $e;
        }
    }
}