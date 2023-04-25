<?php

namespace WH1\PaygateXsolla\XF\Pub\Controller;

use Exception;
use XF;
use XF\Mvc\ParameterBag;
use Xsolla\SDK\Webhook\Message\Message;
use Xsolla\SDK\Webhook\Message\PaymentMessage;
use Xsolla\SDK\Webhook\Message\UserSearchMessage;
use Xsolla\SDK\Webhook\Message\UserValidationMessage;
use Xsolla\SDK\Webhook\WebhookAuthenticator;
use Xsolla\SDK\Webhook\WebhookRequest;

class Purchase extends XFCP_Purchase
{
	public function checkCsrfIfNeeded($action, ParameterBag $params): void
	{
		$i = strtolower($action);
		if ($i == 'process')
		{
			return;
		}

		parent::checkCsrfIfNeeded($action, $params);
	}

	public function actionProcess()
	{
		if ($this->filter('_xfProvider', 'str') == 'wh1Xsolla')
		{
			$this->setResponseType('json');

			$input = @json_decode($this->request->getInputRaw(), true);
			if (empty($input['settings']))
			{
				return $this->error('Settings in request are empty!', 422);
			}

			$paymentProfile = $this->assertPaymentProfile($input['settings']['project_id'], $input['settings']['merchant_id']);
			$projectSecretKey = $paymentProfile->options['secret_key'];

			$webhookAuthenticator = new WebhookAuthenticator($projectSecretKey);
			$webhookRequest = new WebhookRequest(
				['authorization' => $this->request->getServer('HTTP_AUTHORIZATION')],
				$this->request->getInputRaw(),
				$this->request->getIp()
			);
			$message = Message::fromArray($webhookRequest->toArray());

			try
			{
				$webhookAuthenticator->authenticate($webhookRequest, false);
			}
			catch (Exception $e)
			{
				throw $this->exception(
					$this->getErrorReply(400, $e->getMessage(), 'INVALID_SIGNATURE')
				);
			}

			switch ($message->getNotificationType())
			{
				case Message::USER_SEARCH:
					$user = XF::em()->find('XF:User', $message->getUserPublicId());
					if (!$user)
					{
						throw $this->exception(
							$this->getErrorReply(404, 'User not found!', 'INVALID_USER')
						);
					}
					return $this->getSuccessReply(['user' => ['id' => $user->user_id]]);
				case Message::USER_VALIDATION:
					$userId = $message->getUserId();
					if (!XF::em()->find('XF:User', $userId))
					{
						throw $this->exception(
							$this->getErrorReply(404, 'User not found!', 'INVALID_USER')
						);
					}
					return $this->message('success');
				case Message::PAYMENT:
					$purchaseRequest = $this->em()->findOne(
						'XF:PurchaseRequest',
						[
							'request_key' => $input['custom_parameters']['request_key'] ?? ''
						],
						'User'
					);
					if ($purchaseRequest)
					{
						$this->request->set('request_key', $purchaseRequest->request_key);
						return XF::asVisitor($purchaseRequest->User ?? XF::visitor(), function () {
							return parent::actionProcess();
						});
					}

					return $this->getSuccessReply();
				case Message::REFUND:
					return $this->message('Refund is not available', 204);
				default:
					throw $this->exception(
						$this->error('Notification type not implemented', 400)
					);
			}
		}

		return parent::actionProcess();
	}

	protected function assertPaymentProfile($projectId, $merchantId): ?XF\Entity\PaymentProfile
	{
		$paymentProfiles = XF::repository('XF:Payment')->findPaymentProfilesForList()->fetch();
		foreach ($paymentProfiles as $paymentProfile)
		{
			$options = $paymentProfile->options;
			if (!empty($options['project_id']) && !empty($options['merchant_id']))
			{
				if ($options['project_id'] == $projectId && $options['merchant_id'] == $merchantId)
				{
					return $paymentProfile;
				}
			}
		}

		throw $this->exception(
			$this->notFound('No payment profile for credentials!')
		);
	}

	protected function getSuccessReply($data = []): XF\Mvc\Reply\Message
	{
		$reply = $this->message('success');

		$reply->setJsonParams($data);

		return $reply;
	}

	protected function getErrorReply($code, $message, $type): XF\Mvc\Reply\Error
	{
		$reply = $this->error($message, $code);

		$reply->setJsonParams([
			'error' => [
				'code' => $type
			]
		]);

		return $reply;
	}
}
