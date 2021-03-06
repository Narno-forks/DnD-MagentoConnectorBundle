<?php

namespace DnD\Bundle\MagentoConnectorBundle\Writer\File;

use Pim\Bundle\CatalogBundle\Manager\MediaManager;
use Pim\Bundle\CatalogBundle\Model\AbstractProductMedia;
use DnD\Bundle\MagentoConnectorBundle\Helper\SFTPConnection;
use Symfony\Component\Validator\Constraints as Assert;
use Pim\Bundle\BaseConnectorBundle\Validator\Constraints\Channel;
use Pim\Bundle\CatalogBundle\Manager\ChannelManager;

/**
 *
 * @author    DnD Mimosa <mimosa@dnd.fr>
 * @copyright Agence Dn'D (http://www.dnd.fr)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
class CsvProductWriter extends CsvWriter
{
    /**
     * @param MediaManager $mediaManager
     */
    protected $mediaManager;

     /**
     * @var string
     */
    protected $imageFolderPath;

    /**
     * @var boolean
     */
    protected $exportImages;

    /**
     * @var string
     */
    protected $exportPriceOnly;

    /**
     * Assert\NotBlank(groups={"Execution"})
     * Channel
     *
     * @var string $channel Channel code
     */
    protected $channel;

    /**
     * @var ChannelManager
     */
    protected $channelManager;

    /**
     * @var array
     */
    protected $fixedDatas = array("family", "groups", "categories", "RELATED-groups", "RELATED-products");

    /**
     * @param MediaManager $mediaManager
     */
    public function __construct(MediaManager $mediaManager, $entityManager, ChannelManager $channelManager)
    {
        $this->mediaManager = $mediaManager;
        $this->entityManager = $entityManager;
        $this->channelManager = $channelManager;
    }

    /**
     * Set the configured channel
     *
     * @param string $channel
     */
    public function setChannel($channel)
    {
        $this->channel = $channel;
    }

    /**
     * Get the configured channel
     *
     * @return string
     */
    public function getChannel()
    {
        return $this->channel;
    }


    /**
     * Set exportImages
     *
     * @param string $imageFolderPath imageFolderPath
     *
     * @return string
     */
    public function setImageFolderPath($imageFolderPath)
    {
	    $this->imageFolderPath = $imageFolderPath;
    }

    /**
     * get image Folder Path
     *
     * @return string imageFolderPath
     */
    public function getImageFolderPath()
    {
	    return $this->imageFolderPath;
    }

    /**
     * get exportImages
     *
     * @return string exportImages
     */
    public function getExportImages()
    {
        return $this->exportImages;
    }

    /**
     * Set exportImages
     *
     * @param string $exportImages exportImages
     *
     * @return AbstractProcessor
     */
    public function setExportImages($exportImages)
    {
        $this->exportImages = $exportImages;

        return $this;
    }

    /**
     * get exportPriceOnly
     *
     * @return string exportPriceOnly
     */
    public function getExportPriceOnly()
    {
        return $this->exportPriceOnly;
    }

    /**
     * Set exportPriceOnly
     *
     * @param string $exportPriceOnly exportPriceOnly
     *
     * @return AbstractProcessor
     */
    public function setExportPriceOnly($exportPriceOnly)
    {
        $this->exportPriceOnly = $exportPriceOnly;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function write(array $items)
    {
        $products = [];

        if (!is_dir(dirname($this->getPath()))) {
            mkdir(dirname($this->getPath()), 0777, true);
        }

        foreach ($items as $item) {
            $item['product'] = $this->getProductPricesOnly($item['product']);
            $item['product'] = $this->formatMetricsColumns($item['product']);
            $products[] = ($this->getExportImages()) ? $item['product'] : $this->removeMediaColumns($item['product']);
            if($this->getExportImages()){
                foreach ($item['media'] as $media) {
                    if ($media) {
                        $this->sendMedia($media);
                    }
                }
            }
        }

        $this->items = array_merge($this->items, $products);
        $sftpConnection = new SFTPConnection($this->getHost(), $this->getPort());
        $sftpConnection->login($this->getUsername(), $this->getPassword());
        if(file_exists($this->getFilePath())){
	        $sftpConnection->uploadFile($this->getFilePath(), $this->getRemoteFilePath());
        }
    }

    /**
     * @param array|AbstractProductMedia $media
     * //TODO Add a "/" after the image folder path
     *
     * @return void
     */
    public function sendMedia($media)
    {
        $filePath = null;
        $exportPath = null;

        if (is_array($media)) {
            $filePath = $media['filePath'];
            $exportPath = $media['exportPath'];
        } else {
            if ('' !== $media->getFileName()) {
                $filePath = $media->getFilePath();
            }
            $exportPath = $this->mediaManager->getExportPath($media);
        }

        if (null === $filePath) {
            return;
        }

        $dirname = dirname($exportPath);

        $sftpConnection = new SFTPConnection($this->getHost(), $this->getPort());
        $sftpConnection->login($this->getUsername(), $this->getPassword());
        $sftpConnection->createDirectory($this->getImageFolderPath() . $dirname);
        $sftpConnection->uploadFile($filePath, $this->getImageFolderPath().$exportPath);
    }

    /**
     * @param $item array
     * Get only prices or all data without prices
     * @return array
     */
    protected function getProductPricesOnly($item)
    {
        if($this->getExportPriceOnly() == 'all'){
            return $item;
        }
        $attributeEntity = $this->entityManager->getRepository('Pim\Bundle\CatalogBundle\Entity\Attribute');
        $attributes      = $attributeEntity->getNonIdentifierAttributes();
        foreach($attributes as $attribute){
            if($this->getExportPriceOnly() == 'onlyPrices'){
                if($attribute->getBackendType() != 'prices'){
                    $attributesToRemove = preg_grep('/^' . $attribute->getCode() . 'D*/', array_keys($item));
                    foreach($attributesToRemove as $attributeToRemove){
                        unset($item[$attributeToRemove]);
                    }
                }
            }elseif($this->getExportPriceOnly() == 'withoutPrices'){
                if($attribute->getBackendType() == 'prices'){
                    $attributesToRemove = preg_grep('/^' . $attribute->getCode() . 'D*/', array_keys($item));
                    foreach($attributesToRemove as $attributeToRemove){
                        unset($item[$attributeToRemove]);
                    }
                }
            }
        }

        if($this->getExportPriceOnly()  == 'onlyPrices'){
            foreach($this->fixedDatas as $fixedData){
                unset($item[$fixedData]);
            }
        }

        return $item;
    }

    /**
     * @param array $item
     * Add channel code to metric attributes header columns
     * @return array
     */
    protected function formatMetricsColumns($item){
        $attributeEntity = $this->entityManager->getRepository('Pim\Bundle\CatalogBundle\Entity\Attribute');
        $attributes      = $attributeEntity->getNonIdentifierAttributes();
        foreach($attributes as $attribute){
            if($attribute->getBackendType() == 'metric'){
                if(array_key_exists($attribute->getCode(), $item)){
                    $item[$attribute->getCode() . '-' . $this->getChannel()] = $item[$attribute->getCode()];
                    unset($item[$attribute->getCode()]);
                }
            }
        }
        return $item;
    }


    /**
     * @param array $item
     * Remove all column of attributes with type media
     * @return array
     */
    protected function removeMediaColumns($item)
    {
        $attributeEntity      = $this->entityManager->getRepository('Pim\Bundle\CatalogBundle\Entity\Attribute');
        $mediaAttributesCodes = $attributeEntity->findMediaAttributeCodes();
        foreach($mediaAttributesCodes as $mediaAttributesCode){
            if(array_key_exists($mediaAttributesCode, $item)){
                unset($item[$mediaAttributesCode]);
            }
        }
        return $item;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getConfigurationFields()
    {
        return
            array_merge(
                parent::getConfigurationFields(),
                array(
                    'imageFolderPath' => array(
                        'options' => array(
                            'label'    => 'dnd_magento_connector.export.imageFolderPath.label',
                            'help'     => 'dnd_magento_connector.export.imageFolderPath.help',
                            'required' => false
                        )
                    ),
                    'exportImages' => array(
                        'type'    => 'switch',
                        'options' => array(
                            'help'    => 'dnd_magento_connector.export.exportImages.help',
                            'label'   => 'dnd_magento_connector.export.exportImages.label',
                        )
                    ),
                    'exportPriceOnly' => array(
                        'type'    => 'choice',
                        'options' => array(
                            'choices'  => array(
                                'all'           => 'dnd_magento_connector.export.exportPriceOnly.choices.all',
                                'withoutPrices' => 'dnd_magento_connector.export.exportPriceOnly.choices.withoutPrices',
                                'onlyPrices'    => 'dnd_magento_connector.export.exportPriceOnly.choices.onlyPrices',
                            ),
                            'required' => true,
                            'select2'  => true,
                            'label'    => 'dnd_magento_connector.export.exportPriceOnly.label',
                            'help'     => 'dnd_magento_connector.export.exportPriceOnly.help'
                        )
                    )
                )
            );
    }

}
