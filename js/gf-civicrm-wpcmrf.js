document.addEventListener("DOMContentLoaded", function () {
    const civicrm_rest_connection = document.getElementById('civicrm_rest_connection');
    const resultsContainer = document.getElementById('api-checks-results');
    const civicrm_api_settings = document.getElementById('gform-settings-section-gfcv-api-settings');
    const civicrm_api_settings_help = document.querySelector('#gform-settings-section-gfcv-api-settings .gform-settings-field__html');

    // Only display the help text to enable CMRF if CMRF is not enabled
    if (civicrm_rest_connection && civicrm_api_settings_help) {
        civicrm_api_settings_help.style.display = 'none';
    }

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

    // Handle pre-flight checks to test the connection and API user
    function handle_preflight_checks(selectedValue) {
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
                let message = 'OK';
                if (check.id === 'payment_tokens') {
                    message += ' - CAUTION: Remote installations are not supported at this time.';
                }
                messageSpan.textContent = message;
            })
            .catch(error => {
                // --- FAILED ---
                listItem.classList.remove('pending');
                listItem.classList.add('failed');
                iconSpan.innerHTML = '<span class="dashicons dashicons-no" style="color: red;"></span>';
                messageSpan.textContent = error.message;
            });
        });
    }

    // Handle enable/disable Site Key and API Key settings when connection profile is selected
    function toggle_site_and_api_key(selectedValue) {
        if (!civicrm_api_settings) {
            return;
        }

        if (!selectedValue) {
            civicrm_api_settings.style.display = 'block';
        }

        const formData = new URLSearchParams();
        formData.append('action', 'check_connection_profile_type');
        formData.append('security', gf_civicrm_ajax_data.nonce);
        formData.append('profile', selectedValue);

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
                // This is an API-level error (e.g. bad permissions).
                throw new Error(data.data.message);
            }
            if (data.data === "local") {
                civicrm_api_settings.style.display = 'block';
            } else {
                civicrm_api_settings.style.display = 'none';
            }
        })
        .catch(error => {
            // This is an API-level error (e.g. missing connection profile).
            civicrm_api_settings.style.display = 'block'; // Display the settings fields as a fallback
            throw new Error(data.data.message);
        });
    }
    
    civicrm_rest_connection.addEventListener('change', function(event) {
        let selectedValue = event.target.value || '';

        toggle_site_and_api_key(selectedValue);

        // Do nothing else if "None" is selected
        if (!selectedValue) {
            resultsContainer.innerHTML = '';
            return;
        }

        handle_preflight_checks(selectedValue);
    });
});