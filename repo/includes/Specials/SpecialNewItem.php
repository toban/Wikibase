<?php

namespace Wikibase\Repo\Specials;

use Message;
use OutputPage;
use SiteLookup;
use Status;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Term\Term;
use Wikibase\Lib\SettingsArray;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Summary;
use Wikibase\Repo\CopyrightMessageBuilder;
use Wikibase\Repo\EditEntity\MediawikiEditEntityFactory;
use Wikibase\Repo\Specials\HTMLForm\HTMLAliasesField;
use Wikibase\Repo\Specials\HTMLForm\HTMLContentLanguageField;
use Wikibase\Repo\Specials\HTMLForm\HTMLTrimmedTextField;
use Wikibase\Repo\Store\TermsCollisionDetector;
use Wikibase\Repo\SummaryFormatter;
use Wikibase\Repo\Validators\TermValidatorFactory;

/**
 * Page for creating new Wikibase items.
 *
 * @license GPL-2.0-or-later
 * @author John Erling Blad < jeblad@gmail.com >
 */
class SpecialNewItem extends SpecialNewEntity {

	public const FIELD_LANG = 'lang';
	public const FIELD_LABEL = 'label';
	public const FIELD_DESCRIPTION = 'description';
	public const FIELD_ALIASES = 'aliases';
	public const FIELD_SITE = 'site';
	public const FIELD_PAGE = 'page';

	/**
	 * @var SiteLookup
	 */
	private $siteLookup;

	/**
	 * @var TermValidatorFactory
	 */
	private $termValidatorFactory;

	/**
	 * @var TermsCollisionDetector
	 */
	private $termsCollisionDetector;

	public function __construct(
		array $tags,
		SpecialPageCopyrightView $copyrightView,
		EntityNamespaceLookup $entityNamespaceLookup,
		SummaryFormatter $summaryFormatter,
		EntityTitleLookup $entityTitleLookup,
		MediawikiEditEntityFactory $editEntityFactory,
		SiteLookup $siteLookup,
		TermValidatorFactory $termValidatorFactory,
		TermsCollisionDetector $termsCollisionDetector
	) {
		parent::__construct(
			'NewItem',
			'createpage',
			$tags,
			$copyrightView,
			$entityNamespaceLookup,
			$summaryFormatter,
			$entityTitleLookup,
			$editEntityFactory
		);
		$this->siteLookup = $siteLookup;
		$this->termValidatorFactory = $termValidatorFactory;
		$this->termsCollisionDetector = $termsCollisionDetector;
	}

	public static function factory(
		SiteLookup $siteLookup,
		MediawikiEditEntityFactory $editEntityFactory,
		EntityNamespaceLookup $entityNamespaceLookup,
		EntityTitleLookup $entityTitleLookup,
		TermsCollisionDetector $itemTermsCollisionDetector,
		SettingsArray $repoSettings,
		SummaryFormatter $summaryFormatter,
		TermValidatorFactory $termValidatorFactory
	): self {
		$copyrightView = new SpecialPageCopyrightView(
			new CopyrightMessageBuilder(),
			$repoSettings->getSetting( 'dataRightsUrl' ),
			$repoSettings->getSetting( 'dataRightsText' )
		);

		return new self(
			$repoSettings->getSetting( 'specialPageTags' ),
			$copyrightView,
			$entityNamespaceLookup,
			$summaryFormatter,
			$entityTitleLookup,
			$editEntityFactory,
			$siteLookup,
			$termValidatorFactory,
			$itemTermsCollisionDetector
		);
	}

	/**
	 * @see SpecialNewEntity::doesWrites
	 *
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @see SpecialNewEntity::createEntityFromFormData
	 *
	 * @param array $formData
	 *
	 * @return Item
	 */
	protected function createEntityFromFormData( array $formData ) {
		$languageCode = $formData[ self::FIELD_LANG ];

		$item = new Item();
		$item->setLabel( $languageCode, $formData[ self::FIELD_LABEL ] );
		$item->setDescription( $languageCode, $formData[ self::FIELD_DESCRIPTION ] );

		$item->setAliases( $languageCode, $formData[ self::FIELD_ALIASES ] );

		if ( isset( $formData[ self::FIELD_SITE ] ) ) {
			$site = $this->siteLookup->getSite( $formData[ self::FIELD_SITE ] );
			$normalizedPageName = $site->normalizePageName( $formData[ self::FIELD_PAGE ] );

			$item->getSiteLinkList()->addNewSiteLink( $site->getGlobalId(), $normalizedPageName );
		}

		return $item;
	}

	/**
	 * @return array[]
	 */
	protected function getFormFields() {
		$formFields = [
			self::FIELD_LANG => [
				'name' => self::FIELD_LANG,
				'class' => HTMLContentLanguageField::class,
				'id' => 'wb-newentity-language',
			],
			self::FIELD_LABEL => [
				'name' => self::FIELD_LABEL,
				'default' => $this->parts[0] ?? '',
				'class' => HTMLTrimmedTextField::class,
				'id' => 'wb-newentity-label',
				'placeholder-message' => 'wikibase-label-edit-placeholder',
				'label-message' => 'wikibase-newentity-label',
			],
			self::FIELD_DESCRIPTION => [
				'name' => self::FIELD_DESCRIPTION,
				'default' => $this->parts[1] ?? '',
				'class' => HTMLTrimmedTextField::class,
				'id' => 'wb-newentity-description',
				'placeholder-message' => 'wikibase-description-edit-placeholder',
				'label-message' => 'wikibase-newentity-description',
			],
			self::FIELD_ALIASES => [
				'name' => self::FIELD_ALIASES,
				'class' => HTMLAliasesField::class,
				'id' => 'wb-newentity-aliases',
			],
		];

		$request = $this->getRequest();
		if ( $request->getCheck( self::FIELD_SITE ) && $request->getCheck( self::FIELD_PAGE ) ) {
			$formFields[ self::FIELD_SITE ] = [
				'name' => self::FIELD_SITE,
				'default' => $request->getVal( self::FIELD_SITE ),
				'type' => 'text',
				'id' => 'wb-newitem-site',
				'readonly' => 'readonly',
				'validation-callback' => function ( $siteId, $formData ) {
					$site = $this->siteLookup->getSite( $siteId );

					if ( $site === null ) {
						return [ $this->msg( 'wikibase-newitem-not-recognized-siteid' )->text() ];
					}

					return true;
				},
				'label-message' => 'wikibase-newitem-site'
			];

			$formFields[ self::FIELD_PAGE ] = [
				'name' => self::FIELD_PAGE,
				'default' => $request->getVal( self::FIELD_PAGE ),
				'type' => 'text',
				'id' => 'wb-newitem-page',
				'readonly' => 'readonly',
				'validation-callback' => function ( $pageName, $formData ) {
					$siteId = $formData['site'];
					$site = $this->siteLookup->getSite( $siteId );
					if ( $site === null ) {
						return true;
					}

					$normalizedPageName = $site->normalizePageName( $pageName );
					if ( $normalizedPageName === false ) {
						return [
							$this->msg(
								'wikibase-newitem-no-external-page',
								$siteId,
								$pageName
							)->text(),
						];
					}

					return true;
				},
				'label-message' => 'wikibase-newitem-page'
			];
		}

		return $formFields;
	}

	/**
	 * @see SpecialNewEntity::getLegend
	 *
	 * @return string|Message $msg Message key or Message object
	 */
	protected function getLegend() {
		return $this->msg( 'wikibase-newitem-fieldset' );
	}

	/**
	 * @see SpecialNewEntity::getWarnings
	 *
	 * @return string[]
	 */
	protected function getWarnings() {
		if ( $this->getUser()->isAnon() ) {
			return [
				$this->msg( 'wikibase-anonymouseditwarning', $this->msg( 'wikibase-entity-item' ) )->parse(),
			];
		}

		return [];
	}

	/**
	 * @param array $formData
	 *
	 * @return Status
	 */
	protected function validateFormData( array $formData ) {
		if ( $formData[ self::FIELD_LABEL ] == ''
			 && $formData[ self::FIELD_DESCRIPTION ] == ''
			 && $formData[ self::FIELD_ALIASES ] === []
		) {
			return Status::newFatal( 'wikibase-newitem-insufficient-data' );
		}

		// Disallow the same label and description, but ignore if both are empty T100933
		if ( $formData[ self::FIELD_LABEL ] !== '' &&
			$formData[ self::FIELD_LABEL ] === $formData[ self::FIELD_DESCRIPTION ]
		) {
			return Status::newFatal( 'wikibase-newitem-same-label-and-description' );
		}

		if ( $formData[self::FIELD_LABEL] != '' ) {
			$validator = $this->termValidatorFactory->getLabelValidator( $this->getEntityType() );
			$result = $validator->validate( $formData[self::FIELD_LABEL] );
			if ( !$result->isValid() ) {
				return $this->createStatusFromValidatorError( $result->getErrors()[0] );
			}
		}

		if ( $formData[self::FIELD_DESCRIPTION] != '' ) {
			$validator = $this->termValidatorFactory->getDescriptionValidator();
			$result = $validator->validate( $formData[self::FIELD_DESCRIPTION] );
			if ( !$result->isValid() ) {
				return $this->createStatusFromValidatorError( $result->getErrors()[0] );
			}
		}

		if ( $formData[self::FIELD_ALIASES] !== [] ) {
			$validator = $this->termValidatorFactory->getAliasValidator();
			foreach ( $formData[self::FIELD_ALIASES] as $alias ) {
				$result = $validator->validate( $alias );
				if ( !$result->isValid() ) {
					return $this->createStatusFromValidatorError( $result->getErrors()[0] );
				}
			}

			$result = $validator->validate( implode( '|', $formData[self::FIELD_ALIASES] ) );
			if ( !$result->isValid() ) {
				return $this->createStatusFromValidatorError( $result->getErrors()[0] );
			}
		}

		$collidingItemId = $this->termsCollisionDetector->detectLabelAndDescriptionCollision(
			$formData[ self::FIELD_LANG ],
			$formData[ self::FIELD_LABEL ],
			$formData[ self::FIELD_DESCRIPTION ]
		);
		if ( $collidingItemId !== null ) {
			return Status::newFatal(
				'wikibase-validator-label-with-description-conflict',
				$formData[ self::FIELD_LABEL ],
				$formData[ self::FIELD_LANG ],
				$collidingItemId
			);
		}

		return Status::newGood();
	}

	private function createStatusFromValidatorError( $error ) {
		$params = array_merge( [ 'wikibase-validator-' . $error->getCode() ],  $error->getParameters() );
		return Status::newFatal( ...$params );
	}

	/**
	 * @param Item $item
	 *
	 * @return Summary
	 * @suppress PhanParamSignatureMismatch Uses intersection types
	 */
	protected function createSummary( EntityDocument $item ) {
		$uiLanguageCode = $this->getLanguage()->getCode();

		$summary = new Summary( 'wbeditentity', 'create' );
		$summary->setLanguage( $uiLanguageCode );
		/** @var Term|null $labelTerm */
		$labelTerm = $item->getLabels()->getIterator()->current();
		/** @var Term|null $descriptionTerm */
		$descriptionTerm = $item->getDescriptions()->getIterator()->current();
		$summary->addAutoSummaryArgs(
			$labelTerm ? $labelTerm->getText() : '',
			$descriptionTerm ? $descriptionTerm->getText() : ''
		);

		return $summary;
	}

	protected function displayBeforeForm( OutputPage $output ) {
		parent::displayBeforeForm( $output );
		$output->addModules( 'wikibase.special.languageLabelDescriptionAliases' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getEntityType() {
		return Item::ENTITY_TYPE;
	}

}
