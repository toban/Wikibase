<?php

declare( strict_types = 1 );

namespace Wikibase\Client\DataAccess\Scribunto;

use Deserializers\Exceptions\DeserializationException;
use Exception;
use Language;
use MediaWiki\MediaWikiServices;
use Scribunto_LuaError;
use Scribunto_LuaLibraryBase;
use ScribuntoException;
use Wikibase\Client\DataAccess\DataAccessSnakFormatterFactory;
use Wikibase\Client\DataAccess\PropertyIdResolver;
use Wikibase\Client\PropertyLabelNotResolvedException;
use Wikibase\Client\RepoLinker;
use Wikibase\Client\Usage\UsageAccumulator;
use Wikibase\Client\Usage\UsageTrackingLanguageFallbackLabelDescriptionLookup;
use Wikibase\Client\WikibaseClient;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityAccessLimitException;
use Wikibase\DataModel\Services\Lookup\EntityRetrievingClosestReferencedEntityIdLookup;
use Wikibase\Lib\EntityTypeDefinitions;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\Store\CachingFallbackLabelDescriptionLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookupFactory;
use Wikibase\Lib\Store\PropertyOrderProvider;
use Wikibase\Lib\Store\RedirectResolvingLatestRevisionLookup;
use Wikibase\Lib\Store\RevisionBasedEntityRedirectTargetLookup;
use Wikibase\Lib\TermLanguageFallbackChain;

/**
 * Registers and defines functions to access Wikibase through the Scribunto extension
 *
 * @license GPL-2.0-or-later
 */
class Scribunto_LuaWikibaseLibrary extends Scribunto_LuaLibraryBase {

	/**
	 * @var WikibaseLanguageIndependentLuaBindings|null
	 */
	private $languageIndependentLuaBindings = null;

	/**
	 * @var WikibaseLanguageDependentLuaBindings|null
	 */
	private $languageDependentLuaBindings = null;

	/**
	 * @var EntityAccessor|null
	 */
	private $entityAccessor = null;

	/**
	 * @var SnakSerializationRenderer[]
	 */
	private $snakSerializationRenderers = [];

	/**
	 * @var TermLanguageFallbackChain|null
	 */
	private $termFallbackChain = null;

	/**
	 * @var UsageAccumulator|null
	 */
	private $usageAccumulator = null;

	/**
	 * @var PropertyIdResolver|null
	 */
	private $propertyIdResolver = null;

	/**
	 * @var PropertyOrderProvider|null
	 */
	private $propertyOrderProvider = null;

	/**
	 * @var EntityIdParser|null
	 */
	private $entityIdParser = null;

	/**
	 * @var RepoLinker|null
	 */
	private $repoLinker = null;

	/**
	 * @var LuaFunctionCallTracker|null
	 */
	private $luaFunctionCallTracker = null;

	/**
	 * @var string[]|null
	 */
	private $luaEntityModules = null;

	private function getLanguageIndependentLuaBindings(): WikibaseLanguageIndependentLuaBindings {
		if ( $this->languageIndependentLuaBindings === null ) {
			$this->languageIndependentLuaBindings = $this->newLanguageIndependentLuaBindings();
		}

		return $this->languageIndependentLuaBindings;
	}

	private function getLanguageDependentLuaBindings(): WikibaseLanguageDependentLuaBindings {
		if ( $this->languageDependentLuaBindings === null ) {
			$this->languageDependentLuaBindings = $this->newLanguageDependentLuaBindings();
		}

		return $this->languageDependentLuaBindings;
	}

	private function getEntityAccessor(): EntityAccessor {
		if ( $this->entityAccessor === null ) {
			$this->entityAccessor = $this->newEntityAccessor();
		}

		return $this->entityAccessor;
	}

	/**
	 * @param string $type One of DataAccessSnakFormatterFactory::TYPE_*
	 */
	private function getSnakSerializationRenderer( string $type ): SnakSerializationRenderer {
		if ( !array_key_exists( $type, $this->snakSerializationRenderers ) ) {
			$this->snakSerializationRenderers[$type] = $this->newSnakSerializationRenderer( $type );
		}

		return $this->snakSerializationRenderers[$type];
	}

	private function getLanguageFallbackChain(): TermLanguageFallbackChain {
		if ( $this->termFallbackChain === null ) {
			$this->termFallbackChain = WikibaseClient::getLanguageFallbackChainFactory()
				->newFromLanguage(
					$this->getLanguage(),
					LanguageFallbackChainFactory::FALLBACK_ALL
				);
		}

		return $this->termFallbackChain;
	}

	public function getUsageAccumulator(): UsageAccumulator {
		if ( $this->usageAccumulator === null ) {
			$parserOutput = $this->getParser()->getOutput();
			$usageAccumulatorFactory = WikibaseClient::getUsageAccumulatorFactory();
			$this->usageAccumulator = $usageAccumulatorFactory->newFromParserOutput( $parserOutput );
		}

		return $this->usageAccumulator;
	}

	private function getPropertyIdResolver(): PropertyIdResolver {
		if ( $this->propertyIdResolver === null ) {
			$entityLookup = WikibaseClient::getEntityLookup();
			$propertyLabelResolver = WikibaseClient::getPropertyLabelResolver();

			$this->propertyIdResolver = new PropertyIdResolver(
				$entityLookup,
				$propertyLabelResolver,
				$this->getUsageAccumulator()
			);
		}

		return $this->propertyIdResolver;
	}

	/**
	 * Returns the language to use. If we are on a multilingual wiki
	 * (allowDataAccessInUserLanguage is true) this will be the user's interface
	 * language, otherwise it will be the content language.
	 * In a perfect world, this would equal Parser::getTargetLanguage.
	 *
	 * This can probably be removed after T114640 has been implemented.
	 *
	 * Please note, that this splits the parser cache by user language, if
	 * allowDataAccessInUserLanguage is true.
	 */
	private function getLanguage(): Language {
		if ( $this->allowDataAccessInUserLanguage() ) {
			return $this->getParserOptions()->getUserLangObj();
		}

		return MediaWikiServices::getInstance()->getContentLanguage();
	}

	private function getLuaFunctionCallTracker(): LuaFunctionCallTracker {
		if ( !$this->luaFunctionCallTracker ) {
			$mwServices = MediaWikiServices::getInstance();
			$settings = WikibaseClient::getSettings( $mwServices );

			$this->luaFunctionCallTracker = new LuaFunctionCallTracker(
				$mwServices->getStatsdDataFactory(),
				$settings->getSetting( 'siteGlobalID' ),
				WikibaseClient::getSiteGroup( $mwServices ),
				$settings->getSetting( 'trackLuaFunctionCallsPerSiteGroup' ),
				$settings->getSetting( 'trackLuaFunctionCallsPerWiki' ),
				$settings->getSetting( 'trackLuaFunctionCallsSampleRate' )
			);
		}

		return $this->luaFunctionCallTracker;
	}

	private function allowDataAccessInUserLanguage(): bool {
		$settings = WikibaseClient::getSettings();

		return $settings->getSetting( 'allowDataAccessInUserLanguage' );
	}

	private function newEntityAccessor(): EntityAccessor {
		return new EntityAccessor(
			$this->getEntityIdParser(),
			WikibaseClient::getRestrictedEntityLookup(),
			$this->getUsageAccumulator(),
			WikibaseClient::getCompactEntitySerializer(),
			WikibaseClient::getCompactBaseDataModelSerializerFactory()
				->newStatementListSerializer(),
			WikibaseClient::getPropertyDataTypeLookup(),
			$this->getLanguageFallbackChain(),
			$this->getLanguage(),
			WikibaseClient::getTermsLanguages(),
			WikibaseClient::getLogger()
		);
	}

	/**
	 * @param string $type One of DataAccessSnakFormatterFactory::TYPE_*
	 */
	private function newSnakSerializationRenderer( string $type ): SnakSerializationRenderer {
		$snakFormatterFactory = WikibaseClient::getDataAccessSnakFormatterFactory();
		$snakFormatter = $snakFormatterFactory->newWikitextSnakFormatter(
			$this->getLanguage(),
			$this->getUsageAccumulator(),
			$type
		);
		if ( $type === DataAccessSnakFormatterFactory::TYPE_RICH_WIKITEXT ) {
			// As Scribunto doesn't strip parser tags (like <mapframe>) itself,
			// we need to take care of that.
			$snakFormatter = new WikitextPreprocessingSnakFormatter(
				$snakFormatter,
				$this->getParser()
			);
		}

		$deserializerFactory = WikibaseClient::getBaseDataModelDeserializerFactory();
		$snakDeserializer = $deserializerFactory->newSnakDeserializer();
		$snaksDeserializer = $deserializerFactory->newSnakListDeserializer();

		return new SnakSerializationRenderer(
			$snakFormatter,
			$snakDeserializer,
			$this->getLanguage(),
			$snaksDeserializer
		);
	}

	private function newLanguageDependentLuaBindings(): WikibaseLanguageDependentLuaBindings {
		$nonCachingLookup = new LanguageFallbackLabelDescriptionLookup(
			WikibaseClient::getTermLookup(),
			$this->getLanguageFallbackChain()
		);

		$labelDescriptionLookup = new CachingFallbackLabelDescriptionLookup(
			WikibaseClient::getTermFallbackCache(),
			new RedirectResolvingLatestRevisionLookup( WikibaseClient::getStore()->getEntityRevisionLookup() ),
			$nonCachingLookup,
			$this->getLanguageFallbackChain()
		);

		$usageTrackingLabelDescriptionLookup = new UsageTrackingLanguageFallbackLabelDescriptionLookup(
			$labelDescriptionLookup,
			$this->getUsageAccumulator(),
			$this->getLanguageFallbackChain(),
			$this->allowDataAccessInUserLanguage()
		);

		return new WikibaseLanguageDependentLuaBindings(
			$this->getEntityIdParser(),
			$usageTrackingLabelDescriptionLookup
		);
	}

	private function newLanguageIndependentLuaBindings(): WikibaseLanguageIndependentLuaBindings {
		$mediaWikiServices = MediaWikiServices::getInstance();
		$settings = WikibaseClient::getSettings( $mediaWikiServices );
		$store = WikibaseClient::getStore( $mediaWikiServices );
		$termsLanguages = WikibaseClient::getTermsLanguages( $mediaWikiServices );

		$termLookup = new CachingFallbackBasedTermLookup(
			WikibaseClient::getTermFallbackCache( $mediaWikiServices ),
			new RedirectResolvingLatestRevisionLookup( $store->getEntityRevisionLookup() ),
			new LanguageFallbackLabelDescriptionLookupFactory(
				WikibaseClient::getLanguageFallbackChainFactory( $mediaWikiServices ),
				WikibaseClient::getTermLookup( $mediaWikiServices )
			),
			$mediaWikiServices->getLanguageFactory(),
			$termsLanguages
		);

		return new WikibaseLanguageIndependentLuaBindings(
			$store->getSiteLinkLookup(),
			WikibaseClient::getEntityIdLookup( $mediaWikiServices ),
			$settings,
			$this->getUsageAccumulator(),
			$this->getEntityIdParser(),
			$termLookup,
			$termsLanguages,
			new EntityRetrievingClosestReferencedEntityIdLookup(
				WikibaseClient::getEntityLookup( $mediaWikiServices ),
				$store->getEntityPrefetcher(),
				$settings->getSetting( 'referencedEntityIdMaxDepth' ),
				$settings->getSetting( 'referencedEntityIdMaxReferencedEntityVisits' )
			),
			$mediaWikiServices->getTitleFormatter(),
			$mediaWikiServices->getTitleParser(),
			$settings->getSetting( 'siteGlobalID' ),
			new RevisionBasedEntityRedirectTargetLookup( $store->getEntityRevisionLookup() )
		);
	}

	private function getEntityIdParser(): EntityIdParser {
		if ( !$this->entityIdParser ) {
			$this->entityIdParser = WikibaseClient::getEntityIdParser();
		}
		return $this->entityIdParser;
	}

	/**
	 * @throws \ScribuntoException
	 */
	private function parseUserGivenEntityId( string $idSerialization ): EntityId {
		try {
			return $this->getEntityIdParser()->parse( $idSerialization );
		} catch ( EntityIdParsingException $ex ) {
			throw new ScribuntoException(
				'wikibase-error-invalid-entity-id',
				[ 'args' => [ $idSerialization ] ]
			);
		}
	}

	/**
	 * Register mw.wikibase.lua library
	 *
	 * @return array
	 */
	public function register() {
		// These functions will be exposed to the Lua module.
		// They are member functions on a Lua table which is private to the module, thus
		// these can't be called from user code, unless explicitly exposed in Lua.
		$lib = [
			'getLabel' => [ $this, 'getLabel' ],
			'getLabelByLanguage' => [ $this, 'getLabelByLanguage' ],
			'getEntity' => [ $this, 'getEntity' ],
			'entityExists' => [ $this, 'entityExists' ],
			'getEntityStatements' => [ $this, 'getEntityStatements' ],
			'getEntityUrl' => [ $this, 'getEntityUrl' ],
			'renderSnak' => [ $this, 'renderSnak' ],
			'formatValue' => [ $this, 'formatValue' ],
			'renderSnaks' => [ $this, 'renderSnaks' ],
			'formatValues' => [ $this, 'formatValues' ],
			'getEntityId' => [ $this, 'getEntityId' ],
			'getReferencedEntityId' => [ $this, 'getReferencedEntityId' ],
			'getUserLang' => [ $this, 'getUserLang' ],
			'getDescription' => [ $this, 'getDescription' ],
			'resolvePropertyId' => [ $this, 'resolvePropertyId' ],
			'getSiteLinkPageName' => [ $this, 'getSiteLinkPageName' ],
			'incrementExpensiveFunctionCount' => [ $this, 'incrementExpensiveFunctionCount' ],
			'isValidEntityId' => [ $this, 'isValidEntityId' ],
			'getPropertyOrder' => [ $this, 'getPropertyOrder' ],
			'orderProperties' => [ $this, 'orderProperties' ],
			'incrementStatsKey' => [ $this, 'incrementStatsKey' ],
			'getEntityModuleName' => [ $this, 'getEntityModuleName' ],
		];

		$settings = WikibaseClient::getSettings();
		// These settings will be exposed to the Lua module.
		$options = [
			'allowArbitraryDataAccess' => $settings->getSetting( 'allowArbitraryDataAccess' ),
			'siteGlobalID' => $settings->getSetting( 'siteGlobalID' ),
			'trackLuaFunctionCallsSampleRate' => $settings->getSetting( 'trackLuaFunctionCallsSampleRate' ),
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . '/mw.wikibase.lua', $lib, $options
		);
	}

	/**
	 * Wrapper for getEntity in EntityAccessor
	 *
	 * @throws ScribuntoException
	 */
	public function getEntity( string $prefixedEntityId ): array {
		$this->checkType( 'getEntity', 1, $prefixedEntityId, 'string' );

		try {
			$entityArr = $this->getEntityAccessor()->getEntity( $prefixedEntityId );
			return [ $entityArr ];
		} catch ( EntityIdParsingException $ex ) {
			throw new ScribuntoException(
				'wikibase-error-invalid-entity-id',
				[ 'args' => [ $prefixedEntityId ] ]
			);
		} catch ( EntityAccessLimitException $ex ) {
			throw new ScribuntoException( 'wikibase-error-exceeded-entity-access-limit' );
		} catch ( Exception $ex ) {
			throw new ScribuntoException( 'wikibase-error-serialize-error' );
		}
	}

	/**
	 * Wrapper for getReferencedEntityId in WikibaseLanguageIndependentLuaBindings
	 *
	 * @param string[] $prefixedToIds
	 *
	 * @throws ScribuntoException
	 */
	public function getReferencedEntityId( string $prefixedFromEntityId, string $prefixedPropertyId, array $prefixedToIds ): array {
		$parserOutput = $this->getEngine()->getParser()->getOutput();
		$key = 'wikibase-referenced-entity-id-limit';

		$accesses = (int)$parserOutput->getExtensionData( $key );
		$accesses++;
		$parserOutput->setExtensionData( $key, $accesses );

		$limit = WikibaseClient::getSettings()->getSetting( 'referencedEntityIdAccessLimit' );
		if ( $accesses > $limit ) {
			throw new Scribunto_LuaError(
				wfMessage( 'wikibase-error-exceeded-referenced-entity-id-limit' )->params( 'IGNORED' )->numParams( 3 )->text()
			);
		}

		$this->checkType( 'getReferencedEntityId', 1, $prefixedFromEntityId, 'string' );
		$this->checkType( 'getReferencedEntityId', 2, $prefixedPropertyId, 'string' );
		$this->checkType( 'getReferencedEntityId', 3, $prefixedToIds, 'table' );

		$fromId = $this->parseUserGivenEntityId( $prefixedFromEntityId );
		$propertyId = $this->parseUserGivenEntityId( $prefixedPropertyId );
		$toIds = array_map(
			[ $this, 'parseUserGivenEntityId' ],
			$prefixedToIds
		);

		if ( !( $propertyId instanceof PropertyId ) ) {
			return [ null ];
		}

		return [
			$this->getLanguageIndependentLuaBindings()->getReferencedEntityId( $fromId, $propertyId, $toIds )
		];
	}

	/**
	 * Wrapper for entityExists in EntityAccessor
	 *
	 * @throws ScribuntoException
	 * @return bool[]
	 */
	public function entityExists( string $prefixedEntityId ): array {
		$this->checkType( 'entityExists', 1, $prefixedEntityId, 'string' );

		try {
			return [ $this->getEntityAccessor()->entityExists( $prefixedEntityId ) ];
		} catch ( EntityIdParsingException $ex ) {
			throw new ScribuntoException(
				'wikibase-error-invalid-entity-id',
				[ 'args' => [ $prefixedEntityId ] ]
			);
		}
	}

	/**
	 * Wrapper for getEntityStatements in EntityAccessor
	 *
	 * @param string $rank Which statements to include. Either "best" or "all".
	 *
	 * @throws ScribuntoException
	 */
	public function getEntityStatements( string $prefixedEntityId, string $propertyId, string $rank ): array {
		$this->checkType( 'getEntityStatements', 1, $prefixedEntityId, 'string' );
		$this->checkType( 'getEntityStatements', 2, $propertyId, 'string' );
		$this->checkType( 'getEntityStatements', 3, $rank, 'string' );

		try {
			$statements = $this->getEntityAccessor()->getEntityStatements( $prefixedEntityId, $propertyId, $rank );
		} catch ( EntityAccessLimitException $ex ) {
			throw new ScribuntoException( 'wikibase-error-exceeded-entity-access-limit' );
		} catch ( EntityIdParsingException $ex ) {
			throw new ScribuntoException(
				'wikibase-error-invalid-entity-id',
				[ 'args' => [ $prefixedEntityId ] ]
			);
		} catch ( Exception $ex ) {
			throw new ScribuntoException( 'wikibase-error-serialize-error' );
		}
		return [ $statements ];
	}

	/**
	 * Wrapper for getEntityId in WikibaseLanguageIndependentLuaBindings
	 */
	public function getEntityId( string $pageTitle, string $globalSiteId = null ): array {
		$this->checkType( 'getEntityId', 1, $pageTitle, 'string' );
		$this->checkTypeOptional( 'getEntityId', 2, $globalSiteId, 'string', null );

		return [ $this->getLanguageIndependentLuaBindings()->getEntityId( $pageTitle, $globalSiteId ) ];
	}

	/**
	 * @param string $entityIdSerialization entity ID serialization
	 *
	 * @return string[]|null[]
	 */
	public function getEntityUrl( string $entityIdSerialization ): array {
		$this->checkType( 'getEntityUrl', 1, $entityIdSerialization, 'string' );

		try {
			$url = $this->getRepoLinker()->getEntityUrl(
				$this->getEntityIdParser()->parse( $entityIdSerialization )
			);
		} catch ( EntityIdParsingException $ex ) {
			$url = null;
		}

		return [ $url ];
	}

	private function getRepoLinker(): RepoLinker {
		if ( !$this->repoLinker ) {
			$this->repoLinker = WikibaseClient::getRepoLinker();
		}
		return $this->repoLinker;
	}

	public function setRepoLinker( RepoLinker $repoLinker ): void {
		$this->repoLinker = $repoLinker;
	}

	/**
	 * Wrapper for getLabel in WikibaseLanguageDependentLuaBindings
	 *
	 * @return string[]|null[]
	 */
	public function getLabel( string $prefixedEntityId ): array {
		$this->checkType( 'getLabel', 1, $prefixedEntityId, 'string' );

		return $this->getLanguageDependentLuaBindings()->getLabel( $prefixedEntityId );
	}

	/**
	 * Wrapper for getLabelByLanguage in WikibaseLanguageIndependentLuaBindings
	 *
	 *
	 * @return string[]|null[]
	 */
	public function getLabelByLanguage( string $prefixedEntityId, string $languageCode ): array {
		$this->checkType( 'getLabelByLanguage', 1, $prefixedEntityId, 'string' );
		$this->checkType( 'getLabelByLanguage', 2, $languageCode, 'string' );

		return [ $this->getLanguageIndependentLuaBindings()->getLabelByLanguage( $prefixedEntityId, $languageCode ) ];
	}

	/**
	 * Wrapper for getDescription in WikibaseLanguageDependentLuaBindings
	 *
	 *
	 * @return string[]|null[]
	 */
	public function getDescription( string $prefixedEntityId ): array {
		$this->checkType( 'getDescription', 1, $prefixedEntityId, 'string' );

		return $this->getLanguageDependentLuaBindings()->getDescription( $prefixedEntityId );
	}

	/**
	 * Wrapper for getSiteLinkPageName in WikibaseLanguageIndependentLuaBindings
	 *
	 * @return string[]
	 */
	public function getSiteLinkPageName( string $prefixedItemId, ?string $globalSiteId ): array {
		$this->checkType( 'getSiteLinkPageName', 1, $prefixedItemId, 'string' );
		$this->checkTypeOptional( 'getSiteLinkPageName', 2, $globalSiteId, 'string', null );

		return [ $this->getLanguageIndependentLuaBindings()->getSiteLinkPageName( $prefixedItemId, $globalSiteId ) ];
	}

	/**
	 * Wrapper for WikibaseLanguageIndependentLuaBindings::isValidEntityId
	 *
	 * @throws ScribuntoException
	 * @return bool[] One bool telling whether the entity id is valid (parseable).
	 */
	public function isValidEntityId( string $entityIdSerialization ): array {
		$this->checkType( 'isValidEntityId', 1, $entityIdSerialization, 'string' );

		return [ $this->getLanguageIndependentLuaBindings()->isValidEntityId( $entityIdSerialization ) ];
	}

	/**
	 * Wrapper for SnakSerializationRenderer::renderSnak, set to output wikitext escaped plain text.
	 *
	 * @throws ScribuntoException
	 * @return string[] Wikitext
	 */
	public function renderSnak( array $snakSerialization ): array {
		$this->checkType( 'renderSnak', 1, $snakSerialization, 'table' );

		try {
			return [
				$this->getSnakSerializationRenderer(
					DataAccessSnakFormatterFactory::TYPE_ESCAPED_PLAINTEXT
				)->renderSnak( $snakSerialization )
			];
		} catch ( DeserializationException $e ) {
			throw new ScribuntoException( 'wikibase-error-deserialize-error' );
		}
	}

	/**
	 * Wrapper for SnakSerializationRenderer::renderSnak, set to output rich wikitext.
	 *
	 * @throws ScribuntoException
	 * @return string[] Wikitext
	 */
	public function formatValue( array $snakSerialization ): array {
		$this->checkType( 'formatValue', 1, $snakSerialization, 'table' );

		try {
			return [
				$this->getSnakSerializationRenderer(
					DataAccessSnakFormatterFactory::TYPE_RICH_WIKITEXT
				)->renderSnak( $snakSerialization )
			];
		} catch ( DeserializationException $e ) {
			throw new ScribuntoException( 'wikibase-error-deserialize-error' );
		}
	}

	/**
	 * Wrapper for SnakSerializationRenderer::renderSnaks, set to output wikitext escaped plain text.
	 *
	 * @param array[] $snaksSerialization
	 *
	 * @throws ScribuntoException
	 * @return string[] Wikitext
	 */
	public function renderSnaks( array $snaksSerialization ): array {
		$this->checkType( 'renderSnaks', 1, $snaksSerialization, 'table' );

		try {
			return [
				$this->getSnakSerializationRenderer(
					DataAccessSnakFormatterFactory::TYPE_ESCAPED_PLAINTEXT
				)->renderSnaks( $snaksSerialization )
			];
		} catch ( DeserializationException $e ) {
			throw new ScribuntoException( 'wikibase-error-deserialize-error' );
		}
	}

	/**
	 * Wrapper for SnakSerializationRenderer::renderSnaks, set to output rich wikitext.
	 *
	 * @param array[] $snaksSerialization
	 *
	 * @throws ScribuntoException
	 * @return string[] Wikitext
	 */
	public function formatValues( array $snaksSerialization ): array {
		$this->checkType( 'formatValues', 1, $snaksSerialization, 'table' );

		try {
			return [
				$this->getSnakSerializationRenderer(
					DataAccessSnakFormatterFactory::TYPE_RICH_WIKITEXT
				)->renderSnaks( $snaksSerialization )
			];
		} catch ( DeserializationException $e ) {
			throw new ScribuntoException( 'wikibase-error-deserialize-error' );
		}
	}

	/**
	 * Wrapper for PropertyIdResolver
	 *
	 * @return string[]|null[]
	 */
	public function resolvePropertyId( string $propertyLabelOrId ): array {
		$this->checkType( 'resolvePropertyId', 1, $propertyLabelOrId, 'string' );
		try {
			$propertyId = $this->getPropertyIdResolver()->resolvePropertyId(
				$propertyLabelOrId,
				MediaWikiServices::getInstance()->getContentLanguage()->getCode()
			);
			$ret = [ $propertyId->getSerialization() ];
			return $ret;
		} catch ( PropertyLabelNotResolvedException $e ) {
			return [ null ];
		}
	}

	/**
	 * @param string[] $propertyIds
	 *
	 * @return array[]
	 */
	public function orderProperties( array $propertyIds ): array {
		if ( $propertyIds === [] ) {
			return [ [] ];
		}

		$orderedPropertiesPart = [];
		$unorderedProperties = [];

		$propertyOrder = $this->getPropertyOrderProvider()->getPropertyOrder();
		foreach ( $propertyIds as $propertyId ) {
			if ( isset( $propertyOrder[$propertyId] ) ) {
				$orderedPropertiesPart[ $propertyOrder[ $propertyId ] ] = $propertyId;
			} else {
				$unorderedProperties[] = $propertyId;
			}
		}
		ksort( $orderedPropertiesPart );
		$orderedProperties = array_merge( $orderedPropertiesPart, $unorderedProperties );

		// Lua tables start at 1
		$orderedPropertiesResult = array_combine(
				range( 1, count( $orderedProperties ) ), array_values( $orderedProperties )
		);
		return [ $orderedPropertiesResult ];
	}

	/**
	 * Return the order of properties as provided by the PropertyOrderProvider
	 * @return array[] either int[][] or null[][]
	 */
	public function getPropertyOrder(): array {
		return [ $this->getPropertyOrderProvider()->getPropertyOrder() ];
	}

	/**
	 * Increment the given stats key.
	 */
	public function incrementStatsKey( string $key ): void {
		$this->getLuaFunctionCallTracker()->incrementKey( $key );
	}

	/**
	 * Get the entity module name to use for the entity with this ID.
	 *
	 * @return string[]
	 */
	public function getEntityModuleName( string $prefixedEntityId ): array {
		$this->checkType( 'getEntityModuleName', 1, $prefixedEntityId, 'string' );

		try {
			$entityId = $this->getEntityIdParser()->parse( $prefixedEntityId );
			$type = $entityId->getEntityType();
			$moduleName = $this->getLuaEntityModules()[$type] ?? 'mw.wikibase.entity';
		} catch ( EntityIdParsingException $e ) {
			$moduleName = 'mw.wikibase.entity';
		}
		return [ $moduleName ];
	}

	private function getPropertyOrderProvider(): PropertyOrderProvider {
		if ( !$this->propertyOrderProvider ) {
			$this->propertyOrderProvider = WikibaseClient::getPropertyOrderProvider();
		}
		return $this->propertyOrderProvider;
	}

	public function setPropertyOrderProvider( PropertyOrderProvider $propertyOrderProvider ): void {
		$this->propertyOrderProvider = $propertyOrderProvider;
	}

	private function getLuaEntityModules(): array {
		if ( !$this->luaEntityModules ) {
			$this->luaEntityModules = WikibaseClient::getEntityTypeDefinitions()
				->get( EntityTypeDefinitions::LUA_ENTITY_MODULE );
		}
		return $this->luaEntityModules;
	}

}
