<?php

namespace Wikibase\Repo\Tests\ParserOutput;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\LanguageWithConversion;
use Wikibase\Lib\Store\EntityRevision;
use Wikibase\Lib\TermLanguageFallbackChain;
use Wikibase\Repo\ParserOutput\TermboxView;
use Wikibase\Repo\ParserOutput\TextInjector;
use Wikibase\View\DummyLocalizedTextProvider;
use Wikibase\View\EntityTermsView;
use Wikibase\View\LocalizedTextProvider;
use Wikibase\View\SpecialPageLinker;
use Wikibase\View\Termbox\Renderer\TermboxRenderer;
use Wikibase\View\Termbox\Renderer\TermboxRenderingException;
use Wikibase\View\ViewPlaceHolderEmitter;

/**
 * @covers \Wikibase\Repo\ParserOutput\TermboxView
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class TermboxViewTest extends TestCase {

	public function testGetHtmlReturnsPlaceholderMarker() {
		$marker = 'termbox-marker';
		$textInjector = $this->createMock( TextInjector::class );
		$textInjector->expects( $this->once() )
			->method( 'newMarker' )
			->with( TermboxView::TERMBOX_PLACEHOLDER )
			->willReturn( $marker );

		$termbox = new TermboxView(
			$this->createMock( LanguageFallbackChainFactory::class ),
			$this->newTermboxRenderer(),
			$this->newLocalizedTextProvider(),
			$this->newSpecialPageLinker(),
			$textInjector
		);

		$this->assertSame( $marker, $termbox->getHtml(
			'en',
			new TermList( [] ),
			new TermList( [] ),
			null,
			new ItemId( 'Q42' )
		) );
	}

	public function testGetPlaceholdersContainsMarkers() {
		$markers = [ 'marker' => 'marker-name' ];

		$textInjector = $this->createMock( TextInjector::class );
		$textInjector->expects( $this->once() )
			->method( 'getMarkers' )
			->willReturn( $markers );

		$languageCode = 'en';
		$stubContentLanguages = $this->createStub( ContentLanguages::class );
		$stubContentLanguages->method( 'hasLanguage' )
			->willReturn( true );
		$fallbackChainFactory = $this->createMock( LanguageFallbackChainFactory::class );
		$fallbackChainFactory->expects( $this->once() )
			->method( 'newFromLanguageCode' )
			->with( $languageCode )
			->willReturn( new TermLanguageFallbackChain( [ LanguageWithConversion::factory( $languageCode ) ], $stubContentLanguages ) );

		$termbox = new TermboxView(
			$fallbackChainFactory,
			$this->newTermboxRenderer(),
			$this->newLocalizedTextProvider(),
			$this->newSpecialPageLinker(),
			$textInjector
		);

		$this->assertContains( $markers, $termbox->getPlaceholders( new Item( new ItemId( 'Q123' ) ), 4711, $languageCode ) );
	}

	public function testPlaceHolderWithMarkupWithClientStringResponse_returnsContent() {
		$language = 'en';
		$itemId = new ItemId( 'Q42' );
		$item = new Item( $itemId );
		$revision = 4711;
		$editLinkUrl = '/edit/Q42';
		$response = 'termbox says hi';

		$stubContentLanguages = $this->createStub( ContentLanguages::class );
		$stubContentLanguages->method( 'hasLanguage' )
			->willReturn( true );
		$fallbackChain = new TermLanguageFallbackChain( [ LanguageWithConversion::factory( $language ) ], $stubContentLanguages );
		$fallbackChainFactory = $this->createMock( LanguageFallbackChainFactory::class );
		$fallbackChainFactory->expects( $this->once() )
			->method( 'newFromLanguageCode' )
			->with( $language )
			->willReturn( $fallbackChain );

		$renderer = $this->newTermboxRenderer();
		$renderer->expects( $this->once() )
			->method( 'getContent' )
			->with( $item->getId(), $revision, $language, $editLinkUrl, $fallbackChain )
			->willReturn( $response );

		$placeholders = $this->newTermbox(
			$renderer,
			$this->newLocalizedTextProvider(),
			$this->newLinkingSpecialPageLinker( $item->getId(), $editLinkUrl ),
			$fallbackChainFactory
		)->getPlaceholders(
			$item,
			$revision,
			$language
		);

		$this->assertSame(
			$response,
			$placeholders[ TermboxView::TERMBOX_MARKUP ]
		);
	}

	public function testPlaceHolderWithMarkupWithClientThrowingException_returnsErrorValue() {
		$language = 'en';
		$item = new Item( new ItemId( 'Q42' ) );
		$revision = 4711;

		$renderer = $this->newTermboxRenderer();
		$renderer->expects( $this->once() )
			->method( 'getContent' )
			->willThrowException( new TermboxRenderingException( 'specific reason of failure' ) );

		$placeholders = $this->newTermbox( $renderer, $this->newLocalizedTextProvider(), $this->newSpecialPageLinker() )->getPlaceholders(
			$item,
			$revision,
			$language
		);
		$this->assertSame(
			ViewPlaceHolderEmitter::ERRONEOUS_PLACEHOLDER_VALUE,
			$placeholders[ TermboxView::TERMBOX_MARKUP ]
		);
	}

	public function testPlaceHolderWithMarkupWithUnsavedRevision_returnsErrorValue() {
		$language = 'en';
		$item = new Item( new ItemId( 'Q42' ) );
		$revision = EntityRevision::UNSAVED_REVISION;

		$renderer = $this->newTermboxRenderer();
		$renderer->expects( $this->never() )
			->method( 'getContent' );

		$placeholders = $this->newTermbox( $renderer, $this->newLocalizedTextProvider(), $this->newSpecialPageLinker() )->getPlaceholders(
			$item,
			$revision,
			$language
		);
		$this->assertSame(
			ViewPlaceHolderEmitter::ERRONEOUS_PLACEHOLDER_VALUE,
			$placeholders[ TermboxView::TERMBOX_MARKUP ]
		);
	}

	public function testGetTitleHtml_returnsHtmlWithEntityId() {
		$entityId = new ItemId( 'Q42' );

		$textProvider = new DummyLocalizedTextProvider();

		$termbox = $this->newTermbox(
			$this->newTermboxRenderer(),
			$textProvider,
			$this->newSpecialPageLinker()
		);

		$this->assertSame(
			'(parentheses: Q42)',
			$termbox->getTitleHtml( $entityId )
		);
	}

	/**
	 * @return TermboxRenderer|MockObject
	 */
	private function newTermboxRenderer(): TermboxRenderer {
		return $this->createMock( TermboxRenderer::class );
	}

	/**
	 * @return LocalizedTextProvider|MockObject
	 */
	private function newLocalizedTextProvider(): LocalizedTextProvider {
		return $this->createMock( LocalizedTextProvider::class );
	}

	private function newTermbox(
		TermboxRenderer $renderer,
		LocalizedTextProvider $textProvider,
		SpecialPageLinker $specialPageLinker,
		LanguageFallbackChainFactory $fallbackChainFactory = null,
		TextInjector $textInjector = null
	): TermboxView {
		return new TermboxView(
			$fallbackChainFactory ?: new LanguageFallbackChainFactory(),
			$renderer,
			$textProvider,
			$specialPageLinker,
			$textInjector ?: new TextInjector()
		);
	}

	private function newLinkingSpecialPageLinker( $itemId, $editLinkUrl ) {
		$specialPageLinker = $this->newSpecialPageLinker();
		$specialPageLinker->expects( $this->once() )
			->method( 'getLink' )
			->with( EntityTermsView::TERMS_EDIT_SPECIAL_PAGE, [ $itemId ] )
			->willReturn( $editLinkUrl );
		return $specialPageLinker;
	}

	/**
	 * @return MockObject|SpecialPageLinker
	 */
	private function newSpecialPageLinker() {
		return $this->createMock( SpecialPageLinker::class );
	}

}
