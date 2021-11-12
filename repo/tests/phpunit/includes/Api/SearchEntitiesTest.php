<?php

namespace Wikibase\Repo\Tests\Api;

use ApiMain;
use FauxRequest;
use RequestContext;
use Wikibase\DataAccess\DatabaseEntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataAccess\EntitySourceLookup;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Term\Term;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\Interactors\TermSearchResult;
use Wikibase\Lib\StaticContentLanguages;
use Wikibase\Lib\Store\EntityArticleIdLookup;
use Wikibase\Lib\Store\EntityTitleTextLookup;
use Wikibase\Lib\Store\EntityUrlLookup;
use Wikibase\Lib\SubEntityTypesMapper;
use Wikibase\Repo\Api\ApiErrorReporter;
use Wikibase\Repo\Api\EntitySearchHelper;
use Wikibase\Repo\Api\PropertyDataTypeSearchHelper;
use Wikibase\Repo\Api\SearchEntities;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \Wikibase\Repo\Api\SearchEntities
 *
 * @group API
 * @group Wikibase
 * @group WikibaseAPI
 *
 * @group medium
 *
 * @license GPL-2.0-or-later
 * @author Addshore
 * @author Daniel Kinzler
 */
class SearchEntitiesTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$settings = WikibaseRepo::getSettings();
		$settings->setSetting( 'federatedPropertiesEnabled', false );
	}

	/**
	 * @param array $params
	 *
	 * @return ApiMain
	 */
	private function getApiMain( array $params ) {
		$context = new RequestContext();
		$context->setLanguage( 'en-ca' );
		$context->setRequest( new FauxRequest( $params, true ) );
		$main = new ApiMain( $context );
		return $main;
	}

	/**
	 * @return ContentLanguages
	 */
	private function getContentLanguages() {
		return new StaticContentLanguages(
			[ 'de', 'de-ch', 'en', 'ii', 'nn', 'ru', 'zh-cn' ]
		);
	}

	/**
	 * @param array $params
	 * @param TermSearchResult[] $returnResults
	 *
	 * @return EntitySearchHelper
	 */
	private function getMockEntitySearchHelper( array $params, array $returnResults = [] ) {
		// defaults from SearchEntities
		$params = array_merge( [
			'strictlanguage' => false,
			'type' => 'item',
			'limit' => 7,
			'continue' => 0
		], $params );

		$mock = $this->createMock( EntitySearchHelper::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'getRankedSearchResults' )
			->with(
				$this->equalTo( $params['search'] ),
				$this->equalTo( $params['language'] ),
				$this->equalTo( $params['type'] ),
				$this->equalTo( $params['continue'] + $params['limit'] + 1 ),
				$this->equalTo( $params['strictlanguage'] )
			)
			->willReturn( $returnResults );

		return $mock;
	}

	/**
	 * @param array $params
	 * @param EntitySearchHelper|null $entitySearchHelper
	 *
	 * @return array[]
	 */
	private function callApiModule( array $params, EntitySearchHelper $entitySearchHelper = null ) {
		$entitySourceDefinitions = new EntitySourceDefinitions( [
			new DatabaseEntitySource(
				'items',
				false,
				[ 'item' => [ 'namespaceId' => 10000, 'slot' => 'main' ] ],
				'http://items.wiki/concept/',
				'',
				'',
				''
			),
			new DatabaseEntitySource(
				'props',
				'otherdb',
				[ 'property' => [ 'namespaceId' => 50000, 'slot' => 'main' ] ],
				'http://property.wiki/concept/',
				'o',
				'o',
				'otherwiki'
			)
		], new SubEntityTypesMapper( [] ) );

		$module = new SearchEntities(
			$this->getApiMain( $params ),
			'wbsearchentities',
			$entitySearchHelper ?: $this->getMockEntitySearchHelper( $params ),
			$this->getContentLanguages(),
			new EntitySourceLookup( $entitySourceDefinitions, new SubEntityTypesMapper( [] ) ),
			$this->newMockTitleTextLookup(),
			$this->newMockUrlLookup(),
			$this->newMockArticleIdLookup(),
			$this->createMock( ApiErrorReporter::class ),
			[ 'item', 'property' ]
		);

		$module->execute();

		$result = $module->getResult();
		return $result->getResultData( null, [
			'BC' => [],
			'Types' => [],
			'Strip' => 'all',
		] );
	}

	public function testSearchStrictLanguage_passedToSearchInteractor() {
		$params = [
			'action' => 'wbsearchentities',
			'search' => 'Foo',
			'type' => 'item',
			'language' => 'de-ch',
			'strictlanguage' => true
		];

		$this->callApiModule( $params );
	}

	public function provideTestSearchEntities() {
		$q111Match = new TermSearchResult(
			new Term( 'qid', 'Q111' ),
			'entityId',
			new ItemId( 'Q111' ),
			new Term( 'pt', 'ptLabel' ),
			new Term( 'pt', 'ptDescription' )
		);

		$q222Match = new TermSearchResult(
			new Term( 'en-gb', 'Fooooo' ),
			'label',
			new ItemId( 'Q222' ),
			new Term( 'en-gb', 'FooHeHe' ),
			new Term( 'en', 'FooHeHe en description' )
		);

		$q333Match = new TermSearchResult(
			new Term( 'de', 'AMatchedTerm' ),
			'alias',
			new ItemId( 'Q333' ),
			new Term( 'fr', 'ADisplayLabel' )
		);

		$propertyMatch = new TermSearchResult(
			new Term( 'en', 'PropertyLabel' ),
			'label',
			new NumericPropertyId( 'P123' ),
			new Term( 'en', 'PropertyLabel' ),
			null,
			[ PropertyDataTypeSearchHelper::DATATYPE_META_DATA_KEY => 'PropertyDataType' ]
		);

		$q111Result = [
			'repository' => 'items',
			'id' => 'Q111',
			'concepturi' => 'http://items.wiki/concept/Q111',
			'url' => 'http://fullTitleUrl',
			'title' => 'Prefixed:Title',
			'pageid' => 42,
			'label' => 'ptLabel',
			'description' => 'ptDescription',
			'aliases' => [ 'Q111' ],
			'match' => [
				'type' => 'entityId',
				'text' => 'Q111',
			],
		];

		$q222Result = [
			'repository' => 'items',
			'id' => 'Q222',
			'concepturi' => 'http://items.wiki/concept/Q222',
			'url' => 'http://fullTitleUrl',
			'title' => 'Prefixed:Title',
			'pageid' => 42,
			'label' => 'FooHeHe',
			'description' => 'FooHeHe en description',
			'aliases' => [ 'Fooooo' ],
			'match' => [
				'type' => 'label',
				'language' => 'en-gb',
				'text' => 'Fooooo',
			],
		];

		$q333Result = [
			'repository' => 'items',
			'id' => 'Q333',
			'concepturi' => 'http://items.wiki/concept/Q333',
			'url' => 'http://fullTitleUrl',
			'title' => 'Prefixed:Title',
			'pageid' => 42,
			'label' => 'ADisplayLabel',
			'aliases' => [ 'AMatchedTerm' ],
			'match' => [
				'type' => 'alias',
				'language' => 'de',
				'text' => 'AMatchedTerm',
			],
		];

		$propertyResult = [
			'repository' => 'props',
			'id' => 'P123',
			'concepturi' => 'http://property.wiki/concept/P123',
			'url' => 'http://fullTitleUrl',
			'title' => 'Prefixed:Title',
			'pageid' => 42,
			'datatype' => 'PropertyDataType',
			'label' => 'PropertyLabel',
			'match' => [
				'type' => 'label',
				'language' => 'en',
				'text' => 'PropertyLabel',
			],
		];

		$q333ResultWithoutUrl = [
			'repository' => 'items',
			'id' => 'Q333',
			'concepturi' => 'http://items.wiki/concept/Q333',
			'title' => 'Prefixed:Title',
			'pageid' => 42,
			'label' => 'ADisplayLabel',
			'aliases' => [ 'AMatchedTerm' ],
			'match' => [
				'type' => 'alias',
				'language' => 'de',
				'text' => 'AMatchedTerm',
			],
		];

		return [
			'No exact match' => [
				[ 'search' => 'Q999' ],
				[],
				[],
			],
			'Exact EntityId match' => [
				[ 'search' => 'Q111' ],
				[ $q111Match ],
				[ $q111Result ],
			],
			'Multiple Results' => [
				[],
				[ $q222Match, $q333Match ],
				[ $q222Result, $q333Result ],
			],
			'Multiple Results (limited)' => [
				[ 'limit' => 1 ],
				[ $q222Match, $q333Match ],
				[ $q222Result ],
			],
			'Multiple Results (limited-continue)' => [
				[ 'limit' => 1, 'continue' => 1 ],
				[ $q222Match, $q333Match ],
				[ $q333Result ],
			],
			'Property has datatype' => [
				[ 'search' => 'PropertyLabel', 'type' => 'property' ],
				[ $propertyMatch ],
				[ $propertyResult ],
			],
			'URL is omitted' => [
				[ 'search' => 'Q333', 'props' => '' ],
				[ $q333Match ],
				[ $q333ResultWithoutUrl ],
			],
		];
	}

	/**
	 * @dataProvider provideTestSearchEntities
	 */
	public function testSearchEntities( array $overrideParams, array $interactorReturn, array $expected ) {
		$params = array_merge( [
			'action' => 'wbsearchentities',
			'search' => 'Foo',
			'type' => 'item',
			'language' => 'en'
		], $overrideParams );

		$entitySearchHelper = $this->getMockEntitySearchHelper( $params, $interactorReturn );

		$result = $this->callApiModule( $params, $entitySearchHelper );

		$this->assertResultLooksGood( $result );
		$this->assertEquals( $expected, $result['search'] );
	}

	private function assertResultLooksGood( $result ) {
		$this->assertArrayHasKey( 'searchinfo', $result );
		$this->assertArrayHasKey( 'search', $result['searchinfo'] );
		$this->assertArrayHasKey( 'search', $result );

		foreach ( $result['search'] as $key => $searchresult ) {
			$this->assertIsInt( $key );
			$this->assertArrayHasKey( 'repository', $searchresult );
			$this->assertArrayHasKey( 'id', $searchresult );
			$this->assertArrayHasKey( 'concepturi', $searchresult );
			$this->assertArrayHasKey( 'title', $searchresult );
			$this->assertArrayHasKey( 'pageid', $searchresult );
		}
	}

	public function testGivenEntityIdContainsUriUnsafeCharacters_conceptUriContainsEncodedCharacters() {
		$nyanId = $this->createStub( EntityId::class );
		$nyanId->method( $this->logicalOr( 'getSerialization', 'getLocalPart' ) )
			->willReturn( '[,,_,,];3' );
		$nyanId->method( 'getEntityType' )
			->willReturn( 'kitten' );

		$params = [
			'action' => 'wbsearchentities',
			'search' => 'nyan',
			'type' => 'kitten',
			'language' => 'en'
		];

		$match = new TermSearchResult(
			new Term( 'en', 'nyan' ),
			'label',
			$nyanId,
			new Term( 'en', 'nyan' )
		);

		$searchHelper = $this->getMockEntitySearchHelper( $params, [ $match ] );

		$entitySourceDefinitions = new EntitySourceDefinitions(
			[
				new DatabaseEntitySource(
					'test',
					'kittendb',
					[ 'kitten' => [ 'namespaceId' => 1234, 'slot' => 'main' ] ],
					'http://acme.test/concept/',
					'',
					'',
					''
				)
			],
			new SubEntityTypesMapper( [] )
		);
		$module = new SearchEntities(
			$this->getApiMain( $params ),
			'wbsearchentities',
			$searchHelper,
			$this->getContentLanguages(),
			new EntitySourceLookup( $entitySourceDefinitions, new SubEntityTypesMapper( [] ) ),
			$this->newMockTitleTextLookup(),
			$this->newMockUrlLookup(),
			$this->newMockArticleIdLookup(),
			$this->createMock( ApiErrorReporter::class ),
			[ 'kitten' ]
		);

		$module->execute();

		$result = $module->getResult()->getResultData( null, [
			'BC' => [],
			'Types' => [],
			'Strip' => 'all',
		] );

		$this->assertEquals( 'http://acme.test/concept/%5B,,_,,%5D;3', $result['search'][0]['concepturi'] );
	}

	private function newMockTitleTextLookup(): EntityTitleTextLookup {
		$titleTextLookup = $this->createMock( EntityTitleTextLookup::class );
		$titleTextLookup->method( 'getPrefixedText' )
			->willReturn( 'Prefixed:Title' );

		return $titleTextLookup;
	}

	private function newMockUrlLookup(): EntityUrlLookup {
		$urlLookup = $this->createMock( EntityUrlLookup::class );
		$urlLookup->method( 'getFullUrl' )
			->willReturn( 'http://fullTitleUrl' );

		return $urlLookup;
	}

	private function newMockArticleIdLookup(): EntityArticleIdLookup {
		$articleIdLookup = $this->createMock( EntityArticleIdLookup::class );
		$articleIdLookup->method( 'getArticleID' )
			->willReturn( 42 );

		return $articleIdLookup;
	}

}
