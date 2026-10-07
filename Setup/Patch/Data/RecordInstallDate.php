<?php
namespace Gamma\Wallet\Setup\Patch\Data;

use Gamma\Wallet\Model\Gamma;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Orders placed before the module was installed never earn a reward. Shops upgrading from 1.0.0 count
 * from the upgrade: older orders keep the rewards they already have, and get no new ones.
 */
class RecordInstallDate implements DataPatchInterface
{
    public function __construct(private FlagManager $flagManager)
    {
    }

    public function apply(): self
    {
        if (!(int)$this->flagManager->getFlagData(Gamma::FLAG_INSTALLED_ON)) {
            $this->flagManager->saveFlag(Gamma::FLAG_INSTALLED_ON, time());
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
