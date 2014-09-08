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

namespace Shopware\Bundle\SearchBundle\DBAL\FacetHandler;

use Doctrine\DBAL\Connection;
use Shopware\Bundle\SearchBundle\DBAL\QueryBuilder;
use Shopware\Bundle\SearchBundle\Criteria;
use Shopware\Bundle\SearchBundle\Facet;
use Shopware\Bundle\SearchBundle\FacetInterface;
use Shopware\Bundle\SearchBundle\DBAL\FacetHandlerInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\Attribute;
use Shopware\Bundle\StoreFrontBundle\Struct\Context;
use Shopware\Bundle\StoreFrontBundle\Service\CategoryServiceInterface;

/**
 * @category  Shopware
 * @package   Shopware\Bundle\SearchBundle\DBAL\FacetHandler
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class CategoryFacetHandler implements FacetHandlerInterface
{
    /**
     * @var CategoryServiceInterface
     */
    private $categoryService;

    /**
     * @param CategoryServiceInterface $categoryService
     */
    public function __construct(CategoryServiceInterface $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Generates the facet for the \Shopware\Bundle\SearchBundle\Facet;\Category class.
     * Displays how many products are assigned to the children categories.
     *
     * The handler use the category ids of the \Shopware\Bundle\SearchBundle\Condition\Category.
     * If no \Shopware\Bundle\SearchBundle\Condition\Category is set, the handler uses as default the id 1.
     *
     * @param \Shopware\Bundle\SearchBundle\FacetInterface $facet
     * @param \Shopware\Bundle\SearchBundle\DBAL\QueryBuilder $query
     * @param \Shopware\Bundle\SearchBundle\Criteria $criteria
     * @param \Shopware\Bundle\StoreFrontBundle\Struct\Context $context
     * @return \Shopware\Bundle\SearchBundle\Facet\CategoryFacet|\Shopware\Bundle\SearchBundle\FacetInterface
     */
    public function generateFacet(
        FacetInterface $facet,
        QueryBuilder $query,
        Criteria $criteria,
        Context $context
    ) {
        $query->removeTableInclude('s_categories');

        $query->removeTableInclude('s_articles_categories_ro');

        $query->resetQueryPart('orderBy');
        $query->resetQueryPart('groupBy');

        $query->select(
            array(
                'productCategory.categoryID as id',
                'COUNT(DISTINCT product.id) as total'
            )
        );

        $query->innerJoin(
            'product',
            's_articles_categories_ro',
            'productCategory',
            'productCategory.articleID = product.id'
        );

        $query->innerJoin(
            'productCategory',
            's_categories',
            'category',
            'category.id = productCategory.categoryID
             AND category.parent IN (:category)
             AND category.active = 1'
        );

        $query->groupBy('productCategory.categoryID');

        if (!$query->getParameter(':category')) {
            $query->setParameter(
                ':category',
                array(1),
                Connection::PARAM_INT_ARRAY
            );
        }

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        /**@var $facet Facet\CategoryFacet */
        $categories = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);

        $ids = array_keys($categories);

        $categoryStructs = $this->categoryService->getList($ids, $context);

        foreach($categories as $id => $total) {
            if (!$categoryStructs[$id]) {
                continue;
            }
            $category = $categoryStructs[$id];

            $attribute = new Attribute();
            $attribute->set('total', $total);
            $category->addAttribute('facet', $attribute);
        }

        $facet->setCategories($categoryStructs);

        return $facet;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFacet(FacetInterface $facet)
    {
        return ($facet instanceof Facet\CategoryFacet);
    }
}