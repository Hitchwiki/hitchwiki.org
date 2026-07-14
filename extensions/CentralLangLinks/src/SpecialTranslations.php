<?php

namespace MediaWiki\Extension\CentralLangLinks;

use MediaWiki\Html\Html;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;

/**
 * Special:Translations — browser UI to manage the central page_translations
 * table. Look up a concept (by its English title or any language's page title),
 * then edit the set of per-language page titles. English is the concept key.
 *
 * Restricted to the 'managetranslations' right (sysops by default).
 */
class SpecialTranslations extends SpecialPage {

	/** @var string Concept key currently being edited (for the save callback). */
	private string $conceptKey = '';

	public function __construct() {
		parent::__construct( 'Translations', 'managetranslations' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'wiki';
	}

	/** @inheritDoc */
	public function execute( $sub ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->checkPermissions();

		$input = trim( $this->getRequest()->getText( 'concept', (string)( $sub ?? '' ) ) );
		$this->showLookupForm( $input );

		if ( $input === '' ) {
			$this->showBrowse();
			return;
		}

		$this->conceptKey = $this->resolveConcept( $input );
		$this->showEditForm( $this->conceptKey );
	}

	/**
	 * Resolve user input to a concept key: an existing concept, or the concept
	 * of an existing per-language title, or (failing both) a new English key.
	 */
	private function resolveConcept( string $input ): string {
		$key = strtr( trim( $input ), ' ', '_' );
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		$asConcept = $dbr->newSelectQueryBuilder()
			->select( 'pt_concept' )->from( 'page_translations' )
			->where( [ 'pt_concept' => $key ] )->limit( 1 )
			->caller( __METHOD__ )->fetchField();
		if ( $asConcept !== false ) {
			return $asConcept;
		}
		$asTitle = $dbr->newSelectQueryBuilder()
			->select( 'pt_concept' )->from( 'page_translations' )
			->where( [ 'pt_title' => $key ] )->limit( 1 )
			->caller( __METHOD__ )->fetchField();
		if ( $asTitle !== false ) {
			return $asTitle;
		}
		return $key;
	}

	/** @return \stdClass[] current rows for a concept, ordered by language */
	private function loadRows( string $concept ): array {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		return iterator_to_array( $dbr->newSelectQueryBuilder()
			->select( [ 'pt_lang', 'pt_title' ] )->from( 'page_translations' )
			->where( [ 'pt_concept' => $concept ] )->orderBy( 'pt_lang' )
			->caller( __METHOD__ )->fetchResultSet() );
	}

	private function showLookupForm( string $value ): void {
		$html = Html::rawElement( 'form',
			[ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL() ],
			Html::element( 'label', [ 'for' => 'cll-concept' ],
				$this->msg( 'centrallanglinks-lookup-label' )->text() )
			. ' '
			. Html::input( 'concept', $value, 'text',
				[ 'id' => 'cll-concept', 'size' => 50 ] )
			. ' '
			. Html::element( 'button', [ 'type' => 'submit' ],
				$this->msg( 'centrallanglinks-lookup-button' )->text() )
		);
		$this->getOutput()->addHTML( $html );
	}

	private function showBrowse(): void {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$count = (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(DISTINCT pt_concept)' )->from( 'page_translations' )
			->caller( __METHOD__ )->fetchField();
		$concepts = $dbr->newSelectQueryBuilder()
			->select( 'pt_concept' )->distinct()->from( 'page_translations' )
			->orderBy( 'pt_concept' )->limit( 100 )
			->caller( __METHOD__ )->fetchFieldValues();

		$out = $this->getOutput();
		$out->addWikiMsg( 'centrallanglinks-browse', $this->getLanguage()->formatNum( $count ) );
		$items = '';
		foreach ( $concepts as $c ) {
			$items .= Html::rawElement( 'li', [], Html::element( 'a',
				[ 'href' => $this->getPageTitle()->getLocalURL( [ 'concept' => $c ] ) ],
				strtr( $c, '_', ' ' ) ) );
		}
		$out->addHTML( Html::rawElement( 'ul', [], $items ) );
	}

	private function showEditForm( string $concept ): void {
		$out = $this->getOutput();
		if ( $this->getRequest()->getCheck( 'saved' ) ) {
			$out->addHTML( Html::successBox(
				$this->msg( 'centrallanglinks-saved', strtr( $concept, '_', ' ' ) )->parse() ) );
		}

		$rows = $this->loadRows( $concept );
		$default = '';
		foreach ( $rows as $r ) {
			$default .= $r->pt_lang . ':' . strtr( $r->pt_title, '_', ' ' ) . "\n";
		}
		if ( $default === '' ) {
			$default = 'en:' . strtr( $concept, '_', ' ' ) . "\n";
			$out->addHTML( Html::noticeBox(
				$this->msg( 'centrallanglinks-new', strtr( $concept, '_', ' ' ) )->parse(), '' ) );
		} else {
			$out->addWikiMsg( 'centrallanglinks-editing',
				strtr( $concept, '_', ' ' ), count( $rows ) );
		}

		$form = \HTMLForm::factory( 'ooui', [
			'conceptkey' => [ 'type' => 'hidden', 'default' => $concept ],
			'links' => [
				'type' => 'textarea',
				'label-message' => 'centrallanglinks-links-label',
				'help-message' => 'centrallanglinks-links-help',
				'rows' => 14,
				'default' => $default,
			],
		], $this->getContext() );
		// POST back to a URL that keeps the concept in the query string; otherwise
		// execute() sees an empty 'concept' on submit, falls into showBrowse() and
		// returns before onSave() ever runs, so the save silently no-ops.
		$form->setAction( $this->getPageTitle()->getLocalURL( [ 'concept' => $concept ] ) );
		$form->setFormIdentifier( 'cll-edit' )
			->setWrapperLegendMsg( 'centrallanglinks-legend' )
			->setSubmitTextMsg( 'centrallanglinks-save' )
			->setSubmitCallback( [ $this, 'onSave' ] );

		$result = $form->show();
		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			// Post/redirect/get so the reloaded form reflects the stored state.
			$out->redirect( $this->getPageTitle()->getLocalURL(
				[ 'concept' => $concept, 'saved' => 1 ] ) );
		}
	}

	/**
	 * Validate the submitted lines and replace the concept's rows.
	 *
	 * @param array $data
	 * @return Status|true
	 */
	public function onSave( array $data ) {
		$concept = strtr( trim( (string)( $data['conceptkey'] ?? '' ) ), ' ', '_' );
		if ( $concept === '' ) {
			return Status::newFatal( 'centrallanglinks-error-noconcept' );
		}
		$family = array_fill_keys( array_keys( $GLOBALS['hwLanguages'] ?? [] ), true );

		$rows = [];
		$seen = [];
		foreach ( preg_split( '/\r?\n/', (string)( $data['links'] ?? '' ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) !== 2 || trim( $parts[1] ) === '' ) {
				return Status::newFatal( 'centrallanglinks-error-badline', $line );
			}
			$lang = trim( $parts[0] );
			if ( !isset( $family[$lang] ) ) {
				return Status::newFatal( 'centrallanglinks-error-badlang', $lang );
			}
			if ( isset( $seen[$lang] ) ) {
				return Status::newFatal( 'centrallanglinks-error-duplang', $lang );
			}
			$seen[$lang] = true;
			$rows[] = [
				'pt_concept' => $concept,
				'pt_lang' => $lang,
				'pt_title' => strtr( trim( $parts[1] ), ' ', '_' ),
			];
		}

		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$dbw->startAtomic( __METHOD__ );
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_translations' )
			->where( [ 'pt_concept' => $concept ] )
			->caller( __METHOD__ )->execute();
		if ( $rows ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'page_translations' )
				->rows( $rows )
				->caller( __METHOD__ )->execute();
		}
		$dbw->endAtomic( __METHOD__ );

		$this->logChange( $concept, $rows );

		return true;
	}

	/**
	 * Record the change in Special:Log/translations (and RecentChanges) so
	 * on-wiki translation edits are attributed and auditable.
	 *
	 * @param string $concept
	 * @param array[] $rows The rows just written (may be empty = cleared).
	 */
	private function logChange( string $concept, array $rows ): void {
		$langs = array_map( static fn ( $r ) => $r['pt_lang'], $rows );
		$target = Title::newFromText( $concept ) ?: $this->getPageTitle( $concept );

		$logEntry = new ManualLogEntry( 'translations', 'update' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $target );
		$logEntry->setParameters( [
			'4::count' => count( $rows ),
			'5::langs' => $this->getLanguage()->commaList( $langs ) ?: '-',
		] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );
	}
}
