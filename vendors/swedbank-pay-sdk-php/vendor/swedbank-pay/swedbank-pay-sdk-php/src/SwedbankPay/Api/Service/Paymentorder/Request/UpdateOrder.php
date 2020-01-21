<?php

namespace SwedbankPay\Api\Service\Paymentorder\Request;

use SwedbankPay\Api\Service\Request;

class UpdateOrder extends Request
{
    public function setup()
    {
        $this->setRequestMethod('PATCH');
        $this->setRequestEndpoint('/psp/paymentorders');
        $this->setServiceOperation('UpdateOrder');
    }

    /**
     * @param string $paymentId
     */
    public function setPaymentId($paymentId)
    {
        return $this->setRequestEndpoint($paymentId);
    }
}
