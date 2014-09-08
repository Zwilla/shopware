<?php
/**
 * Shopware 4
 * Copyright © shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\StoreFrontBundle\Gateway\DBAL;

use Doctrine\DBAL\Connection;
use Shopware\Components\Model\ModelManager;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\Bundle\StoreFrontBundle\Gateway;

/**
 * @category  Shopware
 * @package   Shopware\Bundle\StoreFrontBundle\Gateway\DBAL
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class SimilarProductsGateway implements Gateway\SimilarProductsGatewayInterface
{
    /**
     * @var \Shopware\Components\Model\ModelManager
     */
    private $entityManager;

    /**
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @param ModelManager $entityManager
     * @param \Shopware_Components_Config $config
     */
    public function __construct(
        ModelManager $entityManager,
        \Shopware_Components_Config $config
    ) {
        $this->entityManager = $entityManager;
        $this->config = $config;
    }

    /**
     * @inheritdoc
     */
    public function get(Struct\ListProduct $product, Struct\Context $context)
    {
        $numbers = $this->getList(array($product), $context);

        return array_shift($numbers);
    }

    /**
     * @inheritdoc
     */
    public function getList($products, Struct\Context $context)
    {
        $ids = array();
        foreach ($products as $product) {
            $ids[] = $product->getId();
        }
        $ids = array_unique($ids);

        $query = $this->entityManager->getDBALQueryBuilder();

        $query->select(
            array(
                'product.id',
                'similarVariant.ordernumber as number'
            )
        );

        $query->from('s_articles_similar', 'similar');

        $query->innerJoin(
            'similar',
            's_articles',
            'product',
            'product.id = similar.articleID'
        );

        $query->innerJoin(
            'similar',
            's_articles',
            'similarArticles',
            'similarArticles.id = similar.relatedArticle'
        );

        $query->innerJoin(
            'similarArticles',
            's_articles_details',
            'similarVariant',
            'similarVariant.id = similarArticles.main_detail_id'
        );

        $query->where('product.id IN (:ids)')
            ->setParameter(':ids', $ids, Connection::PARAM_INT_ARRAY);

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_GROUP);

        $related = array();
        foreach ($data as $productId => $row) {
            $related[$productId] = array_column($row, 'number');
        }

        return $related;
    }

    /**
     * @inheritdoc
     */
    public function getByCategory(Struct\ListProduct $product, Struct\Context $context)
    {
        $products = $this->getListByCategory(array($product), $context);

        return array_shift($products);
    }

    /**
     * @inheritdoc
     */
    public function getListByCategory($products, Struct\Context $context)
    {
        $ids = array();
        foreach ($products as $product) {
            $ids[] = $product->getId();
        }
        $ids = array_unique($ids);

        $categoryId = 1;
        if ($context->getShop() && $context->getShop()->getCategory()) {
            $categoryId = $context->getShop()->getCategory()->getId();
        }

        $query = $this->entityManager->getDBALQueryBuilder();

        $query->select(array(
            'main.articleID',
            "GROUP_CONCAT(subVariant.ordernumber SEPARATOR '|') as similar"
        ));

        $query->from('s_articles_categories', 'main');

        $query->innerJoin(
            'main',
            's_articles_categories',
            'sub',
            'sub.categoryID = main.categoryID AND sub.articleID != main.articleID'
        );

        $query->innerJoin(
            'sub',
            's_articles_details',
            'subVariant',
            'subVariant.articleID = sub.articleID AND subVariant.kind = 1'
        );

        $query->innerJoin(
            'main',
            's_categories',
            'category',
            'category.id = sub.categoryID AND category.id = main.categoryID'
        );

        $query->where('main.articleID IN (:ids)')
            ->andWhere('category.path LIKE :path');

        $query->setParameter(':ids', $ids, Connection::PARAM_INT_ARRAY)
            ->setParameter(':path', '%|'. (int) $categoryId.'|');

        $query->groupBy('main.articleID');

        $statement = $query->execute();
        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $limit = 3;
        if ($this->config->offsetExists('similarLimit') && $this->config->get('similarLimit') > 0) {
            $limit = (int) $this->config->get('similarLimit');
        }

        $result = array();
        foreach ($data as $row) {
            $similar = explode('|', $row['similar']);
            $result[$row['articleID']] = array_slice($similar, 0, $limit);
        }

        return $result;
    }
}