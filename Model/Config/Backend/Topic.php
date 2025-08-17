<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

class Topic extends Value
{
    public function beforeSave()
    {
        $val = trim((string)$this->getValue());
        // Deixem buit si l’usuari no posa res (faràs servir DEFAULT_TOPIC)
        $this->setValue($val);
        return parent::beforeSave();
    }
}