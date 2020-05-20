<?php

declare(strict_types=1);

namespace PayonePayment\Components\DependencyInjection\Factory;

use PayonePayment\Components\Exception\NoPaymentHandlerFoundException;
use PayonePayment\Components\RequestHandler\AbstractRequestHandler;

class RequestHandlerFactory
{
    /** @var AbstractRequestHandler[]|iterable */
    protected $requestHandlerCollection = [];

    public function __construct(iterable $requestHandlerCollection)
    {
        $this->requestHandlerCollection = $requestHandlerCollection;
    }

    public function getRequestHandler(string $paymentMethodId, string $orderNumber): AbstractRequestHandler
    {
        foreach ($this->requestHandlerCollection as $requestHandler) {
            if (!empty($paymentMethodId) && $requestHandler->supports($paymentMethodId)) {
                return $requestHandler;
            }
        }

        throw new NoPaymentHandlerFoundException($orderNumber);
    }
}