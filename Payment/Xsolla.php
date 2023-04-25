<?php

namespace WH1\PaygateXsolla\Payment;

use Exception;
use XF;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use Xsolla\SDK\API\PaymentUI\TokenRequest;
use Xsolla\SDK\API\XsollaClient;

class Xsolla extends AbstractProvider
{
	public function getTitle(): string
	{
		return 'Xsolla';
	}

	public function getApiEndpoint()
	{
		if (!XF::config('enableLivePayments'))
		{
			return 'https://sandbox-secure.xsolla.com';
		}

		return 'https://secure.xsolla.com';
	}

	public function verifyConfig(array &$options, &$errors = []): bool
	{
		if (empty($options['merchant_id']) || empty($options['project_id']) || empty($options['secret_key']) || empty($options['api_key']))
		{
			$errors[] = XF::phrase('wh1_xsolla_you_must_provide_all_data');
		}

		return !$errors;
	}

	public function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase): array
	{
		$options = $purchaseRequest->PaymentProfile->options;

		$tokenRequest = new TokenRequest((int)$options['project_id'], (string)$purchase->purchaser->user_id);
		$tokenRequest->setUserEmail($purchase->extraData['email'] ?? $purchase->purchaser->email)
			->setExternalPaymentId((string)$purchaseRequest->purchase_request_id)
			->setUserName($purchase->purchaser->username)
			->setPurchase((float)$purchase->cost, $purchase->currency)
			->setCustomParameters([
				'title'       => $purchase->title,
				'request_key' => $purchaseRequest->request_key
			]);

		$tokenRequest->setSandboxMode(!XF::config('enableLivePayments'));

		return [
			'token_request' => $tokenRequest
		];
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): XF\Mvc\Reply\Redirect
	{
		$options = $purchaseRequest->PaymentProfile->options;

		$paymentParams = $this->getPaymentParams($purchaseRequest, $purchase);

		$xsollaClient = XsollaClient::factory([
			'merchant_id' => $options['merchant_id'],
			'api_key'     => $options['api_key']
		]);

		$token = $xsollaClient->createPaymentUITokenFromRequest($paymentParams['token_request']);

		return $controller->redirect($this->getApiEndpoint() . "/paystation3/?access_token={$token}");
	}

	public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase): ?XF\Mvc\Reply\View
	{
		$state = $this->setupCallback($controller->request());

		if ($this->validateCallback($state)
			&& $this->validateTransaction($state)
			&& $this->validatePurchaseRequest($state)
			&& $this->validatePurchasableHandler($state)
			&& $this->validatePaymentProfile($state)
			&& $this->validatePurchaser($state)
			&& $this->validatePurchasableData($state)
			&& $this->validateCost($state)
		)
		{
			$this->setProviderMetadata($state);
			$this->getPaymentResult($state);
			$this->completeTransaction($state);
		}

		if ($state->logType)
		{
			try
			{
				$this->log($state);
			}
			catch (Exception $e)
			{
				XF::logException($e, false, 'Error logging payment to payment provider: ');
			}
		}

		return $this->getResponse($controller, $state->logMessage, $state->logType != 'error' ? 'result' : $state->logType);
	}

	protected function getResponse(Controller $controller, $message = '', $type = 'result'): XF\Mvc\Reply\View
	{
		$reply = $controller->view();

		$reply->setResponseType('json');

		$reply->setJsonParams([
			$type => [
				'message' => $message
			]
		]);

		return $reply;
	}

	public function setupCallback(Request $request): CallbackState
	{

		$state = new CallbackState();

		$state->input = @json_decode($request->getInputRaw(), true);

		$state->ip = $request->getIp();

		$state->httpCode = 200;

		return $state;
	}

	public function validateTransaction(CallbackState $state): bool
	{
		$state->transactionId = $state->input['transaction']['id'];
		$state->requestKey = $state->input['custom_parameters']['request_key'];

		if (!$state->requestKey)
		{
			$state->logType = 'info';
			$state->logMessage = 'Metadata is empty!';

			return false;
		}

		return parent::validateTransaction($state);
	}

	public function validateCost(CallbackState $state): bool
	{
		$purchaseRequest = $state->purchaseRequest;
		$purchaseCost = $state->input['purchase']['total']['amount'];
		$purchaseCurrency = $state->input['purchase']['total']['currency'];

		if (($purchaseCurrency == $purchaseRequest->cost_currency)
			&& round($purchaseCost, 2) == round($purchaseRequest->cost_amount, 2))
		{
			return true;
		}

		$state->logType = 'error';
		$state->logMessage = 'Invalid cost amount.';

		return false;
	}

	public function getPaymentResult(CallbackState $state): void
	{
		$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
	}

	public function prepareLogData(CallbackState $state): void
	{
		$state->logDetails = array_merge($state->input, [$state->ip]);
	}

	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
	{
		$result = self::ERR_NO_RECURRING;

		return false;
	}
}