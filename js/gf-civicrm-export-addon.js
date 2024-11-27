document.addEventListener("DOMContentLoaded", function () {
    'use strict';

    const { __ } = wp.i18n;

    // Select all checkboxes inside the list
    const checkboxes = document.querySelectorAll('#import_form_list input[type="checkbox"]');
    const form_processor_checkboxes = document.querySelectorAll('#import_form_processors_list input[type="checkbox"]');

    // Add event listeners to each checkbox
    checkboxes.forEach(checkbox => {
        // These are the form processors related to the form we're trying to import
        var relatedFormProcessors = checkbox.dataset.formprocessors;
        if ( relatedFormProcessors.length > 0) {
            relatedFormProcessors = relatedFormProcessors.split(", ")
        }

        // Find related form processors in the list of import options
        var related_fp = null;
        for (let index = 0; index < form_processor_checkboxes.length; index++) {
            const fp_checkbox = form_processor_checkboxes[index];
            
            const checkboxValue = fp_checkbox.value;
            if ( ! relatedFormProcessors.includes(checkboxValue) ) {
                continue;
            }

            related_fp = fp_checkbox;
            break;
        }

        checkbox.addEventListener('click', (event) => {
            if (event.target.checked) {
                // Check the related form processor for import
                related_fp.checked = true;
            }
        });

        // Prepopulate the target forms to import with a best guess based on form name.
        // Note GF does not have machine names, and form titles are considered unique.

        // Extract the shared ID from the checkbox's ID
        const sharedId = checkbox.id.split('-').pop(); // Get the last part of the ID after the last '-'
        const selectId_form = `import-form-into-${sharedId}`;

        // Find the related select element
        const selectElement = document.getElementById(selectId_form);

        if (selectElement) {
            selectElement.value = checkbox.value;
        } else {
            console.log("Related select element not found.");
        }
    });

    form_processor_checkboxes.forEach(checkbox => {
        // Prepopulate the target form processorss to import with a best guess based on machine name.

        // Extract the machine from the checkbox's value
        const sharedName = checkbox.value;
        const selectId_form = `import-form-processor-into-${sharedName}`;

        // Find the related select element
        const selectElement = document.getElementById(selectId_form);

        if (selectElement) {
            selectElement.value = checkbox.value;
        } else {
            console.log("Related select element not found.");
        }
    });

    
});