document.addEventListener("gform/theme/scripts_loaded", function () {
  console.log('hello');

  gform.addFilter("gform_is_value_match", function (isMatch, formId, rule) {
    // Helper function to parse relative date strings like "+1 week", "2 months ago", or "today".
    function parseRelativeDate(dateString) {
      const now = new Date();
      now.setHours(0, 0, 0, 0); // Normalize to the start of today.

      const lowerCaseDateString = dateString.toLowerCase();

      if (lowerCaseDateString === "today" || lowerCaseDateString === "now") {
        return now;
      }

      // A more robust regex to find the number and the unit.
      const regex = /(\d+)\s+(day|week|month|year)s?/;
      const match = lowerCaseDateString.match(regex);

      if (match) {
        // Determine if the direction is past or future.
        const isPast = lowerCaseDateString.includes("ago");
        const hasMinusSign = lowerCaseDateString.startsWith("-");
        const sign = isPast || hasMinusSign ? -1 : 1;

        const value = parseInt(match[1], 10);
        const unit = match[2];

        if (unit === "day") now.setDate(now.getDate() + value * sign);
        if (unit === "week") now.setDate(now.getDate() + value * 7 * sign);
        if (unit === "month") now.setMonth(now.getMonth() + value * sign);
        if (unit === "year") now.setFullYear(now.getFullYear() + value * sign);

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
      const fieldContainer = jQuery("#field_" + formId + "_" + fieldId);

      // Case 1: Date Picker (or simple text input)
      if (
        fieldContainer.find(".ginput_container_date").length > 0 &&
        fieldContainer.find('input[type="text"]').length > 0
      ) {
        return fieldContainer.find('input[type="text"]').val();
      }

      // Case 2: Date Dropdowns
      if (
        fieldContainer.find(".ginput_container_date").length > 0 &&
        fieldContainer.find("select").length > 0
      ) {
        const month = fieldContainer.find("select").eq(0).val();
        const day = fieldContainer.find("select").eq(1).val();
        const year = fieldContainer.find("select").eq(2).val();

        if (month && day && year) {
          return `${month}/${day}/${year}`;
        }
      }

      return ""; // Return empty if no value found
    }

    // Get the date from the field and the rule.
    const fieldValueString = getFieldValue(formId, rule.fieldId);
    const ruleValueDate = parseRelativeDate(rule.value);

    console.log(fieldValueString, ruleValueDate);
    console.log(rule);

    // If we can't parse either date, the condition fails.
    if (!fieldValueString || !ruleValueDate) {
      console.log("here");
      return false;
    }

    const fieldValueDate = new Date(fieldValueString);
    if (isNaN(fieldValueDate.getTime())) {
      console.log("here 2");
      return false; // User input is not a valid date
    }
    fieldValueDate.setHours(0, 0, 0, 0); // Normalize to the start of the day.

    // 5. Perform the actual comparison.
    isMatch = false;
    switch (rule.operator) {
      case "is":
        isMatch = fieldValueDate.getTime() === ruleValueDate.getTime();
        break;
      case "isnot":
        isMatch = fieldValueDate.getTime() !== ruleValueDate.getTime();
        break;
      case "greaterThan":
      case ">":
        isMatch = fieldValueDate > ruleValueDate;

        console.log("greaterThan", fieldValueDate, ruleValueDate, isMatch);
        break;
      case "lessThan":
      case "<":
        isMatch = fieldValueDate < ruleValueDate;

        console.log("lessThan", fieldValueDate, ruleValueDate, isMatch);
        break;
    }

    return isMatch;
  });
});
