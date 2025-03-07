<?php

namespace Wikibase\Client\Hooks;

use Parser;
use ParserOutput;
use Wikibase\Client\NamespaceChecker;
use Wikibase\Client\WikibaseClient;

/**
 * Handles the NOEXTERNALLANGLINKS parser function.
 *
 * @license GPL-2.0-or-later
 * @author Nikola Smolenski <smolensk@eunet.rs>
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Daniel Kinzler
 */
class NoLangLinkHandler {

	/**
	 * @var NamespaceChecker
	 */
	private $namespaceChecker;

	/**
	 * Parser function
	 *
	 * @param Parser $parser
	 * @param string ...$langs Language codes or '*'
	 */
	public static function handle( Parser $parser, ...$langs ) {
		$handler = self::factory();
		$handler->doHandle( $parser, $langs );
	}

	private static function factory(): self {
		return new self( WikibaseClient::getNamespaceChecker() );
	}

	public function __construct( NamespaceChecker $namespaceChecker ) {
		$this->namespaceChecker = $namespaceChecker;
	}

	/**
	 * Get the noexternallanglinks page property from the ParserOutput,
	 * which is set by the {{#noexternallanglinks}} parser function.
	 *
	 * @param ParserOutput $out
	 *
	 * @return string[] A list of language codes, identifying which repository links to ignore.
	 *         Empty if {{#noexternallanglinks}} was not used on the page.
	 */
	public static function getNoExternalLangLinks( ParserOutput $out ) {
		$property = $out->getPageProperty( 'noexternallanglinks' );

		return is_string( $property ) ? unserialize( $property ) : [];
	}

	/**
	 * Set the noexternallanglinks page property in the ParserOutput,
	 * which is set by the {{#noexternallanglinks}} parser function.
	 *
	 * @param ParserOutput $out
	 * @param string[] $noexternallanglinks a list of languages to suppress
	 */
	public static function setNoExternalLangLinks( ParserOutput $out, array $noexternallanglinks ) {
		$out->setPageProperty( 'noexternallanglinks', serialize( $noexternallanglinks ) );
	}

	/**
	 * Parser function
	 *
	 * @param Parser $parser
	 * @param string[] $langs
	 *
	 * @return string
	 */
	public function doHandle( Parser $parser, array $langs ) {
		if ( !$this->namespaceChecker->isWikibaseEnabled( $parser->getTitle()->getNamespace() ) ) {
			// shorten out
			return '';
		}

		$output = $parser->getOutput();
		$nel = array_merge( self::getNoExternalLangLinks( $output ), $langs );

		self::setNoExternalLangLinks( $output, $nel );

		return '';
	}

}
