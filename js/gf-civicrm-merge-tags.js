'use strict';

const {__, _x, _n, _nx} = wp.i18n;

const compose_merge_tags = function ( mergeTags ) {
	const tags = mergeTags.custom.tags.filter( ({ tag }) => tag.startsWith( '{civicrm_fp.' ) );
	mergeTags.form_processor = ({ label: __( 'Form Processor', 'gf-civicrm' ), tags });
	mergeTags.custom.tags = mergeTags.custom.tags.filter( ({ tag }) => !tag.startsWith( '{civicrm_fp.' ) );

	return mergeTags;
}

if ( typeof gform !== 'undefined' ) {
	gform.addFilter?.( 'gform_merge_tags', compose_merge_tags );
}
