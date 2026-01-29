<?php

namespace GFCiviCRM;

use GFAPI;
use CRM_Core_Exception;

/**
 * Replaces the default_fp tag with the value of the default form processor form setting, if it exists.
 * Do this in pre_render for the $form context, which is not passed to gform_replace_merge_tags for default values.
 * 
 * @param $form
 * 
 * @return mixed
 */
function replace_default_fp( $form ) {
	if ( !class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		return $form; // do nothing
	}

	$form_settings    = FieldsAddOn::get_instance()->get_form_settings( $form );
	$default_fp_value = rgar( $form_settings, 'default_fp' );

	// Only proceed if the setting has a value.
	if ( empty( $default_fp_value ) ) {
		return $form;
	}

	/**
	 * A closure that takes a string by reference and replaces the
	 * 'default_fp' placeholder with the actual value from form settings.
	 *
	 * @param ?string &$string_to_update The string to process.
	 */
	$replacer = function ( ?string &$string_to_update ) use ( $default_fp_value ) {
		if ( empty( $string_to_update ) || strpos( $string_to_update, '.default_fp.' ) === false ) {
			return;
		}

		$string_to_update = preg_replace_callback(
			'/{ (civicrm_fp(?:_default)?) \. default_fp \. ([[:alnum:]_]+) }/x',
			function ( $matches ) use ( $default_fp_value ) {
				// Reconstruct the merge tag with the real value.
				return sprintf(
					'{%1$s.%2$s.%3$s}',
					$matches[1],
					$default_fp_value,
					$matches[2]
				);
			},
			$string_to_update
		);
	};

	foreach ( $form['fields'] as &$field ) {
		// Process the default value for the main field object.
		if ( isset( $field->defaultValue ) ) {
			$replacer( $field->defaultValue );
		}

		if ( 'html' === $field->type ) {
			$replacer( $field->content );
		}

		// Process default values for address sub-fields (which are in an array).
		if ( 'address' === $field->type && ! empty( $field->inputs ) ) {
			foreach ( $field->inputs as &$input ) {
				// The defaultValue for sub-fields is an array key.
				if ( isset( $input['defaultValue'] ) ) {
					$replacer( $input['defaultValue'] );
				}
			}
		}
	}

	return $form;
}
add_filter( 'gform_pre_render', 'GFCiviCRM\replace_default_fp', 10, 1 );

/**
 * Add setting for CiviCRM Source to Gravity Forms editor standard settings.
 * Allows you to select a Form Processor input as the source for values for a Gravity Forms field.
 *
 * @param int $position
 * @param int $form_id
 *
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function civicrm_optiongroup_setting( $position, $form_id ) {
	$profile_name = get_rest_connection_profile( $form_id );

	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return;
	}

	switch ( $position ) {
		case BEFORE_CHOICES_SETTING:
      		$api_params = [
				'return' => ['name', 'title'], // Specify the fields to return
			];
			$api_options = [
				'check_permissions' => 0, // Set check_permissions to false
				'sort'              => 'title ASC',
				'limit'             => 0,
			];
      		$option_groups = api_wrapper( $profile_name, 'OptionGroup', 'get', $api_params, $api_options ) ?? [];

			try {
        		$form_processors = api_wrapper($profile_name, 'FormProcessorInstance', 'get', [ 'sequential' => 1], [ 'limit' => 0]) ?? [];

				$form_processors = array_filter( array_map( function ( $processor ) use ( $option_groups ) {
					$mapped = [
						'name'    => $processor['name'],
						'title'   => $processor['title'],
						'options' => [],
					];

					foreach ( $processor['inputs'] as $input ) {
						$type = &$input['type']['name'];

						if ( in_array( $type, [
							'OptionGroup',
							'CustomOptionListType',
							'YesNoOptionList',
							'MailingGroup',
							'Tag',
						] ) ) {
							$mapped['options'][ $input['name'] ] = $input['title'];
						}
					}

					return ! empty( $mapped['options'] ) ? $mapped : FALSE;
				}, $form_processors ) );
			} catch ( CRM_Core_Exception $e ) {
				// Form processor extension may not be installed, ignore
        		$form_processors = [];
			}

			// Build the list of options for the default_fp tag.
			// If the default_fp form setting has a value, populate with the values for the defined form processor.
			// Otherwise, do not add anything to the list of options.
			$form = GFAPI::get_form( $form_id );
			$form_settings    = FieldsAddOn::get_instance()->get_form_settings( $form );
			$default_fp_value = rgar( $form_settings, 'default_fp' );

			if ( $default_fp_value ) {
				$default_fp_options = reset(array_filter( $form_processors, fn($fp) => $fp['name'] === $default_fp_value ));
			}

			?>
			<li class="civicrm_optiongroup_setting field_setting">
				<label for="civicrm_optiongroup_selector">
					<?php esc_html_e( 'CiviCRM Source', 'gf-civicrm' ); ?>
				</label>
				<select id="civicrm_optiongroup_selector"
				        onchange="SetCiviCRMOptionGroup(this)">
					<option value=""><?php esc_html_e( 'None' ); ?></option>
					<?php if ( $default_fp_options ): ?>
						<optgroup label="DEFAULT Form Processor: <?php echo $default_fp_options['title']; ?>">
							<?php foreach ( $default_fp_options['options'] as $pr_name => $pr_title ) {
								echo "<option value=\"civicrm_fp__{$default_fp_options['name']}__{$pr_name}\">{$pr_title}</option>";
							} ?>
						</optgroup>
					<?php endif; ?>
					<?php foreach ( $form_processors as $processor ): ?>
						<optgroup label="Form Processor: <?php echo $processor['title']; ?>">
							<?php foreach ( $processor['options'] as $pr_name => $pr_title ) {
								echo "<option value=\"civicrm_fp__{$processor['name']}__{$pr_name}\">{$pr_title}</option>";
							} ?>
						</optgroup>
					<?php endforeach; ?>
					<optgroup label="Option Groups">
						<?php foreach ( $option_groups as $group ) {
							echo "<option value=\"civicrm__{$group['name']}\">" . sprintf( __( '%1$s (ID: %2$u)', 'gf-civicrm' ), $group['title'], $group['id'] ) . "</option>";
						} ?>
					</optgroup>
				</select>
			</li>
			<?php
			break;
	}
}

add_action( 'gform_field_standard_settings', 'GFCiviCRM\civicrm_optiongroup_setting', 10, 2 );