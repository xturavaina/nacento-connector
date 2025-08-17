<?php
declare(strict_types=1);

namespace Nacento\Connector\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;

class PingKey extends Field
{
    private const PREFIX = 'media/catalog/product/';

    private Escaper $escaper;

    public function __construct(
        Context $context,
        Escaper $escaper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->escaper = $escaper;

        // opcional: CSS petit per al prefix
        $this->pageConfig->addPageAsset('Nacento_Connector::css/pingkey.css');
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $name  = $element->getName();
        $id    = $element->getHtmlId();
        $value = (string)$element->getValue();

        // Mostra només la "cua": treu "pub/media/catalog/product/" o "media/catalog/product/"
        $tail = preg_replace('#^/?(pub/)?media/catalog/product/#', '', $value);

        $html  = '<div class="admin__control-grouped nacento-pingkey">';
        $html .= '<span class="admin__addon-prefix">' . self::PREFIX . '</span>';
        $html .= '<input id="' . $this->escaper->escapeHtmlAttr($id) . '"'
              .  ' class="admin__control-text" type="text"'
              .  ' name="' . $this->escaper->escapeHtmlAttr($name) . '"'
              .  ' value="' . $this->escaper->escapeHtmlAttr($tail) . '"/>';
        $html .= '</div>';

        return $html;
    }
}
