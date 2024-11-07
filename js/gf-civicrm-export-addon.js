(function () {
    'use strict';

    const { __ } = wp.i18n;

    const button = document.createElement( 'button' );

    button.classList.add( 'button' );

    button.formAction = gf_civicrm_export_addon_strings.action;

    button.append( __( 'Export Form & Feeds to Server' ) );

    document.addEventListener(
        'DOMContentLoaded',
        () => document.querySelector( '#tab_export_form .gform-settings-panel__content' )?.appendChild(button)
    );
})();