if ( typeof gform !== 'undefined' ) {
    // Default the "Show values" option to true
    gform.addAction( 'gform_post_set_field_property', function ( name, field, value, previousValue ) {
        if ( name === 'civicrmOptionGroup' ) {
            var field_choice_values_enabled = jQuery('#field_choice_values_enabled');
            field_choice_values_enabled.prop( "checked", true );
            field_choice_values_enabled["enableChoiceValue"] = true;
            ToggleChoiceValue();
            SetFieldChoices();
        }
    } );
}