<?php

namespace SwedbankPay\Api\Service\Paymentorder\Request;

use SwedbankPay\Api\Service\Request;

class CurrentPayment extends Request
{
    public function setup()
    {
        $this->setRequestMethod('GET');
    }

    /**
     * @param string $paymentId
     */
    public function setPaymentId($paymentId)
    {
        return $this->setRequestEndpoint($paymentId);
    }
}
