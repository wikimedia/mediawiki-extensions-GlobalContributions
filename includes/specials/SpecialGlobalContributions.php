<?php

class SpecialGlobalContributions extends FormSpecialPage {

	protected $title;

	protected $user;

	public function __construct() {
		parent::__construct( 'GlobalContributions' );
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setMethod( 'GET' );
		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getPageTitle() ); // Strip subpage
		$form->setContext( $context );
	}

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

	public function onSubmit( array $data ) {
		// @todo Given that we're overriding a lot, figure out
		// if we should just use a normal SpecialPage
		$form = $this->getForm();
		$form->mFieldData = $data; // Well, this works!
		$form->displayForm( false );

		$out = $this->getOutput();

		$name = $data['user'];
		$user = User::newFromName( $name );
		if ( !$user && !IP::isIPAddress( $name ) ) {
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

	protected function getGroupName() {
		return 'users';
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}
}
