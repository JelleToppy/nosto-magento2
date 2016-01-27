<?php

namespace Nosto\Tagging\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Nosto\Tagging\Helper\Data as DataHelper;

/** @noinspection PhpIncludeInspection */
require_once 'app/code/Nosto/Tagging/vendor/nosto/php-sdk/autoload.php';

/**
 * Meta data block for outputting <meta> elements in the page <head>.
 * This block should be included on all pages.
 */
class Meta extends Template
{
    /**
     * @inheritdoc
     */
    protected $_template = 'meta.phtml';

    /**
     * @var DataHelper the module data helper.
     */
    protected $_dataHelper;

    /**
     * Constructor.
     *
     * @param Context    $context the context.
     * @param DataHelper $dataHelper the data helper.
     * @param array      $data optional data.
     */
    public function __construct(
        Context $context,
        DataHelper $dataHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->_dataHelper = $dataHelper;
    }

    /**
     * Returns the module version number.
     *
     * @return string the module version number.
     */
    public function getModuleVersion()
    {
        // todo
        return 'todo';
    }

    /**
     * Returns the unique installation ID.
     *
     * @return string the unique ID.
     */
    public function getInstallationId()
    {
        return $this->_dataHelper->getInstallationId();
    }

    /**
     * Returns the current stores language code in ISO 639-1 format.
     *
     * @return string the language code.
     */
    public function getLanguageCode()
    {
        $store = $this->_storeManager->getStore(true);
        return substr($store->getConfig('general/locale/code'), 0, 2);
    }
}
