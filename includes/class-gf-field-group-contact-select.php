<?php

use Civi\Api4\OptionGroup;
use const GFCiviCRM\BEFORE_CHOICES_SETTING;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class GF_Field_Group_Contact_Select extends GF_Field {

  /**
   * @var string $type The field type.
   */
  public $type = 'group_contact_select';

  /**
   * Return the field title, for use in the form editor.
   *
   * @return string
   */
  public function get_form_editor_field_title() {
    return esc_attr__('CiviCRM Group Contact Select', 'gf-civicrm');
  }

  /**
   * Assign the field button to the Advanced Fields group.
   *
   * @return array
   */
  public function get_form_editor_button() {
    return array(
      'group' => 'advanced_fields',
      'text'  => $this->get_form_editor_field_title(),
    );
  }

  /**
   * Add CiviCRM Source Group field setting to Gravity Forms editor, General Settings
   *
   * @param   int  $position  The position the settings should be located at.
   * @param   int  $form_id   The ID of the form currently being edited.
   *
   * @throws \API_Exception
   *
   */

  public static function field_standard_settings(int $position, int $form_id) {

    // BEFORE_CHOICES_SETTING determines the position of the field settings on the form
    if ($position != BEFORE_CHOICES_SETTING) return;

    if (!civicrm_initialize()) {
      return;
    }

    try {
      $groups = \Civi\Api4\Group::get(FALSE)
                                ->addSelect('id', 'title')
                                ->addWhere('is_active', '=', TRUE)
                                ->addOrderBy('title', 'ASC')
                                ->execute();

      if(class_exists('\Civi\Api4\SavedSearch')) {
	      $savedSearches = \Civi\Api4\SavedSearch::get( false )
	                                             ->addSelect( 'id', 'label' )
	                                             ->addWhere( 'api_entity', '=', 'Contact' )
	                                             ->addWhere( 'is_current', '=', true )
	                                             ->execute();
      }

      // This class: group_contact_select_setting must be added to the get_form_editor_field_settings array to be displayed for this field.
      // JS onchange function required to store the selected value when the form is saved. See get_form_editor_inline_script_on_page_render
      ?>
      <li class="group_contact_select_setting field_setting">
        <label for="civicrm_group">
          <?php esc_html_e('CiviCRM Source Group', 'gf-civicrm'); ?>
        </label>
        <select id="civicrm_group" onchange="SetCiviCRMGroupSetting(jQuery(this).val());">
          <option value=""><?php esc_html_e('None'); ?></option>
          <optgroup label="<?php esc_html_e('Active CiviCRM Groups', 'gf-civicrm'); ?>">
            <?php
            foreach ($groups as $group) {
              echo "<option value=\"{$group['id']}\">{$group['title']} (ID: {$group['id']})</option>";
            }
            ?>
          </optgroup>
          <?php if($savedSearches && ($savedSearches->count() > 0)): ?>
            <optgroup label="<?php esc_html_e('Saved Searches', 'gf-civicrm'); ?>">
                <?php foreach($savedSearches as $search) {
                    echo "<option value=\"ss:{$search['id']}\">{$search['label']} (Search ID: {$search['id']})</option>";
                } ?>
            </optgroup>
          <?php endif;?>
        </select>
      </li>
      <?php
    }
    catch (API_Exception $e) {
      $errorMessage = $e->getMessage();
      CRM_Core_Error::debug_var('Get list of active CiviCRM Groups', $errorMessage);
      return;
    }
  }


  public function get_form_editor_inline_script_on_page_render() {
    // Loads the saved value for the field
    // TODO Should the field civicrm_group be linked to the field type in some way? That way it would not be declared separately
    $js = sprintf( '
	        ( function( $ ) {
		        $( document ).bind( "gform_load_field_settings", function( event, field ) {
		            if( GetInputType( field ) == "%s" ) {
		                $( "#civicrm_group" ).val( field.civicrm_group );		                
		            }
		        } );
		    } )( jQuery );',
      $this->type
    );

    // Saves the selected value for the field
    $js .= "function SetCiviCRMGroupSetting(value) { SetFieldProperty('civicrm_group', value); }" . PHP_EOL;

    return $js;
  }

  /**
   * Returns the field's form editor description.
   *
   * @since 2.5
   *
   * @return string
   */
  public function get_form_editor_field_description() {
    return esc_attr__( 'Allows users to select a contact from a group of contacts.', 'gf-civicrm');
  }

  /**
   * Returns the field's form editor icon.
   *
   * This could be an icon url or a gform-icon class.
   *
   * @since 2.5
   *
   * @return string
   */
  public function get_form_editor_field_icon() {
    return 'gform-icon--dropdown';
  }

  function get_form_editor_field_settings() {
    return array(
      'conditional_logic_field_setting',
      'prepopulate_field_setting',
      'error_message_setting',
      // TODO implement the chosen functionality for select fields
      // 'enable_enhanced_ui_setting',
      'label_setting',
      'label_placement_setting',
      'admin_label_setting',
      'size_setting',
      'choices_setting',
      'rules_setting',
      'placeholder_setting',
      'default_value_setting',
      'visibility_setting',
      // Disabled this option as not "possible" to have duplicate Contact IDs in a CiviCRM Group
      // 'duplicate_setting',
      'description_setting',
      'css_class_setting',
      'autocomplete_setting',
      'group_contact_select_setting',
    );
  }

  public function is_conditional_logic_supported() {
    return true;
  }

  public function get_field_input( $form, $value = '', $entry = null ) {
    $form_id         = absint( $form['id'] );
    $is_entry_detail = $this->is_entry_detail();
    $is_form_editor  = $this->is_form_editor();

    $id       = $this->id;
    $field_id = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";

    $size                   = $this->size;
    $class_suffix           = $is_entry_detail ? '_admin' : '';
    $class                  = $size . $class_suffix;
    $css_class              = trim( esc_attr( $class ) . ' gfield_select' );
    $tabindex               = $this->get_tabindex();
    $disabled_text          = $is_form_editor ? 'disabled="disabled"' : '';
    $required_attribute     = $this->isRequired ? 'aria-required="true"' : '';
    $invalid_attribute      = $this->failed_validation ? 'aria-invalid="true"' : 'aria-invalid="false"';
    $describedby_attribute = $this->get_aria_describedby();
    $autocomplete_attribute = $this->enableAutocomplete ? $this->get_field_autocomplete_attribute() : '';

    return sprintf( "<div class='ginput_container ginput_container_select'><select name='input_%d' id='%s' class='%s' $tabindex $describedby_attribute %s %s %s %s>%s</select></div>", $id, $field_id, $css_class, $disabled_text, $required_attribute, $invalid_attribute, $autocomplete_attribute, $this->get_choices( $value ) );

  }

  public function get_choices( $value ) {
    return $this->group_contact_select_options( $this, $value );
  }

  public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {
    $return = esc_html( $value );
    return GFCommon::selection_display( $return, $this, $entry['currency'] );
  }

  /*
   * Return the list of contacts for the selected group
   */

  public function group_contact_select_options($field, $value = '', $support_placeholders = TRUE) {
    // Define empty value to return
    $empty_option = "<option value=''></option>";

    // If no group has been selected, then return empty
    if (!isset($this->civicrm_group)) {
      return $empty_option;
    }

    // No CiviCRM, then return empty
    if (!civicrm_initialize()) {
      return $empty_option;
    }

    try {
      // Fetch the contacts for the selected group
      // TODO It would be nice to be able to define which name column to use for contacts: display_name, sort_name, first name and last name etc.

      if( preg_match('/^ss:(?<id>\d+)$/', $field['civicrm_group'], $m) ) {
        // Group is actually a saved search, use saved search parameters
        $savedSearch = \Civi\Api4\SavedSearch::get( FALSE )
          ->addWhere('id', '=', $m['id'])
          ->execute()
          ->first();

        $api_params                     = $savedSearch['api_params'];
        $api_params['checkPermissions'] = false;
        $api_params['select']           = [ 'id', 'sort_name' ];
        $api_params['orderBy']          = array_merge(
          [ 'sort_name' => 'ASC' ],
          $api_params['orderBy'] ?? []
        );
        $groupContacts                  = civicrm_api4( 'Contact', 'get', $api_params );
      }
      else {
        $groupContacts = \Civi\Api4\Contact::get( false )
                                           ->addSelect( 'id', 'sort_name' )
                                           ->addWhere( 'groups', 'IN', $field['civicrm_group'] )
                                           ->addWhere( 'is_deleted', '=', false )
                                           ->addOrderBy( 'sort_name', 'ASC' )
                                           ->execute();
      }

      // Initialise the choices field if not already set
      if (empty($field->choices[0]['value'])) {
        $field->choices = [];
      }

      foreach ($groupContacts as $groupContact) {
        $field->choices[] = [
          'text'       => $groupContact['sort_name'],
          'value'      => (string) $groupContact['id'],
          'isSelected' => FALSE,
          'price'      => '',
        ];
      }
    }
    catch (API_Exception $e) {
      $errorMessage = $e->getMessage();
      CRM_Core_Error::debug_var('Get contacts for a Groups', $errorMessage);
      return $empty_option;
    }

    // Return the select options using the Gravity Forms common select function
    return GFCommon::get_select_choices($field, $value, $support_placeholders);
  }

  /**
   * Validates the field inputs.
   *
   * @access public
   *
   * @used-by GFFormDisplay::validate()
   *
   * @param array|string $value The field value or values to validate.
   * @param array        $form  The Form Object.
   *
   * @return void
   */
  public function validate($value, $form) {
    // If the field is required
    if ($this->isRequired) {
      // Then check that the value selected is a number (assuming this is a contact id)
      if (!is_numeric($value)) {
        $this->failed_validation  = TRUE;
        $this->validation_message = esc_attr__('This field is required.', 'gf-civicrm');
      }
    }
  }

  /**
   * Gets merge tag values.
   *
   * @since  Unknown
   * @access public
   *
   * @uses GFCommon::to_money()
   * @uses GFCommon::format_post_category()
   * @uses GFFormsModel::is_field_hidden()
   * @uses GFFormsModel::get_choice_text()
   * @uses GFCommon::format_variable_value()
   * @uses GFCommon::implode_non_blank()
   *
   * @param array|string $value      The value of the input.
   * @param string       $input_id   The input ID to use.
   * @param array        $entry      The Entry Object.
   * @param array        $form       The Form Object
   * @param string       $modifier   The modifier passed.
   * @param array|string $raw_value  The raw value of the input.
   * @param bool         $url_encode If the result should be URL encoded.
   * @param bool         $esc_html   If the HTML should be escaped.
   * @param string       $format     The format that the value should be.
   * @param bool         $nl2br      If the nl2br function should be used.
   *
   * @return string The processed merge tag.
   */
  public function get_value_merge_tag( $value, $input_id, $entry, $form, $modifier, $raw_value, $url_encode, $esc_html, $format, $nl2br ) {
    $modifiers       = $this->get_modifiers();
    $use_value       = in_array( 'value', $modifiers );
    $format_currency = ! $use_value && in_array( 'currency', $modifiers );
    $use_price       = $format_currency || ( ! $use_value && in_array( 'price', $modifiers ) );

    if ( is_array( $raw_value ) && (string) intval( $input_id ) != $input_id ) {
      $items = array( $input_id => $value ); // Float input Ids. (i.e. 4.1 ). Used when targeting specific checkbox items.
    } elseif ( is_array( $raw_value ) ) {
      $items = $raw_value;
    } else {
      $items = array( $input_id => $raw_value );
    }

    $ary = array();

    foreach ( $items as $input_id => $item ) {
      if ( $use_value ) {
        [ $val, $price ] = rgexplode( '|', $item, 2 );
      } elseif ( $use_price ) {
        [ $name, $val ] = rgexplode( '|', $item, 2 );
        if ( $format_currency ) {
          $val = GFCommon::to_money( $val, rgar( $entry, 'currency' ) );
        }
      } elseif ( $this->type == 'post_category' ) {
        $use_id     = strtolower( $modifier ) == 'id';
        $item_value = GFCommon::format_post_category( $item, $use_id );

        $val = RGFormsModel::is_field_hidden( $form, $this, array(), $entry ) ? '' : $item_value;
      } else {
        $val = RGFormsModel::is_field_hidden( $form, $this, array(), $entry ) ? '' : RGFormsModel::get_choice_text( $this, $raw_value, $input_id );
      }

      $ary[] = GFCommon::format_variable_value( $val, $url_encode, $esc_html, $format );
    }

    return GFCommon::implode_non_blank( ', ', $ary );
  }

  public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
    $return = esc_html( $value );
    return GFCommon::selection_display( $return, $this, $currency, $use_text );
  }

  public function get_value_export( $entry, $input_id = '', $use_text = false, $is_csv = false ) {
    if ( empty( $input_id ) ) {
      $input_id = $this->id;
    }

    $value = rgar( $entry, $input_id );

    return $is_csv ? $value : GFCommon::selection_display( $value, $this, rgar( $entry, 'currency' ), $use_text );
  }

  /**
   * Strips all tags from the input value.
   *
   * @param string $value The field value to be processed.
   * @param int $form_id The ID of the form currently being processed.
   *
   * @return string
   */
  public function sanitize_entry_value( $value, $form_id ) {

    $value = wp_strip_all_tags( $value );

    return $value;
  }

  /**
   * Returns the filter operators for the current field.
   *
   * @since 2.4
   *
   * @return array
   */
  public function get_filter_operators() {
    $operators = $this->type == 'product' ? array( 'is' ) : array( 'is', 'isnot', '>', '<' );

    return $operators;
  }

}

GF_Fields::register( new GF_Field_Group_Contact_Select() );
