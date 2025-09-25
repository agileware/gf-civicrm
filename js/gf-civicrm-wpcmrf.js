document.addEventListener("DOMContentLoaded", function () {
    const civicrm_rest_connection = document.getElementById('civicrm_rest_connection');
    const resultsContainer = document.getElementById('api-checks-results');

    if (!civicrm_rest_connection) {
        return;
    }

    // Define all the checks to perform
    const checks = [
        { id: 'settings', label: 'Get Settings' },
        { id: 'validate_checksum', label: 'Validate Checksums' },
        { id: 'groups', label: 'Get Groups' },
        { id: 'option_groups', label: 'Get OptionGroups' },
        { id: 'countries', label: 'Get Countries & States' },
        { id: 'saved_searches', label: 'Get SavedSearches' },
        { id: 'formprocessor_getfields', label: 'Get FormProcessor fields' },
        { id: 'formprocessor_instance', label: 'Get FormProcessorInstances' },
        { id: 'formprocessor_defaults', label: 'Get FormProcessorDefaults' },
        { id: 'payment_processors', label: 'Get PaymentProcessors' },
        { id: 'payment_tokens', label: 'Get PaymentTokens' },
        // Add more checks here as you implement them in PHP
    ];

    civicrm_rest_connection.addEventListener('change', function(event) {
        let selectedValue = event.target.value || '';

        // Do nothing if "None" is selected
        if (empty(selectedValue)) {
            return;
        }

        resultsContainer.innerHTML = '<h4>API Pre-flight Checks</h4><p>Verfies the connection profile can establish baseline connection requirements.</p><ul></ul>';
        const list = resultsContainer.querySelector('ul');

        checks.forEach(check => {
            const listItemHTML = `
                <li id="check-${check.id}" class="api-check-item pending">
                    <span class="api-check-icon"><span class="dashicons dashicons-marker"></span></span>
                    <span class="api-check-label" style="font-weight: bold;">${check.label}</span>
                    <span class="api-check-message"></span>
                </li>`;
            // Append the new list item's HTML to the list.
            list.insertAdjacentHTML('beforeend', listItemHTML);
        });

        resultsContainer.style.display = 'block';

        checks.forEach(check => {
            // Get the specific list item for this check.
            const listItem = document.getElementById(`check-${check.id}`);
            const iconSpan = listItem.querySelector('.api-check-icon');
            const messageSpan = listItem.querySelector('.api-check-message');

            const formData = new URLSearchParams();
            formData.append('action', 'check_civi_connection');
            formData.append('security', gf_civicrm_ajax_data.nonce);
            formData.append('profile', selectedValue);
            formData.append('check_type', check.id);

            fetch(gf_civicrm_ajax_data.ajax_url, {
                method: 'POST',
                body: formData,
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    // This is an API-level error (e.g., bad permissions).
                    throw new Error(data.data.message);
                }
                
                // --- SUCCESS ---
                listItem.classList.remove('pending');
                listItem.classList.add('success');
                iconSpan.innerHTML = '<span class="dashicons dashicons-yes" style="color: green;"></span>';
                messageSpan.textContent = 'OK';
            })
            .catch(error => {
                // --- FAILED ---
                listItem.classList.remove('pending');
                listItem.classList.add('failed');
                iconSpan.innerHTML = '<span class="dashicons dashicons-no" style="color: red;"></span>';
                messageSpan.textContent = error.message;
            });
        });
    });
});