<?php

namespace SwedbankPay\Api\Service\Paymentorder\Resource;

use SwedbankPay\Api\Service\Payment\Resource\Data\PaymentInterface;
use SwedbankPay\Api\Service\Paymentorder\Resource\Data\PaymentObjectInterface;
use SwedbankPay\Api\Service\Resource;

/**
 * Payment object
 */
class PaymentObject extends Resource implements PaymentObjectInterface
{

    /**
     * @return PaymentInterface
     */
    public function getPayment()
    {
        return $this->offsetGet(self::PAYMENT);
    }

    /**
     * @param PaymentInterface $payment
     * @return $this
     */
    public function setPayment($payment)
    {
        return $this->offsetSet(self::PAYMENT, $payment);
    }
}
