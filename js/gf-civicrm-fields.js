/* Placeholder for JS */

if ( typeof gform !== 'undefined' ) {
    // Default the "Show values" option to true
    gform.addAction( 'gform_post_set_field_property', function ( name, field, value, previousValue ) {
        if ( name === 'civicrmOptionGroup' ) {
            enableFieldChoiceValues();
        }
    } );

    // Enable the "Show Values" option if a CiviCRM Source was selected
    jQuery(document).on('gform_load_field_settings', function( event, field, form ){
        if ( field['civicrmOptionGroup'] && field['civicrmOptionGroup'] != '' ) {
            enableFieldChoiceValues();
        }
    });

    function enableFieldChoiceValues() {
        var field_choice_values_enabled = jQuery('#field_choice_values_enabled');

        field_choice_values_enabled.prop( "checked", true );
        field_choice_values_enabled["enableChoiceValue"] = true;

        ToggleChoiceValue();
    }
}