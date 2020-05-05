<?php

namespace SwedbankPay\Core\Library;

use SwedbankPay\Core\Api\Transaction;

trait TransactionAction
{
    /**
     * Save Transaction Data.
     *
     * @param mixed $orderId
     * @param array $transactionData
     */
    public function saveTransaction($orderId, $transactionData = [])
    {
        if ($transactionData instanceof Transaction) {
            $transactionData = $transactionData->toArray();
        }

        $this->adapter->saveTransaction($orderId, $transactionData);
    }

    /**
     * Save Transactions Data.
     *
     * @param mixed $orderId
     * @param array $transactions
     */
    public function saveTransactions($orderId, array $transactions)
    {
        foreach ($transactions as $transactionData) {
            $this->adapter->saveTransaction($orderId, $transactionData);
        }
    }

    /**
     * Find Transaction.
     *
     * @param string $field
     * @param mixed $value
     *
     * @return bool|Transaction
     */
    public function findTransaction($field, $value)
    {
        $transaction = $this->adapter->findTransaction($field, $value);

        if (!$transaction) {
            return false;
        }

        return new Transaction($transaction);
    }
}
