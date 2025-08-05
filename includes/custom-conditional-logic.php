<?php

/**
 * Gravity Forms Advanced Date Conditional Logic
 *
 */

if (! class_exists('GFForms')) {
  die();
}

/**
 * Set date fields to enable conditional logic.
 * 
 * If the form only has a date field, we'll run into an issue with being unable to add conditional 
 * logic. The gform_conditional_logic_fields JavaScript filter won't run.
 */
add_filter('gform_admin_pre_render', function ($form) {
  add_action('admin_footer', function () {
  ?>
    <script>
      gform.addFilter('gform_is_conditional_logic_field', function(isLogicField, field) {
        if (field.type === 'date') {
          return true;
        }
        return isLogicField;
      });
    </script>
  <?php
  });
  return $form;
});


/**
 * Add date fields as options for Conditional Logic.
 */
add_action('admin_print_scripts', function () {
  if (method_exists('GFForms', 'is_gravity_page') && GFForms::is_gravity_page()) { ?>
    <script type="text/javascript">
      // Add date fields to conditional logic field options
      gform.addFilter('gform_conditional_logic_fields', function(options, form, selectedFieldId) {

        // Add date fields to options
        form.fields.forEach(function(field) {
          if (field.type === 'date') {
            const alreadyIncluded = options.some(function(option) {
              return option.value === field.id;
            });

            if (!alreadyIncluded) {
              options.push({
                label: field.adminLabel?.trim() ? field.adminLabel : field.label,
                value: field.id
              });
            }
          }
        });

        // Sort options by the order of fields in the form
        options.sort(function(a, b) {
          const indexA = form.fields.findIndex(f => f.id === a.value);
          const indexB = form.fields.findIndex(f => f.id === b.value);
          return indexA - indexB;
        });

        return options;
      });

      gform.addFilter('gform_conditional_logic_operators', function (operators, objectType, fieldId) {
        // do stuff
        console.log(operators);
        console.log(objectType);
        console.log(fieldId);

        // Get current form object and find the field
        var field = GetFieldById(fieldId);
        if (!field || field.inputType !== 'date') {
          return operators;
        }

        return operators;
      });
    </script>
  <?php }
});

// Add the custom operators to the list of conditional logic options for date fields.
add_filter( 'gform_conditional_logic_operators', function ( $operators ) {
  $date_operators = [
      'date_is'                  => __( 'is (date)', 'gravityforms' ),
      'date_isnot'               => __( 'is not (date)', 'gravityforms' ),
      'date_greater_than'        => __( 'is after (date)', 'gravityforms' ),
      'date_greater_than_or_eq'  => __( 'is on or after (date)', 'gravityforms' ),
      'date_less_than'           => __( 'is before (date)', 'gravityforms' ),
      'date_less_than_or_eq'     => __( 'is on or before (date)', 'gravityforms' ),
  ];

  // Add these operators to a new group called 'Date Operators'.
  $operators['date_operators'] = $date_operators;

  return $operators;
} );

add_action('wp_footer', 'gfcv_gform_date_logic_script', 100);
add_action('gform_preview_footer', 'gfcv_gform_date_logic_script', 100); // optional for preview

function gfcv_gform_date_logic_script()
{
  // Only inject if conditional logic is loaded (GF does this check)
  if (! wp_script_is('gform_gravityforms', 'enqueued') || ! wp_script_is('gform_conditional_logic', 'enqueued')) {
    return;
  }

  ?>
  <script>
    gform.addFilter('gform_is_value_match', function(isMatch, formId, rule) {

      // Helper function to parse relative date strings like "+1 week", "2 months ago", or "today".
      function parseRelativeDate(dateString) {
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Normalize to the start of today.

        const lowerCaseDateString = dateString.toLowerCase();

        if (lowerCaseDateString === 'today' || lowerCaseDateString === 'now') {
          return now;
        }

        // A more robust regex to find the number and the unit.
        const regex = /(\d+)\s+(day|week|month|year)s?/;
        const match = lowerCaseDateString.match(regex);

        if (match) {
          // Determine if the direction is past or future.
          const isPast = lowerCaseDateString.includes('ago');
          const hasMinusSign = lowerCaseDateString.startsWith('-');
          const sign = (isPast || hasMinusSign) ? -1 : 1;

          const value = parseInt(match[1], 10);
          const unit = match[2];

          if (unit === 'day') now.setDate(now.getDate() + (value * sign));
          if (unit === 'week') now.setDate(now.getDate() + (value * 7 * sign));
          if (unit === 'month') now.setMonth(now.getMonth() + (value * sign));
          if (unit === 'year') now.setFullYear(now.getFullYear() + (value * sign));

          return now;
        }

        // Fallback for static dates like "1/1/2026".
        const staticDate = new Date(dateString);
        if (isNaN(staticDate.getTime())) return null; // Invalid date format
        staticDate.setHours(0, 0, 0, 0);
        return staticDate;
      }

      // Helper function to get the value from any kind of date field.
      function getFieldValue(formId, fieldId) {
        const fieldContainer = jQuery('#field_' + formId + '_' + fieldId);

        // Case 1: Date Picker (or simple text input)
        if (fieldContainer.find('.ginput_container_date').length > 0 && fieldContainer.find('input[type="text"]').length > 0) {
          console.log('inside 1');
          return fieldContainer.find('input[type="text"]').val();
        }

        // Case 2: Date Dropdowns
        if (fieldContainer.find('.ginput_container_date').length > 0 && fieldContainer.find('select').length > 0) {
          const month = fieldContainer.find('select').eq(0).val();
          const day = fieldContainer.find('select').eq(1).val();
          const year = fieldContainer.find('select').eq(2).val();

          if (month && day && year) {
            console.log('inside 2');
            return `${month}/${day}/${year}`;
          }
        }

        return ''; // Return empty if no value found
      }

      // ----- Main Processing -----

      // Get the date from the field and the rule.
      const fieldValueString = getFieldValue(formId, rule.fieldId);
      const ruleValueDate = parseRelativeDate(rule.value);

      // If we can't parse either date, the condition fails.
      if (!fieldValueString || !ruleValueDate) {
        return false;
      }

      const fieldValueDate = new Date(fieldValueString);
      if (isNaN(fieldValueDate.getTime())) {
        return false; // User input is not a valid date
      }
      fieldValueDate.setHours(0, 0, 0, 0); // Normalize to the start of the day.

      // 5. Perform the actual comparison.
      isMatch = false;
      switch (rule.operator) {
        case 'is':
          isMatch = (fieldValueDate.getTime() === ruleValueDate.getTime());
          break;
        case 'isnot':
          isMatch = (fieldValueDate.getTime() !== ruleValueDate.getTime());
          break;
        case 'greaterThan':
        case '>':
          isMatch = (fieldValueDate > ruleValueDate);
          break;
        case 'lessThan':
        case '<':
          isMatch = (fieldValueDate < ruleValueDate);
          break;
      }

      return isMatch;
    });
  </script>
<?php
}
