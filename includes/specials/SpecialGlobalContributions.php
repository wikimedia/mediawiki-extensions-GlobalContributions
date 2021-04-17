<?php

use Wikimedia\IPUtils;

class SpecialGlobalContributions extends FormSpecialPage {

	/** @var Title */
	protected $title;

	/** @var User */
	protected $user;

	public function __construct() {
		parent::__construct( 'GlobalContributions' );
	}

	/**
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setMethod( 'GET' );
		$context = new DerivativeContext( $this->getContext() );
		// Strip subpage
		$context->setTitle( $this->getPageTitle() );
		$form->setContext( $context );
	}

	/** @inheritDoc */
	protected function getFormFields() {
		return [
			'user' => [
				'section' => 'legend',
				'type' => 'text',
				'name' => 'user',
				'default' => $this->par,
				'label-message' => 'guc-form-user',
			],
		];
	}

	/**
	 * @param array $data
	 * @return bool true
	 */
	public function onSubmit( array $data ) {
		// @todo Given that we're overriding a lot, figure out
		// if we should just use a normal SpecialPage
		$form = $this->getForm();
		// Well, this works!
		$form->mFieldData = $data;
		$form->displayForm( false );

		$out = $this->getOutput();

		$name = $data['user'];
		$user = User::newFromName( $name );
		if ( !$user && !IPUtils::isIPAddress( $name ) ) {
			if ( trim( $name ) ) {
				// If they just visit the page with no input,
				// don't show any error.
				$out->addWikiMsg( 'guc-invalid-username' );
			}
			return true;
		}
		$user = User::newFromName( $name, false );

		$guc = new GlobalUserContribs( $user, $this->getContext() );
		$out->addHTML( $guc->getHtml() );

		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
	}

	/** @inheritDoc */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
