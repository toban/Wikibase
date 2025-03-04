<?php

namespace Wikibase\DataModel\Tests\Statement;

use DataValues\StringValue;
use InvalidArgumentException;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;

/**
 * @covers \Wikibase\DataModel\Statement\Statement
 *
 * @group Wikibase
 * @group WikibaseDataModel
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class StatementTest extends \PHPUnit\Framework\TestCase {

	public function testMinimalConstructor() {
		$mainSnak = new PropertyNoValueSnak( 1 );
		$statement = new Statement( $mainSnak );
		$this->assertTrue( $mainSnak->equals( $statement->getMainSnak() ) );
	}

	/**
	 * @dataProvider validConstructorArgumentsProvider
	 */
	public function testConstructorWithValidArguments(
		Snak $mainSnak,
		?SnakList $qualifiers,
		?ReferenceList $references,
		$guid
	) {
		$statement = new Statement( $mainSnak, $qualifiers, $references, $guid );
		$this->assertTrue( $statement->getMainSnak()->equals( $mainSnak ) );
		$this->assertTrue( $statement->getQualifiers()->equals( $qualifiers ?: new SnakList() ) );
		$this->assertTrue( $statement->getReferences()->equals( $references ?: new ReferenceList() ) );
		$this->assertSame( $guid, $statement->getGuid() );
	}

	public function validConstructorArgumentsProvider() {
		$snak = new PropertyNoValueSnak( 1 );
		$qualifiers = new SnakList( [ $snak ] );
		$references = new ReferenceList( [ new Reference( [ $snak ] ) ] );

		return [
			[ $snak, null, null, null ],
			[ $snak, null, null, 'guid' ],
			[ $snak, $qualifiers, $references, 'guid' ],
		];
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSetGuid( Statement $statement ) {
		$statement->setGuid( 'foo-bar-baz' );
		$this->assertSame( 'foo-bar-baz', $statement->getGuid() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetGuid( Statement $statement ) {
		$guid = $statement->getGuid();
		$this->assertTrue( $guid === null || is_string( $guid ) );
		$this->assertSame( $guid, $statement->getGuid() );

		$statement->setGuid( 'foobar' );
		$this->assertSame( 'foobar', $statement->getGuid() );
	}

	public function testHashStability() {
		$mainSnak = new PropertyNoValueSnak( new NumericPropertyId( 'P42' ) );
		$statement = new Statement( $mainSnak );
		$this->assertSame( '50c73da6759fd31868fb0cc9c218969fa776f62c', $statement->getHash() );
	}

	public function testSetAndGetMainSnak() {
		$mainSnak = new PropertyNoValueSnak( new NumericPropertyId( 'P42' ) );
		$statement = new Statement( $mainSnak );
		$this->assertSame( $mainSnak, $statement->getMainSnak() );
	}

	public function testSetAndGetQualifiers() {
		$qualifiers = new SnakList( [
			new PropertyValueSnak( new NumericPropertyId( 'P42' ), new StringValue( 'a' ) )
		] );

		$statement = new Statement(
			new PropertyNoValueSnak( new NumericPropertyId( 'P42' ) ),
			$qualifiers
		);

		$this->assertSame( $qualifiers, $statement->getQualifiers() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSerialize( Statement $statement ) {
		$copy = unserialize( serialize( $statement ) );

		$this->assertSame( $statement->getHash(), $copy->getHash(), 'Serialization roundtrip should not affect hash' );
	}

	public function testGuidDoesNotAffectHash() {
		$statement0 = new Statement( new PropertyNoValueSnak( 42 ) );
		$statement0->setGuid( 'statement0' );

		$statement1 = new Statement( new PropertyNoValueSnak( 42 ) );
		$statement1->setGuid( 'statement1' );

		$this->assertSame( $statement0->getHash(), $statement1->getHash() );
	}

	/**
	 * @dataProvider invalidGuidProvider
	 */
	public function testGivenInvalidGuid_constructorThrowsException( $guid ) {
		$this->expectException( InvalidArgumentException::class );
		new Statement( new PropertyNoValueSnak( 1 ), null, null, $guid );
	}

	/**
	 * @dataProvider invalidGuidProvider
	 */
	public function testGivenInvalidGuid_setGuidThrowsException( $guid ) {
		$this->expectException( InvalidArgumentException::class );
		$statement = new Statement( new PropertyNoValueSnak( 42 ) );
		$statement->setGuid( $guid );
	}

	public function invalidGuidProvider() {
		$snak = new PropertyNoValueSnak( 1 );

		return [
			[ false ],
			[ 1 ],
			[ $snak ],
			[ new Statement( $snak ) ],
		];
	}

	public function instanceProvider() {
		$instances = [];

		$propertyId = new NumericPropertyId( 'P42' );
		$baseInstance = new Statement( new PropertyNoValueSnak( $propertyId ) );

		$instances[] = $baseInstance;

		$instance = clone $baseInstance;
		$instance->setRank( Statement::RANK_PREFERRED );

		$instances[] = $instance;

		$newInstance = clone $instance;

		$instances[] = $newInstance;

		$instance = clone $baseInstance;

		$instance->setReferences( new ReferenceList( [
			new Reference( [
				new PropertyValueSnak( new NumericPropertyId( 'P1' ), new StringValue( 'a' ) )
			] )
		] ) );

		$instances[] = $instance;

		$argLists = [];

		foreach ( $instances as $instance ) {
			$argLists[] = [ $instance ];
		}

		return $argLists;
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetReferences( Statement $statement ) {
		$this->assertInstanceOf( ReferenceList::class, $statement->getReferences() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSetReferences( Statement $statement ) {
		$references = new ReferenceList( [
			new Reference( [
				new PropertyValueSnak( new NumericPropertyId( 'P1' ), new StringValue( 'a' ) ),
			] )
		] );

		$statement->setReferences( $references );

		$this->assertSame( $references, $statement->getReferences() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testAddNewReferenceWithVariableArgumentsSyntax( Statement $statement ) {
		$snak1 = new PropertyNoValueSnak( 256 );
		$snak2 = new PropertySomeValueSnak( 42 );
		$statement->addNewReference( $snak1, $snak2 );

		$expectedSnaks = [ $snak1, $snak2 ];
		$this->assertTrue( $statement->getReferences()->hasReference( new Reference( $expectedSnaks ) ) );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testAddNewReferenceWithAnArrayOfSnaks( Statement $statement ) {
		$snaks = [
			new PropertyNoValueSnak( 256 ),
			new PropertySomeValueSnak( 42 ),
		];
		$statement->addNewReference( $snaks );

		$this->assertTrue( $statement->getReferences()->hasReference( new Reference( $snaks ) ) );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetRank( Statement $statement ) {
		$rank = $statement->getRank();
		$this->assertIsInt( $rank );

		$ranks = [ Statement::RANK_DEPRECATED, Statement::RANK_NORMAL, Statement::RANK_PREFERRED ];
		$this->assertContains( $rank, $ranks, true );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSetRank( Statement $statement ) {
		$statement->setRank( Statement::RANK_DEPRECATED );
		$this->assertSame( Statement::RANK_DEPRECATED, $statement->getRank() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSetInvalidRank( Statement $statement ) {
		$this->expectException( InvalidArgumentException::class );
		$statement->setRank( 9001 );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetPropertyId( Statement $statement ) {
		$this->assertSame(
			$statement->getMainSnak()->getPropertyId(),
			$statement->getPropertyId()
		);
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetAllSnaks( Statement $statement ) {
		$snaks = $statement->getAllSnaks();

		$c = count( $statement->getQualifiers() ) + 1;

		/* @var Reference $reference */
		foreach ( $statement->getReferences() as $reference ) {
			$c += count( $reference->getSnaks() );
		}

		$this->assertGreaterThanOrEqual( $c, count( $snaks ), 'At least one snak per Qualifier and Reference' );
	}

	public function testGivenNonStatement_equalsReturnsFalse() {
		$statement = new Statement( new PropertyNoValueSnak( 42 ) );

		$this->assertFalse( $statement->equals( null ) );
		$this->assertFalse( $statement->equals( 42 ) );
		$this->assertFalse( $statement->equals( new \stdClass() ) );
	}

	public function testGivenSameStatement_equalsReturnsTrue() {
		$statement = new Statement(
			new PropertyNoValueSnak( 42 ),
			new SnakList( [
				new PropertyNoValueSnak( 1337 ),
			] ),
			new ReferenceList( [
				new Reference( [ new PropertyNoValueSnak( 1337 ) ] ),
			] )
		);

		$statement->setGuid( 'kittens' );

		$this->assertTrue( $statement->equals( $statement ) );
		$this->assertTrue( $statement->equals( clone $statement ) );
	}

	public function testGivenStatementWithDifferentProperty_equalsReturnsFalse() {
		$statement = new Statement( new PropertyNoValueSnak( 42 ) );
		$this->assertFalse( $statement->equals( new Statement( new PropertyNoValueSnak( 43 ) ) ) );
	}

	public function testGivenStatementWithDifferentSnakType_equalsReturnsFalse() {
		$statement = new Statement( new PropertyNoValueSnak( 42 ) );
		$this->assertFalse( $statement->equals( new Statement( new PropertySomeValueSnak( 42 ) ) ) );
	}

	public function testStatementWithDifferentQualifiers_equalsReturnsFalse() {
		$statement = new Statement(
			new PropertyNoValueSnak( 42 ),
			new SnakList( [
				new PropertyNoValueSnak( 1337 ),
			] )
		);

		$differentStatement = new Statement(
			new PropertyNoValueSnak( 42 ),
			new SnakList( [
				new PropertyNoValueSnak( 32202 ),
			] )
		);

		$this->assertFalse( $statement->equals( $differentStatement ) );
	}

	public function testGivenStatementWithDifferentGuids_equalsReturnsFalse() {
		$statement = new Statement( new PropertyNoValueSnak( 42 ) );

		$differentStatement = new Statement( new PropertyNoValueSnak( 42 ) );
		$differentStatement->setGuid( 'kittens' );

		$this->assertFalse( $statement->equals( $differentStatement ) );
	}

	public function testStatementWithDifferentReferences_equalsReturnsFalse() {
		$statement = new Statement(
			new PropertyNoValueSnak( 42 ),
			new SnakList(),
			new ReferenceList( [
				new Reference( [ new PropertyNoValueSnak( 1337 ) ] ),
			] )
		);

		$differentStatement = new Statement(
			new PropertyNoValueSnak( 42 ),
			new SnakList(),
			new ReferenceList( [
				new Reference( [ new PropertyNoValueSnak( 32202 ) ] ),
			] )
		);

		$this->assertFalse( $statement->equals( $differentStatement ) );
	}

	public function testEquals() {
		$statement = $this->newStatement();
		$target = $this->newStatement();

		$this->assertTrue( $statement->equals( $target ) );
	}

	/**
	 * @dataProvider notEqualsProvider
	 */
	public function testNotEquals( Statement $statement, Statement $target, $message ) {
		$this->assertFalse( $statement->equals( $target ), $message );
	}

	public function notEqualsProvider() {
		$statement = $this->newStatement();

		$statementWithoutQualifiers = $this->newStatement();
		$statementWithoutQualifiers->setQualifiers( new SnakList() );

		$statementWithoutReferences = $this->newStatement();
		$statementWithoutReferences->setReferences( new ReferenceList() );

		$statementWithPreferredRank = $this->newStatement();
		$statementWithPreferredRank->setRank( Statement::RANK_PREFERRED );

		$statementMainSnakNotEqual = $this->newStatement();
		$statementMainSnakNotEqual->setMainSnak( new PropertyNoValueSnak( 9000 ) );

		return [
			[ $statement, $statementWithoutQualifiers, 'qualifiers not equal' ],
			[ $statement, $statementWithoutReferences, 'references not equal' ],
			[ $statement, $statementWithPreferredRank, 'rank not equal' ],
			[ $statement, $statementMainSnakNotEqual, 'main snak not equal' ]
		];
	}

	private function newStatement() {
		$qualifiers = new SnakList( [ new PropertyNoValueSnak( 23 ) ] );

		$statement = new Statement(
			new PropertyNoValueSnak( 42 ),
			$qualifiers,
			new ReferenceList( [
				new Reference( [ new PropertyNoValueSnak( 1337 ) ] ),
			] )
		);

		$statement->setRank( Statement::RANK_NORMAL );

		return $statement;
	}

	public function testHashesOfDifferentStatementsAreNotTheSame() {
		$this->assertNotSame(
			( new Statement( new PropertyNoValueSnak( 1 ) ) )->getHash(),
			( new Statement( new PropertyNoValueSnak( 2 ) ) )->getHash()
		);
	}

	public function testHashesOfEqualStatementsAreTheSame() {
		$this->assertSame(
			( new Statement( new PropertyNoValueSnak( 1 ) ) )->getHash(),
			( new Statement( new PropertyNoValueSnak( 1 ) ) )->getHash()
		);
	}

}
