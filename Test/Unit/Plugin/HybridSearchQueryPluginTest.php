<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Test\Unit\Plugin;

use JALabs\SymSearch\Model\Config;
use JALabs\SymSearch\Plugin\HybridSearchQueryPlugin;
use JALabs\SymSearch\Service\QueryEmbedding;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\Query\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\OpenSearch\SearchAdapter\Mapper;
use Magento\Search\Model\Query;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * NOTE on RequestInterface::getSort():
 * Magento\Framework\Search\RequestInterface does NOT declare getSort().
 * It lives only on the concrete Magento\Framework\Search\Request class
 * (@since 102.0.2, @deprecated 102.0.2).  We therefore cannot use
 * createMock(RequestInterface::class) for mocks that need getSort() —
 * PHPUnit would throw because the method doesn't exist on the interface.
 * Instead we use getMockBuilder(RequestInterface::class)->addMethods(['getSort'])
 * so PHPUnit generates the stub while still typing the variable as RequestInterface.
 */
class HybridSearchQueryPluginTest extends TestCase
{
    private Config $config;
    private QueryEmbedding $queryEmbedding;
    private RequestInterface $request;
    private Mapper $mapper;
    private HybridSearchQueryPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isStorefrontEnabled')->willReturn(true);
        $this->config->method('getKnnK')->willReturn(100);
        $this->config->method('getMinScore')->willReturn(0.0);
        $this->config->method('getPaginationDepth')->willReturn(1000);

        $this->queryEmbedding = $this->createMock(QueryEmbedding::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(10);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $query = $this->createMock(Query::class);
        $query->method('getQueryText')->willReturn('gift for kids who love space');
        $queryFactory = $this->createMock(QueryFactory::class);
        $queryFactory->method('get')->willReturn($query);

        // getSort() is NOT on RequestInterface — use addMethods() to stub it.
        $this->request = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getSort'])
            ->getMockForAbstractClass();
        $this->request->method('getName')->willReturn('quick_search_container');
        $this->request->method('getSort')->willReturn([['field' => 'relevance', 'direction' => 'DESC']]);

        $this->mapper = $this->createMock(Mapper::class);

        $this->plugin = new HybridSearchQueryPlugin(
            $this->config,
            $this->queryEmbedding,
            $storeManager,
            $queryFactory,
            new NullLogger()
        );
    }

    private function baseSearchQuery(): array
    {
        return ['index' => 'magento2_product_10_v3', 'body' => ['query' => [
            'bool' => [
                'must'   => [['query_string' => ['query' => 'space']]],
                'filter' => [['term' => ['visibility' => 4]]],
            ],
        ]]];
    }

    public function testWrapsQueryInHybridWithKnnAndCopiesFilter(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);

        $result = $this->plugin->afterBuildQuery($this->mapper, $this->baseSearchQuery(), $this->request);

        $hybrid = $result['body']['query']['hybrid']['queries'];
        $this->assertCount(2, $hybrid);
        $this->assertArrayHasKey('bool', $hybrid[0]);
        $knn = $hybrid[1]['knn']['embedding_vector'];
        $this->assertSame([0.1, 0.2], $knn['vector']);
        $this->assertSame(100, $knn['k']);
        $this->assertSame([['term' => ['visibility' => 4]]], $knn['filter']['bool']['filter']);
    }

    public function testHandlesMirasvitScriptScoreWrapper(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $searchQuery = ['body' => ['query' => ['script_score' => [
            'query'  => $this->baseSearchQuery()['body']['query'],
            'script' => ['source' => '1 + _score'],
        ]]]];

        $result = $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request);

        $hybrid = $result['body']['query']['hybrid']['queries'];
        $this->assertArrayHasKey('script_score', $hybrid[0]);
        $this->assertSame(
            [['term' => ['visibility' => 4]]],
            $hybrid[1]['knn']['embedding_vector']['filter']['bool']['filter']
        );
    }

    public function testNoVectorLeavesQueryUntouched(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn(null);
        $searchQuery = $this->baseSearchQuery();

        $this->assertSame($searchQuery, $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request));
    }

    public function testNonQuickSearchContainerIsSkipped(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getName')->willReturn('catalog_view_container');
        $this->queryEmbedding->expects($this->never())->method('getVector');
        $searchQuery = $this->baseSearchQuery();

        $this->assertSame($searchQuery, $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $request));
    }

    public function testNonRelevanceSortIsSkipped(): void
    {
        // getSort() is NOT on RequestInterface — use addMethods() to stub it.
        $request = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getSort'])
            ->getMockForAbstractClass();
        $request->method('getName')->willReturn('quick_search_container');
        $request->method('getSort')->willReturn([['field' => 'price', 'direction' => 'ASC']]);
        $this->queryEmbedding->expects($this->never())->method('getVector');
        $searchQuery = $this->baseSearchQuery();

        $this->assertSame($searchQuery, $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $request));
    }

    public function testExtractsStructuralFiltersFromBoolMust(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $searchQuery = ['body' => ['query' => ['bool' => [
            'must' => [
                ['query_string' => ['query' => 'space', 'fields' => ['name']]],
                ['term' => ['visibility' => 4]],
                ['terms' => ['website_ids' => [1]]],
            ],
            'minimum_should_match' => 0,
        ]]]];

        $result = $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request);

        $knnFilter = $result['body']['query']['hybrid']['queries'][1]['knn']['embedding_vector']['filter']['bool']['filter'];
        $this->assertContains(['term' => ['visibility' => 4]], $knnFilter);
        $this->assertContains(['terms' => ['website_ids' => [1]]], $knnFilter);
        foreach ($knnFilter as $clause) {
            $this->assertArrayNotHasKey('query_string', $clause);
        }
    }

    public function testExtractsMirasvitStockTermFromScriptScoreMust(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $searchQuery = ['body' => ['query' => ['script_score' => [
            'query' => ['bool' => [
                'must' => [
                    ['query_string' => ['query' => 'space', 'fields' => ['name']]],
                    ['term' => ['stock_status_value' => 2]],
                ],
            ]],
            'script' => ['source' => '1 + _score'],
        ]]]];

        $result = $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request);

        $knnFilter = $result['body']['query']['hybrid']['queries'][1]['knn']['embedding_vector']['filter']['bool']['filter'];
        $this->assertSame([['term' => ['stock_status_value' => 2]]], $knnFilter);
    }

    public function testHybridReducesSortToScoreOnly(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $searchQuery = $this->baseSearchQuery();
        $searchQuery['body']['sort'] = [['_score' => ['order' => 'desc']], ['entity_id' => ['order' => 'desc']]];

        $result = $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request);

        $this->assertSame([['_score' => ['order' => 'desc']]], $result['body']['sort']);
        $this->assertArrayHasKey('hybrid', $result['body']['query']);
    }

    public function testSkippedRequestKeepsSortUntouched(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn(null);
        $searchQuery = $this->baseSearchQuery();
        $searchQuery['body']['sort'] = [['_score' => ['order' => 'desc']], ['entity_id' => ['order' => 'desc']]];

        $result = $this->plugin->afterBuildQuery($this->mapper, $searchQuery, $this->request);

        $this->assertCount(2, $result['body']['sort']);
    }

    public function testExtractsQueryTextFromSearchRequestTree(): void
    {
        // The bound search_term lives in the request's query tree as a Match query
        // inside the bool 'should' clause (see quick_search_container search_request.xml).
        $match = $this->createMock(MatchQuery::class);
        $match->method('getType')->willReturn(QueryInterface::TYPE_MATCH);
        $match->method('getValue')->willReturn('space books for kids');

        $bool = $this->createMock(BoolExpression::class);
        $bool->method('getType')->willReturn(QueryInterface::TYPE_BOOL);
        $bool->method('getMust')->willReturn([]);
        $bool->method('getShould')->willReturn([$match]);
        $bool->method('getMustNot')->willReturn([]);

        // getSort() is NOT on RequestInterface — use addMethods() to stub it.
        $request = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['getSort'])
            ->getMockForAbstractClass();
        $request->method('getName')->willReturn('quick_search_container');
        $request->method('getSort')->willReturn([['field' => 'relevance', 'direction' => 'DESC']]);
        $request->method('getQuery')->willReturn($bool);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(10);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        // The request tree supplies the term, so QueryFactory must never be consulted.
        $queryFactory = $this->createMock(QueryFactory::class);
        $queryFactory->expects($this->never())->method('get');

        $queryEmbedding = $this->createMock(QueryEmbedding::class);
        $queryEmbedding->expects($this->once())->method('getVector')
            ->with('space books for kids', 10)
            ->willReturn([0.1, 0.2]);

        $plugin = new HybridSearchQueryPlugin(
            $this->config,
            $queryEmbedding,
            $storeManager,
            $queryFactory,
            new NullLogger()
        );

        $result = $plugin->afterBuildQuery($this->mapper, $this->baseSearchQuery(), $request);

        $this->assertArrayHasKey('hybrid', $result['body']['query']);
        $this->assertSame(
            [0.1, 0.2],
            $result['body']['query']['hybrid']['queries'][1]['knn']['embedding_vector']['vector']
        );
    }

    public function testMinScoreReplacesKInKnnClause(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isStorefrontEnabled')->willReturn(true);
        $config->method('getKnnK')->willReturn(100);
        $config->method('getMinScore')->willReturn(0.4);
        $config->method('getPaginationDepth')->willReturn(1000);
        $queryEmbedding = $this->createMock(QueryEmbedding::class);
        $queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(10);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $query = $this->createMock(Query::class);
        $query->method('getQueryText')->willReturn('gift for kids');
        $queryFactory = $this->createMock(QueryFactory::class);
        $queryFactory->method('get')->willReturn($query);
        $plugin = new HybridSearchQueryPlugin($config, $queryEmbedding, $storeManager, $queryFactory, new NullLogger());

        $result = $plugin->afterBuildQuery($this->mapper, $this->baseSearchQuery(), $this->request);

        $knn = $result['body']['query']['hybrid']['queries'][1]['knn']['embedding_vector'];
        $this->assertSame(0.4, $knn['min_score']);
        $this->assertArrayNotHasKey('k', $knn);
    }

    public function testHybridIncludesPaginationDepth(): void
    {
        $this->queryEmbedding->method('getVector')->willReturn([0.1, 0.2]);
        $result = $this->plugin->afterBuildQuery($this->mapper, $this->baseSearchQuery(), $this->request);
        $this->assertSame(1000, $result['body']['query']['hybrid']['pagination_depth']);
    }
}
