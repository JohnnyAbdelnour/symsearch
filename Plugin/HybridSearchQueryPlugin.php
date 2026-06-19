<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Plugin;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Service\QueryEmbedding;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\Query\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\OpenSearch\SearchAdapter\Mapper;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rewrites the quick-search query into an OpenSearch hybrid (BM25 + k-NN) query.
 * Runs AFTER Mirasvit's ElasticsearchAddScriptToSearchQueryPlugin (which acts on the
 * inner ElasticAdapter Mapper), so the original sub-query keeps Mirasvit boosts intact.
 */
class HybridSearchQueryPlugin
{
    private const MIN_QUERY_LENGTH = 3;

    public function __construct(
        private readonly Config $config,
        private readonly QueryEmbedding $queryEmbedding,
        private readonly StoreManagerInterface $storeManager,
        private readonly QueryFactory $queryFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterBuildQuery(Mapper $subject, array $searchQuery, RequestInterface $request): array
    {
        try {
            if ($request->getName() !== 'quick_search_container'
                || empty($searchQuery['body']['query'])
                || !$this->isSortByRelevance($request)
            ) {
                return $searchQuery;
            }

            $storeId = (int)$this->storeManager->getStore()->getId();
            if (!$this->config->isStorefrontEnabled($storeId)) {
                return $searchQuery;
            }

            $queryText = trim($this->extractQueryText($request));
            if ($queryText === '') {
                $queryText = trim($this->queryFactory->get()->getQueryText());
            }
            if (mb_strlen($queryText) < self::MIN_QUERY_LENGTH) {
                return $searchQuery;
            }

            $vector = $this->queryEmbedding->getVector($queryText, $storeId);
            if ($vector === null) {
                return $searchQuery;
            }

            $original = $searchQuery['body']['query'];

            $knn = ['vector' => $vector];
            // OpenSearch treats `k` and `min_score` as mutually exclusive in a knn clause.
            // When the admin configures a min_score (> 0) it REPLACES k-based retrieval;
            // otherwise we fall back to top-k.
            $minScore = $this->config->getMinScore($storeId);
            if ($minScore > 0) {
                $knn['min_score'] = $minScore;
            } else {
                $knn['k'] = $this->config->getKnnK($storeId);
            }
            $filter = $this->extractFilter($original);
            if ($filter) {
                $knn['filter'] = ['bool' => ['filter' => $filter]];
            }

            $searchQuery['body']['query'] = [
                'hybrid' => [
                    'pagination_depth' => $this->config->getPaginationDepth($storeId),
                    'queries' => [
                        $original,
                        ['knn' => [Config::FIELD_NAME => $knn]],
                    ],
                ],
            ];

            $searchQuery['body']['sort'] = $this->normalizeSortForHybrid($searchQuery['body']['sort'] ?? null);
            if ($searchQuery['body']['sort'] === null) {
                unset($searchQuery['body']['sort']);
            }

            if ($this->config->isDebug()) {
                $this->logger->info('[symsearch] hybrid query applied', ['store' => $storeId, 'q' => $queryText]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[symsearch] hybrid rewrite failed, falling back to keyword: ' . $e->getMessage());
        }

        return $searchQuery;
    }

    /** Extract the raw search phrase from the search request's query tree (works in HTTP and CLI). */
    private function extractQueryText(RequestInterface $request): string
    {
        $query = $request->getQuery();
        if (!$query instanceof QueryInterface) {
            return '';
        }
        return $this->findFirstMatchValue($query, 0);
    }

    /**
     * Walk the request query tree depth-first (depth-limit 3) and return the first
     * Match-type query's value. The bound search_term lives in the quick_search_container
     * bool tree's 'should' clause as a matchQuery (see core search_request.xml).
     */
    private function findFirstMatchValue(QueryInterface $query, int $depth): string
    {
        if ($depth > 3) {
            return '';
        }
        if ($query->getType() === QueryInterface::TYPE_MATCH && $query instanceof MatchQuery) {
            return (string)$query->getValue();
        }
        if ($query->getType() === QueryInterface::TYPE_BOOL && $query instanceof BoolExpression) {
            foreach ([$query->getMust(), $query->getShould(), $query->getMustNot()] as $collection) {
                foreach ($collection as $sub) {
                    if ($sub instanceof QueryInterface) {
                        $value = $this->findFirstMatchValue($sub, $depth + 1);
                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
            }
        }
        return '';
    }

    private function isSortByRelevance(RequestInterface $request): bool
    {
        $sorts = method_exists($request, 'getSort') ? $request->getSort() : null;
        if (!$sorts) {
            return true;
        }
        foreach ($sorts as $sort) {
            $field = is_array($sort) ? ($sort['field'] ?? '') : (string)$sort->getField();
            if ($field === 'relevance' || $field === '_score') {
                return true;
            }
        }
        return false;
    }

    /**
     * Collects structural (non-textual) filter clauses from the bool query.
     *
     * Magento's core Filter\Builder emits structural filters into bool['must']
     * (not bool['filter']), and Mirasvit injects stock terms into the same place
     * inside its script_score wrapper. We therefore harvest from BOTH bool['filter']
     * and bool['must'], keeping only structural clauses so the knn branch is filtered
     * identically to the BM25 branch (no out-of-stock / catalog-only leakage).
     */
    private function extractFilter(array $query): ?array
    {
        $bool = $query['bool'] ?? ($query['script_score']['query']['bool'] ?? null);
        if (!is_array($bool)) {
            return null;
        }

        $collected = [];
        if (isset($bool['filter']) && is_array($bool['filter'])) {
            foreach ($bool['filter'] as $clause) {
                $collected[] = $clause;
            }
        }
        if (isset($bool['must']) && is_array($bool['must'])) {
            foreach ($bool['must'] as $clause) {
                if (is_array($clause) && $this->isStructuralClause($clause)) {
                    $collected[] = $clause;
                }
            }
        }

        return $collected ?: null;
    }

    private const STRUCTURAL_KEYS = ['term', 'terms', 'range', 'exists', 'ids'];
    private const TEXTUAL_KEYS = [
        'match',
        'multi_match',
        'match_phrase',
        'match_phrase_prefix',
        'query_string',
        'simple_query_string',
        'wildcard',
        'more_like_this',
    ];
    private const BOOL_COMPOSITE_KEYS = [
        'must',
        'should',
        'must_not',
        'filter',
        'minimum_should_match',
        'boost',
    ];

    /** A clause is structural if its single top-level key is structural, or a fully-structural nested bool. */
    private function isStructuralClause(array $clause): bool
    {
        if (count($clause) !== 1) {
            return false;
        }
        $key = array_key_first($clause);
        if (in_array($key, self::STRUCTURAL_KEYS, true)) {
            return true;
        }
        if ($key === 'bool' && is_array($clause['bool'])) {
            return $this->isStructuralBool($clause['bool']);
        }
        return false;
    }

    /**
     * Hybrid queries reject _score combined with any other sort criterion.
     * Keep only the _score entry; relevance order is what hybrid produces anyway.
     *
     * @param array|null $sort
     * @return array|null
     */
    private function normalizeSortForHybrid(?array $sort): ?array
    {
        if (!$sort) {
            return null;
        }
        foreach ($sort as $entry) {
            if (is_array($entry) && array_key_exists('_score', $entry)) {
                return [$entry];
            }
        }
        return [['_score' => ['order' => 'desc']]];
    }

    /** A bool subtree is structural if every clause within it is structural (recursively). */
    private function isStructuralBool(array $bool): bool
    {
        foreach ($bool as $key => $value) {
            if (in_array($key, self::TEXTUAL_KEYS, true)) {
                return false;
            }
            if (!in_array($key, self::BOOL_COMPOSITE_KEYS, true)) {
                return false;
            }
            // minimum_should_match / boost carry scalars — no nested clauses to inspect.
            if (in_array($key, ['must', 'should', 'must_not', 'filter'], true) && is_array($value)) {
                foreach ($value as $sub) {
                    if (!is_array($sub) || !$this->isStructuralClause($sub)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
