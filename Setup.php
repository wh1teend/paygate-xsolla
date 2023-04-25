<?php

namespace WH1\PaygateXsolla;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1(): void
	{
		$db = $this->db();

		$db->insert('xf_payment_provider', [
			'provider_id'    => "wh1Xsolla",
			'provider_class' => "WH1\\PaygateXsolla:Xsolla",
			'addon_id'       => "WH1/PaygateXsolla"
		]);
	}

	public function uninstallStep1(): void
	{
		$db = $this->db();

		$db->delete('xf_payment_profile', "provider_id = 'wh1Xsolla'");
		$db->delete('xf_payment_provider', "provider_id = 'wh1Xsolla'");
	}
}