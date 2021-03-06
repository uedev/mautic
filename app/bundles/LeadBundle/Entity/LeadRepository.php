<?php
/**
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Entity;

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\SearchStringHelper;
use Mautic\PointBundle\Model\TriggerModel;

/**
 * LeadRepository.
 */
class LeadRepository extends CommonRepository implements CustomFieldRepositoryInterface
{
    use CustomFieldRepositoryTrait;
    use OperatorListTrait;

    /**
     * @var array
     */
    private $availableSocialFields = [];

    /**
     * @var array
     */
    private $availableSearchFields = [];

    /**
     * Required to get the color based on a lead's points.
     *
     * @var TriggerModel
     */
    private $triggerModel;

    /**
     * Used by search functions to search social profiles.
     *
     * @param array $fields
     */
    public function setAvailableSocialFields(array $fields)
    {
        $this->availableSocialFields = $fields;
    }

    /**
     * Used by search functions to search using aliases as commands.
     *
     * @param array $fields
     */
    public function setAvailableSearchFields(array $fields)
    {
        $this->availableSearchFields = $fields;
    }

    /**
     * Sets trigger model.
     *
     * @param TriggerModel $triggerModel
     */
    public function setTriggerModel(TriggerModel $triggerModel)
    {
        $this->triggerModel = $triggerModel;
    }

    /**
     * Get a list of leads based on field value.
     *
     * @param $field
     * @param $value
     * @param $ignoreId
     *
     * @return array
     */
    public function getLeadsByFieldValue($field, $value, $ignoreId = null)
    {
        $col = 'l.'.$field;

        if ($field == 'email') {
            // Prevent emails from being case sensitive
            $col   = "LOWER($col)";
            $value = strtolower($value);
        }

        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where("$col = :search")
            ->setParameter('search', $value);

        if ($ignoreId) {
            $q->andWhere('l.id != :ignoreId')
                ->setParameter('ignoreId', $ignoreId);
        }

        $results = $q->execute()->fetchAll();

        if (count($results)) {
            $ids = [];
            foreach ($results as $r) {
                $ids[] = $r['id'];
            }

            $q = $this->_em->createQueryBuilder()
                ->select('l')
                ->from('MauticLeadBundle:Lead', 'l');
            $q->where(
                $q->expr()->in('l.id', ':ids')
            )
                ->setParameter('ids', $ids)
                ->orderBy('l.dateAdded', 'DESC');
            $results = $q->getQuery()->getResult();

            /** @var Lead $lead */
            foreach ($results as $lead) {
                $lead->setAvailableSocialFields($this->availableSocialFields);
            }
        }

        return $results;
    }

    /**
     * Get a list of lead entities.
     *
     * @param      $uniqueFieldsWithData
     * @param null $leadId
     *
     * @return array
     */
    public function getLeadsByUniqueFields($uniqueFieldsWithData, $leadId = null)
    {
        // get the list of IDs
        $idList = $this->getLeadIdsByUniqueFields($uniqueFieldsWithData, $leadId);

        // init to empty array
        $results = [];

        // if we didn't get anything return empty
        if (!count(($idList))) {
            return $results;
        }

        $ids = [];

        // we know we have at least one
        foreach ($idList as $r) {
            $ids[] = $r['id'];
        }

        $q = $this->_em->createQueryBuilder()
            ->select('l')
            ->from('MauticLeadBundle:Lead', 'l');

        $q->where(
            $q->expr()->in('l.id', ':ids')
        )
            ->setParameter('ids', $ids)
            ->orderBy('l.dateAdded', 'DESC');

        $results = $q->getQuery()->getResult();

        /** @var Lead $lead */
        foreach ($results as $lead) {
            $lead->setAvailableSocialFields($this->availableSocialFields);
            if (!empty($this->triggerModel)) {
                $lead->setColor($this->triggerModel->getColorForLeadPoints($lead->getPoints()));
            }

            $fieldValues = $this->getFieldValues($lead->getId());
            $lead->setFields($fieldValues);
        }

        return $results;
    }

    /**
     * Get list of lead Ids by unique field data.
     *
     * @param $uniqueFieldsWithData is an array of columns & values to filter by
     * @param int $leadId is the current lead id. Added to query to skip and find other leads
     *
     * @return array
     */
    public function getLeadIdsByUniqueFields($uniqueFieldsWithData, $leadId = null)
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        // loop through the fields and
        foreach ($uniqueFieldsWithData as $col => $val) {
            $q->orWhere("l.$col = :".$col)
                ->setParameter($col, $val);
        }

        // if we have a lead ID lets use it
        if (!empty($leadId)) {
            // make sure that its not the id we already have
            $q->andWhere('l.id != '.$leadId);
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param string $email
     * @param bool   $all   Set to true to return all matching lead id's
     *
     * @return array|null
     */
    public function getLeadByEmail($email, $all = false)
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('LOWER(email) = :search')
            ->setParameter('search', strtolower($email));

        $result = $q->execute()->fetchAll();

        if (count($result)) {
            return $all ? $result : $result[0];
        } else {
            return null;
        }
    }

    /**
     * Get leads by IP address.
     *
     * @param      $ip
     * @param bool $byId
     *
     * @return array
     */
    public function getLeadsByIp($ip, $byId = false)
    {
        $q = $this->createQueryBuilder('l')
            ->leftJoin('l.ipAddresses', 'i');
        $col = ($byId) ? 'i.id' : 'i.ipAddress';
        $q->where($col.' = :ip')
            ->setParameter('ip', $ip)
            ->orderBy('l.dateAdded', 'DESC');
        $results = $q->getQuery()->getResult();

        /** @var Lead $lead */
        foreach ($results as $lead) {
            $lead->setAvailableSocialFields($this->availableSocialFields);
        }

        return $results;
    }

    /**
     * @param $id
     *
     * @return array
     */
    public function getLead($id)
    {
        $fq = $this->_em->getConnection()->createQueryBuilder();
        $fq->select('l.*')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where('l.id = '.$id);
        $results = $fq->execute()->fetchAll();

        return (isset($results[0])) ? $results[0] : [];
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     *
     * @return mixed|null
     */
    public function getEntity($id = 0)
    {
        try {
            /** @var Lead $entity */
            $entity = $this
                ->createQueryBuilder('l')
                ->select('l, u, i')
                ->leftJoin('l.ipAddresses', 'i')
                ->leftJoin('l.owner', 'u')
                ->where('l.id = :leadId')
                ->setParameter('leadId', $id)
                ->getQuery()
                ->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        if ($entity != null) {
            if (!empty($this->triggerModel)) {
                $entity->setColor($this->triggerModel->getColorForLeadPoints($entity->getPoints()));
            }

            $fieldValues = $this->getFieldValues($id);
            $entity->setFields($fieldValues);

            $entity->setAvailableSocialFields($this->availableSocialFields);
        }

        return $entity;
    }

    /**
     * Get a list of leads.
     *
     * @param array $args
     *
     * @return array
     */
    public function getEntities($args = [])
    {
        return $this->getEntitiesWithCustomFields('lead', $args, function ($r) {
            if (!empty($this->triggerModel)) {
                $r->setColor($this->triggerModel->getColorForLeadPoints($r->getPoints()));
            }
            $r->setAvailableSocialFields($this->availableSocialFields);
        });
    }

    /**
     * @return array
     */
    public function getFieldGroups()
    {
        return ['core', 'social', 'personal', 'professional'];
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function getEntitiesDbalQueryBuilder()
    {
        $alias = $this->getTableAlias();
        $dq    = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'leads', $alias)
            ->leftJoin($alias, MAUTIC_TABLE_PREFIX.'users', 'u', 'u.id = '.$alias.'.owner_id');

        return $dq;
    }

    /**
     * @param $order
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getEntitiesOrmQueryBuilder($order)
    {
        $alias = $this->getTableAlias();
        $q     = $this->getEntityManager()->createQueryBuilder();
        $q->select($alias.', u, i,'.$order)
            ->from('MauticLeadBundle:Lead', $alias, $alias.'.id')
            ->leftJoin($alias.'.ipAddresses', 'i')
            ->leftJoin($alias.'.owner', 'u');

        return $q;
    }

    /**
     * Get contacts for a specific channel entity.
     *
     * @param $args - same as getEntity/getEntities
     * @param        $joinTable
     * @param        $entityId
     * @param array  $filters
     * @param string $contactColumnName
     *
     * @return array
     */
    public function getEntityContacts($args, $joinTable, $entityId, $filters = [], $contactColumnName = 'id')
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $qb->select('null')
            ->from(MAUTIC_TABLE_PREFIX.$joinTable, 'entity')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('l.id', 'entity.lead_id'),
                    $qb->expr()->eq("entity.{$contactColumnName}", (int) $entityId)
                )
            );

        $parameters = [];
        if ($filters) {
            foreach ($filters as $column => $value) {
                $parameterName = $this->generateRandomParameterName();
                $qb->andWhere(
                    $qb->expr()->eq("entity.{$column}", ":{$parameterName}")
                );
                $parameters[$parameterName] = $value;
            }
        }

        $args['entity_query']      = $qb;
        $args['entity_parameters'] = $parameters;

        return $this->getEntities($args);
    }

    /**
     * Adds the "catch all" where clause to the QueryBuilder.
     *
     * @param QueryBuilder $q
     * @param              $filter
     *
     * @return array
     */
    protected function addCatchAllWhereClause(&$q, $filter)
    {
        $columns = array_merge(
            [
                'l.firstname',
                'l.lastname',
                'l.email',
                'l.company',
                'l.city',
                'l.state',
                'l.zipcode',
                'l.country',
            ],
            $this->availableSocialFields
        );

        return $this->addStandardCatchAllWhereClause($q, $filter, $columns);
    }

    /**
     * Adds the command where clause to the QueryBuilder.
     *
     * @param QueryBuilder $q
     * @param              $filter
     *
     * @return array
     */
    protected function addSearchCommandWhereClause(&$q, $filter)
    {
        $command         = $filter->command;
        $string          = $filter->string;
        $unique          = $this->generateRandomParameterName();
        $returnParameter = true; //returning a parameter that is not used will lead to a Doctrine error
        $expr            = false;
        $parameters      = [];

        //DBAL QueryBuilder does not have an expr()->not() function; boo!!

        // This will be switched by some commands that use join tables as NOT EXISTS queries will be used
        $exprType = ($filter->not) ? 'negate_expr' : 'expr';

        $operators = $this->getFilterExpressionFunctions();
        $operators = array_merge($operators, [
            'x' => [
                'expr'        => 'andX',
                'negate_expr' => 'orX',
            ],
            'null' => [
                'expr'        => 'isNull',
                'negate_expr' => 'isNotNull',
            ],
        ]);

        $joinTables = (isset($this->advancedFilterCommands[$command])
            && SearchStringHelper::COMMAND_NEGATE !== $this->advancedFilterCommands[$command]);
        $setParameter = true;
        $likeExpr     = $operators['like'][$exprType];
        $eqExpr       = $operators['='][$exprType];
        $nullExpr     = $operators['null'][$exprType];
        $inExpr       = $operators['in'][$exprType];

        switch ($command) {
            case $this->translator->trans('mautic.lead.lead.searchcommand.isanonymous'):
                $expr            = $q->expr()->$nullExpr('l.date_identified');
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.core.searchcommand.ismine'):
                $expr            = $q->expr()->$eqExpr('l.owner_id', $this->currentUser->getId());
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.isunowned'):
                $expr = $q->expr()->orX(
                    $q->expr()->$eqExpr('l.owner_id', 0),
                    $q->expr()->$nullExpr('l.owner_id')
                );
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.owner'):
                $expr = $q->expr()->orX(
                    $q->expr()->$likeExpr('u.first_name', ':'.$unique),
                    $q->expr()->$likeExpr('u.last_name', ':'.$unique)
                );
                break;
            case $this->translator->trans('mautic.core.searchcommand.name'):
                $expr = $q->expr()->orX(
                    $q->expr()->$likeExpr('l.firstname', ":$unique"),
                    $q->expr()->$likeExpr('l.lastname', ":$unique")
                );
                break;
            case $this->translator->trans('mautic.core.searchcommand.email'):
                $expr = $q->expr()->$likeExpr('l.email', ":$unique");
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.list'):
                if (!$joinTables) {
                    $exprType = 'expr';
                }
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_lists_leads',
                            'alias'      => 'list_lead',
                            'condition'  => 'l.id = list_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'list_lead',
                            'table'      => 'lead_lists',
                            'alias'      => 'list',
                            'condition'  => 'list_lead.leadlist_id = list.id',
                        ],
                    ],
                    $joinTables,
                    $q->expr()->{$operators['x'][$exprType]}(
                        $q->expr()->{$operators['='][$exprType]}('list_lead.manually_removed', 0),
                        $q->expr()->{$operators['like'][$exprType]}('list.alias', ":$unique")
                    )
                );

                break;
            case $this->translator->trans('mautic.core.searchcommand.ip'):
                if (!$joinTables) {
                    $exprType = 'expr';
                }
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_ips_xref',
                            'alias'      => 'ip_lead',
                            'condition'  => 'l.id = ip_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'ip_lead',
                            'table'      => 'ip_addresses',
                            'alias'      => 'ip',
                            'condition'  => 'ip_lead.ip_id = ip.id',
                        ],
                    ],
                    $joinTables,
                    $q->expr()->{$operators['like'][$exprType]}('p.ip_address', ":$unique")
                );

                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.duplicate'):
                $prateek  = explode('+', $string);
                $imploder = [];

                foreach ($prateek as $key => $value) {
                    $list       = $this->getEntityManager()->getRepository('MauticLeadBundle:LeadList')->findOneByAlias($value);
                    $imploder[] = ((!empty($list)) ? (int) $list->getId() : 0);
                }

                //logic. In query, Sum(manuall_removed) should be less than the current)
                $pluck    = count($imploder);
                $imploder = (string) (implode(',', $imploder));

                $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();
                $sq->select('duplicate.lead_id')
                    ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'duplicate')
                    ->where(
                        $q->expr()->andX(
                            $q->expr()->in('duplicate.leadlist_id', $imploder),
                            $q->expr()->eq('duplicate.manually_removed', 0)
                        )
                    )
                    ->groupBy('duplicate.lead_id')
                    ->having("COUNT(duplicate.lead_id) = $pluck");

                $expr = $q->expr()->$inExpr('l.id', sprintf('(%s)', $sq->getSQL()));

                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.tag'):
                if (!$joinTables) {
                    $exprType = 'expr';
                }
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'lead_tags_xref',
                            'alias'      => 'xtag',
                            'condition'  => 'l.id = xtag.lead_id',
                        ],
                        [
                            'from_alias' => 'xtag',
                            'table'      => 'lead_tags',
                            'alias'      => 'tag',
                            'condition'  => 'xtag.tag_id = tag.id',
                        ],
                    ],
                    $joinTables,
                    $q->expr()->{$operators['like'][$exprType]}('tag.tag', ":$unique")
                );
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.company'):
                if (!$joinTables) {
                    $exprType = 'expr';
                }
                $this->applySearchQueryRelationship(
                    $q,
                    [
                        [
                            'from_alias' => 'l',
                            'table'      => 'companies_leads',
                            'alias'      => 'comp_lead',
                            'condition'  => 'l.id = comp_lead.lead_id',
                        ],
                        [
                            'from_alias' => 'comp_lead',
                            'table'      => 'companies',
                            'alias'      => 'comp',
                            'condition'  => 'comp_lead.company_id = comp.id',
                        ],
                    ],
                    $joinTables,
                    $q->expr()->{$operators['like'][$exprType]}('comp.companyname', ":$unique")
                );
                break;
            default:
                if (in_array($command, $this->availableSearchFields)) {
                    $expr = $q->expr()->$likeExpr("l.$command", ":$unique");
                }
                break;
        }

        if ($setParameter) {
            $string              = ($filter->strict) ? $filter->string : "{$filter->string}%";
            $parameters[$unique] = $string;
        }

        return [
            $expr,
            ($returnParameter) ? $parameters : [],
        ];
    }

    /**
     * Returns the array of search commands.
     *
     * @return array
     */
    public function getSearchCommands()
    {
        $commands = [
            'mautic.lead.lead.searchcommand.isanonymous',
            'mautic.core.searchcommand.ismine',
            'mautic.lead.lead.searchcommand.isunowned',
            'mautic.lead.lead.searchcommand.list',
            'mautic.core.searchcommand.name',
            'mautic.lead.lead.searchcommand.company',
            'mautic.core.searchcommand.email',
            'mautic.lead.lead.searchcommand.owner',
            'mautic.core.searchcommand.ip',
            'mautic.lead.lead.searchcommand.tag',
            'mautic.lead.lead.searchcommand.stage',
            'mautic.lead.lead.searchcommand.duplicate',
        ];

        if (!empty($this->availableSearchFields)) {
            $commands = array_merge($commands, $this->availableSearchFields);
        }

        return $commands;
    }

    /**
     * Returns the array of columns with the default order.
     *
     * @return array
     */
    protected function getDefaultOrder()
    {
        return [
            ['l.last_active', 'DESC'],
        ];
    }

    /**
     * Updates lead's lastActive with now date/time.
     *
     * @param int $leadId
     */
    public function updateLastActive($leadId)
    {
        $dt     = new DateTimeHelper();
        $fields = ['last_active' => $dt->toUtcString()];

        $this->_em->getConnection()->update(MAUTIC_TABLE_PREFIX.'leads', $fields, ['id' => $leadId]);
    }

    /**
     * Gets the ID of the latest ID.
     *
     * @return int
     */
    public function getMaxLeadId()
    {
        $result = $this->_em->getConnection()->createQueryBuilder()
            ->select('max(id) as max_lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->execute()->fetchAll();

        return $result[0]['max_lead_id'];
    }

    /**
     * Gets names, signature and email of the user(lead owner).
     *
     * @param int $ownerId
     *
     * @return array|false
     */
    public function getLeadOwner($ownerId)
    {
        if (!$ownerId) {
            return false;
        }

        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('u.id, u.first_name, u.last_name, u.email, u.signature')
            ->from(MAUTIC_TABLE_PREFIX.'users', 'u')
            ->where('u.id = :ownerId')
            ->setParameter('ownerId', (int) $ownerId);

        $result = $q->execute()->fetch();

        // Fix the HTML markup
        if (is_array($result)) {
            foreach ($result as &$field) {
                $field = html_entity_decode($field);
            }
        }

        return $result;
    }

    /**
     * @param array $contactIds
     *
     * @return array
     */
    public function getContacts(array $contactIds)
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $qb->select('l.*')->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where(
                $qb->expr()->in('l.id', $contactIds)
            );

        $results = $qb->execute()->fetchAll();

        if ($results) {
            $contacts = [];
            foreach ($results as $result) {
                $contacts[$result['id']] = $result;
            }

            return $contacts;
        }

        return [];
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'l';
    }

    /**
     * @param QueryBuilder $q
     * @param array        $tables          $tables[0] should be primary table
     * @param bool         $joinTables
     * @param null         $whereExpression
     * @param null         $having
     */
    protected function applySearchQueryRelationship(QueryBuilder $q, array $tables, $joinTables, $whereExpression = null, $having = null)
    {
        $primaryTable = $tables[0];
        unset($tables[0]);

        if ($joinTables) {
            $this->useDistinctCount = true;
            $joins                  = $q->getQueryPart('join');
            if (!array_key_exists($primaryTable['alias'], $joins)) {
                $q->join(
                    $primaryTable['from_alias'],
                    MAUTIC_TABLE_PREFIX.$primaryTable['table'],
                    $primaryTable['alias'],
                    $primaryTable['condition']
                );
                foreach ($tables as $table) {
                    $q->join($table['from_alias'], MAUTIC_TABLE_PREFIX.$table['table'], $table['alias'], $table['condition']);
                }

                if ($whereExpression) {
                    $q->andWhere($whereExpression);
                }

                if ($having) {
                    $q->andHaving($having);
                }
                $q->groupBy('l.id');
            }
        } else {
            // Use a NOT EXIST table so that entites not
            $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $sq->select('null')
                ->from(MAUTIC_TABLE_PREFIX.$primaryTable['table'], $primaryTable['alias']);

            if (count($tables)) {
                foreach ($tables as $table) {
                    $sq->join($table['from_alias'], MAUTIC_TABLE_PREFIX.$table['table'], $table['alias'], $table['condition']);
                }
            }

            $sq->where($q->expr()->eq('l.id', $primaryTable['alias'].'.lead_id'));
            if ($whereExpression) {
                $sq->andWhere($whereExpression);
            }

            if ($having) {
                $sq->having($having);
            }

            $q->andWhere(
                sprintf('NOT EXISTS (%s)', $sq->getSQL())
            );
        }
    }
}
