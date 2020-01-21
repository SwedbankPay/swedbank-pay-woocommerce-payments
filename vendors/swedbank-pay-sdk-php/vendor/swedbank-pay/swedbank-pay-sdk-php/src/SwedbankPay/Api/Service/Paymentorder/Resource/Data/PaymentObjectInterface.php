<?php

namespace SwedbankPay\Api\Service\Paymentorder\Resource\Data;

use SwedbankPay\Api\Service\Data\ResourceInterface;
use SwedbankPay\Api\Service\Paymentorder\Resource\Request\Data\PaymentorderInterface;

/**
 * Payment object interface
 *
 * @api
 */
interface PaymentObjectInterface extends ResourceInterface
{
    const PAYMENT = 'payment';

    /**
     * @return PaymentorderInterface
     */
    public function getPayment();

    /**
     * @param PaymentorderInterface $payment
     * @return $this
     */
    public function setPayment($payment);
}
