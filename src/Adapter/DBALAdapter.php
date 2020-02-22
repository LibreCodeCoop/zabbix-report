<?php
namespace App\Adapter;

use Doctrine\DBAL\Query\QueryBuilder;
use Omines\DataTablesBundle\Adapter\AbstractAdapter;
use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\Column\TextColumn;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DBALAdapter extends AbstractAdapter
{
    /**
     * @var Closure
     */
    private $queryBuilderCallback;
    /**
     * {@inheritdoc}
     */
    public function configure(array $options)
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $options = $resolver->resolve($options);

        $this->queryBuilderCallback = $options['query'];
    }

    protected function prepareQuery(AdapterQuery $query)
    {
        $state = $query->getState();
        $query->set('qb', $builder = ($this->queryBuilderCallback)($state));
        if (!$builder) {
            return;
        }
        $columns = $state->getDataTable()->getColumns();
        if (!$columns) {
            $select = $builder->getQueryPart('select');
            foreach ($select as $colName) {
                $colName = explode(' ',$colName);
                $colName = end($colName);
                $state->getDataTable()->add($colName, TextColumn::class, [
                    'propertyPath' => $colName
                ]);
            }
        }

        $query->setTotalRows($this->getCount($builder));
    }

    /**
     * @param $identifier
     * @return int
     */
    protected function getCount(QueryBuilder $queryBuilder)
    {
        $qb = clone $queryBuilder;

        $qb->select('count(1)');
        $stmt = $qb->execute();
        return (int) $stmt->fetch(\PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column)
    {
        return $column->getField();
    }

    protected function getResults(AdapterQuery $query): \Traversable
    {
        /** @var \Doctrine\DBAL\Query\QueryBuilder $builder */
        $builder = $query->get('qb');
        if (!$builder || !$query->getTotalRows()) {
            return;
        }
        $state = $query->getState();

        // Apply definitive view state for current 'page' of the table
        foreach ($state->getOrderBy() as list($column, $direction)) {
            /** @var AbstractColumn $column */
            if ($column->isOrderable()) {
                $builder->addOrderBy($column->getOrderField(), $direction);
            }
        }
        if ($state->getLength() > 0) {
            $builder
                ->setFirstResult($state->getStart())
                ->setMaxResults($state->getLength())
            ;
        }
        $stmt = $builder->execute();
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
            yield $row;
        }
    }

    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setRequired('query');
    }
}