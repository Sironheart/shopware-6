<?php

declare(strict_types=1);

namespace PayonePayment\PaymentHandler;

use PayonePayment\Components\ConfigReader\ConfigReaderInterface;
use PayonePayment\Components\DataHandler\Transaction\TransactionDataHandlerInterface;
use PayonePayment\Components\TransactionStatus\TransactionStatusService;
use PayonePayment\Installer\CustomFieldInstaller;
use PayonePayment\Payone\Client\Exception\PayoneRequestException;
use PayonePayment\Payone\Client\PayoneClientInterface;
use PayonePayment\Payone\Request\Prepayment\PrepaymentPreAuthorizeRequestFactory;
use PayonePayment\Struct\PaymentTransaction;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

class PayonePrepaymentPaymentHandler extends AbstractPayonePaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var PrepaymentPreAuthorizeRequestFactory */
    private $preAuthRequestFactory;

    /** @var PayoneClientInterface */
    private $client;

    /** @var TranslatorInterface */
    private $translator;

    /** @var TransactionDataHandlerInterface */
    private $dataHandler;

    public function __construct(
        ConfigReaderInterface $configReader,
        PrepaymentPreAuthorizeRequestFactory $preAuthRequestFactory,
        PayoneClientInterface $client,
        TranslatorInterface $translator,
        TransactionDataHandlerInterface $dataHandler,
        EntityRepositoryInterface $lineItemRepository,
        RequestStack $requestStack
    ) {
        parent::__construct($configReader, $lineItemRepository, $requestStack);
        $this->preAuthRequestFactory = $preAuthRequestFactory;
        $this->client                = $client;
        $this->translator            = $translator;
        $this->dataHandler           = $dataHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $requestData = $this->fetchRequestData();

        $paymentTransaction = PaymentTransaction::fromSyncPaymentTransactionStruct($transaction, $transaction->getOrder());

        $request = $this->preAuthRequestFactory->getRequestParameters(
            $paymentTransaction,
            $requestData,
            $salesChannelContext
        );

        try {
            $response = $this->client->request($request);
        } catch (PayoneRequestException $exception) {
            throw new SyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getResponse()['error']['CustomerMessage']
            );
        } catch (Throwable $exception) {
            throw new SyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $this->translator->trans('PayonePayment.errorMessages.genericError')
            );
        }

        if (empty($response['status']) || $response['status'] === 'ERROR') {
            throw new SyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $this->translator->trans('PayonePayment.errorMessages.genericError')
            );
        }

        $data = $this->prepareTransactionCustomFields($request, $response, array_merge(
            $this->getBaseCustomFields($response['status']),
            [
                // Set clearing type explicitly
                // todo: evaluate moving this to parent::getBaseCustomFields()
                CustomFieldInstaller::CLEARING_TYPE => static::PAYONE_CLEARING_VOR,

                // Store clearing bank account information as custom field of the transaction in order to
                // use this data for payment instructions of an invoice or similar.
                // See: https://docs.payone.com/display/public/PLATFORM/How+to+use+JSON-Responses#HowtouseJSON-Responses-JSON,Clearing-Data
                CustomFieldInstaller::CLEARING_BANK_ACCOUNT => array_merge(array_filter($response['clearing']['BankAccount'] ?? []), [
                    // The PAYONE transaction ID acts as intended purpose of the transfer.
                    // We add this field explicitly here to make clear that the transaction ID is used
                    // as payment reference in context of the prepayment.
                    'Reference' => (string) $response['txid'],
                ]),
            ]
        ));

        $this->dataHandler->saveTransactionData($paymentTransaction, $salesChannelContext->getContext(), $data);
        $this->dataHandler->logResponse($paymentTransaction, $salesChannelContext->getContext(), ['request' => $request, 'response' => $response]);
    }

    /**
     * {@inheritdoc}
     */
    public static function isCapturable(array $transactionData, array $customFields): bool
    {
        // Prepayment is always pre-authorization

        $txAction = strtolower($transactionData['txaction'] ?? '');
        $txStatus = strtolower($transactionData['transaction_status'] ?? '');

        $isAppointed = $txAction === TransactionStatusService::ACTION_APPOINTED && $txStatus === TransactionStatusService::STATUS_COMPLETED;
        $isUnderpaid = $txAction === TransactionStatusService::ACTION_UNDERPAID;
        $isPaid      = $txAction === TransactionStatusService::ACTION_PAID;

        return $isAppointed || $isUnderpaid || $isPaid;
    }

    /**
     * {@inheritdoc}
     */
    public static function isRefundable(array $transactionData, array $customFields): bool
    {
        if (strtolower($transactionData['txaction']) === TransactionStatusService::ACTION_CAPTURE && (float) $transactionData['receivable'] !== 0.0) {
            return true;
        }

        return strtolower($transactionData['txaction']) === TransactionStatusService::ACTION_PAID;
    }
}