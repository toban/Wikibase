<?php

namespace Wikibase\DataModel\Deserializers;

use Deserializers\Deserializer;
use Deserializers\DispatchableDeserializer;
use Deserializers\DispatchingDeserializer;
use Wikibase\DataModel\Entity\EntityIdParser;

/**
 * Factory for constructing Deserializer objects that can deserialize WikibaseDataModel objects.
 *
 * @since 0.1
 *
 * @license GPL-2.0-or-later
 * @author Thomas Pellissier Tanon
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class DeserializerFactory {

	/**
	 * @var Deserializer
	 */
	private $dataValueDeserializer;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @param Deserializer $dataValueDeserializer deserializer for DataValue objects
	 * @param EntityIdParser $entityIdParser
	 */
	public function __construct(
		Deserializer $dataValueDeserializer,
		EntityIdParser $entityIdParser
	) {
		$this->dataValueDeserializer = $dataValueDeserializer;
		$this->entityIdParser = $entityIdParser;
	}

	/**
	 * @return DispatchableDeserializer A deserializer that can only deserialize Item and Property
	 *  objects, but no other entity types. In contexts with custom entity types other than items
	 *  and properties this is not what you want. If in doubt, favor a custom
	 *  `DispatchingDeserializer` containing the exact entity deserializers you need.
	 */
	public function newEntityDeserializer() {
		return new DispatchingDeserializer( [
			$this->newItemDeserializer(),
			$this->newPropertyDeserializer()
		] );
	}

	/**
	 * Returns a Deserializer that can deserialize Item objects.
	 *
	 * @since 2.1
	 *
	 * @return DispatchableDeserializer
	 */
	public function newItemDeserializer() {
		return new ItemDeserializer(
			$this->newEntityIdDeserializer(),
			$this->newTermListDeserializer(),
			$this->newAliasGroupListDeserializer(),
			$this->newStatementListDeserializer(),
			$this->newSiteLinkDeserializer()
		);
	}

	/**
	 * Returns a Deserializer that can deserialize Property objects.
	 *
	 * @since 2.1
	 *
	 * @return DispatchableDeserializer
	 */
	public function newPropertyDeserializer() {
		return new PropertyDeserializer(
			$this->newEntityIdDeserializer(),
			$this->newTermListDeserializer(),
			$this->newAliasGroupListDeserializer(),
			$this->newStatementListDeserializer()
		);
	}

	/**
	 * Returns a Deserializer that can deserialize SiteLink objects.
	 *
	 * @return Deserializer
	 */
	public function newSiteLinkDeserializer() {
		return new SiteLinkDeserializer( $this->newEntityIdDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize StatementList objects.
	 *
	 * @since 1.4
	 *
	 * @return Deserializer
	 */
	public function newStatementListDeserializer() {
		return new StatementListDeserializer( $this->newStatementDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize Statement objects.
	 *
	 * @since 1.4
	 *
	 * @return DispatchableDeserializer
	 */
	public function newStatementDeserializer() {
		return new StatementDeserializer(
			$this->newSnakDeserializer(),
			$this->newSnakListDeserializer(),
			$this->newReferencesDeserializer()
		);
	}

	/**
	 * Returns a Deserializer that can deserialize ReferenceList objects.
	 *
	 * @return Deserializer
	 */
	public function newReferencesDeserializer() {
		return new ReferenceListDeserializer( $this->newReferenceDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize Reference objects.
	 *
	 * @return Deserializer
	 */
	public function newReferenceDeserializer() {
		return new ReferenceDeserializer( $this->newSnakListDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize SnakList objects.
	 *
	 * @since 1.4
	 *
	 * @return Deserializer
	 */
	public function newSnakListDeserializer() {
		return new SnakListDeserializer( $this->newSnakDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize Snak objects.
	 *
	 * @return Deserializer
	 */
	public function newSnakDeserializer() {
		return new SnakDeserializer( $this->entityIdParser, $this->dataValueDeserializer );
	}

	/**
	 * Returns a Deserializer that can deserialize EntityId objects.
	 *
	 * @return Deserializer
	 */
	public function newEntityIdDeserializer() {
		return new EntityIdDeserializer( $this->entityIdParser );
	}

	/**
	 * Returns a Deserializer that can deserialize Term objects.
	 *
	 * @since 1.5
	 *
	 * @return Deserializer
	 */
	public function newTermDeserializer() {
		return new TermDeserializer();
	}

	/**
	 * Returns a Deserializer that can deserialize TermList objects.
	 *
	 * @since 1.5
	 *
	 * @return Deserializer
	 */
	public function newTermListDeserializer() {
		return new TermListDeserializer( $this->newTermDeserializer() );
	}

	/**
	 * Returns a Deserializer that can deserialize AliasGroupList objects.
	 *
	 * @since 1.5
	 *
	 * @return Deserializer
	 */
	public function newAliasGroupListDeserializer() {
		return new AliasGroupListDeserializer();
	}

}
