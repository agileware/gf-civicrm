document.addEventListener("DOMContentLoaded", function () {
    console.log('loaded gf-civicrm-wpcmrf');

    const civicrm_rest_connection = document.getElementById('civicrm_rest_connection');
    const resultsContainer = document.getElementById('api-checks-results');

    if (!civicrm_rest_connection) {
        return;
    }

    // Define all the checks to perform
    const checks = [
        { id: 'settings', label: 'Retrieve Settings' },
        { id: 'groups', label: 'Retrieve Groups' },
        { id: 'countries', label: 'Retrieve Countries & States' },
        // Add more checks here as you implement them in PHP
    ];

    civicrm_rest_connection.addEventListener('change', function(event) {
        let selectedValue = event.target.value || '_local_civi_';

        resultsContainer.innerHTML = '<h4>API Pre-flight Checks</h4><ul></ul>';
        const list = resultsContainer.querySelector('ul');

        checks.forEach(check => {
            const listItemHTML = `
                <li id="check-${check.id}" class="api-check-item pending">
                    <span class="api-check-icon">⏳</span>
                    <span class="api-check-label">${check.label}</span>
                    <span class="api-check-message"></span>
                </li>`;
            // Append the new list item's HTML to the list.
            list.insertAdjacentHTML('beforeend', listItemHTML);
        });

        resultsContainer.style.display = 'block';

        // Create an array of promises, one for each check (this part is already vanilla JS).
        const promises = checks.map(check => {
            const formData = new URLSearchParams();
            formData.append('action', 'check_civi_connection');
            formData.append('security', gf_civicrm_ajax_data.nonce);
            formData.append('profile', selectedValue);
            formData.append('check_type', check.id);

            return fetch(gf_civicrm_ajax_data.ajax_url, {
                method: 'POST',
                body: formData,
            }).then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.statusText}`);
                }
                return response.json();
            }).then(data => {
                if (!data.success) {
                    throw new Error(data.data.message);
                }
                return data.data;
            });
        });

        // Run all promises and update the UI as they complete.
        Promise.allSettled(promises).then(results => {
            results.forEach((result, index) => {
                const check = checks[index];
                const listItem = document.getElementById(`check-${check.id}`);

                listItem.classList.remove('pending');
                const iconSpan = listItem.querySelector('.api-check-icon');
                const messageSpan = listItem.querySelector('.api-check-message');

                if (result.status === 'fulfilled') {
                    // The promise was successful.
                    listItem.classList.add('success');
                    iconSpan.textContent = '✅';
                    messageSpan.textContent = 'OK';
                } else {
                    // The promise failed (network error or API error).
                    listItem.classList.add('failed');
                    iconSpan.textContent = '❌';
                    messageSpan.textContent = result.reason.message;
                }
            });
        });

        /**
         * Working, single AJAX call
        const formData = new URLSearchParams();
        formData.append('action', 'check_civi_connection');
        formData.append('profile', selectedValue);
        formData.append('security', gf_civicrm_ajax_data.nonce);

        // Use the Fetch API to send the data to WordPress.
        fetch(gf_civicrm_ajax_data.ajax_url, {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json()) // Parse the JSON response from PHP
        .then(data => {
            if (data.success) {
                console.log('Success:', data.data.message);
            } else {
                console.error('Error:', data.data.message);
            }
        })
        .catch(error => {
            console.error('Request failed:', error);
        });
        */
    });
});