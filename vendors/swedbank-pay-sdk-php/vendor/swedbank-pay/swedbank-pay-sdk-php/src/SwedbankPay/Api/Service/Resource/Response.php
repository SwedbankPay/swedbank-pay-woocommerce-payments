<?php

namespace SwedbankPay\Api\Service\Resource;

use SwedbankPay\Api\Service\Resource;
use SwedbankPay\Api\Service\Resource\Collection\OperationsCollection;
use SwedbankPay\Api\Service\Resource\Data\ResponseInterface;

/**
 * Base Class for data response objects
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
class Response extends Resource implements ResponseInterface
{
    /**
     * @return OperationsCollection
     */
    public function getOperations()
    {
        return $this->offsetGet(self::OPERATIONS);
    }

    /**
     * @param OperationsCollection|array $operations
     * @return $this
     */
    public function setOperations($operations)
    {
        if (!($operations instanceof OperationsCollection)) {
            $operations = new OperationsCollection($operations);
        }

        return $this->offsetSet(self::OPERATIONS, $operations);
    }
}
