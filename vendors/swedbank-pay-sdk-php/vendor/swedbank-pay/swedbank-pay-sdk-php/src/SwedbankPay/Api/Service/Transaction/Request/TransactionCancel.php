<?php

namespace SwedbankPay\Api\Service\Transaction\Request;

use SwedbankPay\Api\Service\Request;

class TransactionCancel extends Request
{
    public function setup()
    {
        $this->setRequestMethod('POST');
        $this->setRequestEndpoint('/psp/paymentorders/%s/cancellations');
    }
}
