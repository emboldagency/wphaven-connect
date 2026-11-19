jQuery(document).ready(function($) {
    const settings = window.wphavenSupport || {};
    const $form = $('#wphaven-support-form');
    const $submitBtn = $('#wphaven-submit-btn');
    const $messages = $('#wphaven-support-messages');
    const $successMessage = $('#wphaven-success-message');
    const $fileInput = $('#wphaven-attachments');
    const $fileList = $('#wphaven-attachment-list');
    const submitLabel = $submitBtn.text().trim();
    const allowedExtensions = (settings.allowedExtensions || []).map(function(ext) {
        return String(ext).toLowerCase();
    });

    function formatBytes(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '') + ' MB';
        }
        if (bytes >= 1024) {
            return Math.round(bytes / 1024) + ' KB';
        }
        return bytes + ' B';
    }

    function selectedFiles() {
        return $fileInput.length ? Array.from($fileInput[0].files || []) : [];
    }

    function showErrors(errors) {
        const $notice = $('<div class="notice notice-error"></div>');
        errors.forEach(function(error) {
            $notice.append($('<p></p>').text(error));
        });
        $messages.empty().append($notice);
    }

    function fileErrors(files) {
        const errors = [];
        let total = 0;

        if (settings.maxFiles && files.length > settings.maxFiles) {
            errors.push('Attach up to ' + settings.maxFiles + ' files.');
        }

        files.forEach(function(file) {
            const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
            total += file.size;

            if (allowedExtensions.length && !allowedExtensions.includes(extension)) {
                errors.push(file.name + ' is not a file type that can be attached. Use an image, PDF, .txt or .log file.');
            } else if (settings.maxBytes && file.size > settings.maxBytes) {
                errors.push(file.name + ' is larger than ' + formatBytes(settings.maxBytes) + '.');
            }
        });

        if (!errors.length && settings.maxTotalBytes && total > settings.maxTotalBytes) {
            errors.push('The files add up to more than this server accepts (' + formatBytes(settings.maxTotalBytes) + ' in total).');
        }

        return errors;
    }

    function renderFileList() {
        $fileList.empty();

        selectedFiles().forEach(function(file) {
            $fileList.append(
                $('<li></li>')
                    .append($('<span class="wphaven-attachment-name"></span>').text(file.name))
                    .append($('<span class="wphaven-attachment-size"></span>').text(formatBytes(file.size)))
            );
        });
    }

    $fileInput.on('change', function() {
        renderFileList();

        const errors = fileErrors(selectedFiles());
        if (errors.length) {
            showErrors(errors);
        } else {
            $messages.empty();
        }
    });

    $form.on('submit', function(e) {
        e.preventDefault();

        // Clear previous messages
        $messages.empty();
        $successMessage.hide();

        const files = selectedFiles();
        const errors = fileErrors(files);
        if (errors.length) {
            showErrors(errors);
            return;
        }

        // Disable submit button and show loading
        $submitBtn.prop('disabled', true).text(files.length ? 'Uploading files...' : 'Creating ticket...');

        // Send only the chosen files, not the browser's empty entry when there are none.
        const formData = new FormData($form[0]);
        formData.delete('attachments[]');
        files.forEach(function(file) {
            formData.append('attachments[]', file, file.name);
        });
        formData.append('nonce', settings.nonce);

        // The action rides in the URL so WordPress still routes the request when
        // PHP drops an oversized form, and the handler can say why.
        $.ajax({
            url: settings.ajax_url + (settings.ajax_url.indexOf('?') === -1 ? '?' : '&') + 'action=wphaven_support_ticket',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
        }).done(function(response) {
            if (response && response.success) {
                // Clear form fields and show success message
                $form[0].reset();
                renderFileList();
                $successMessage.show();

                // Hide success message after 5 seconds
                setTimeout(function() {
                    $successMessage.fadeOut();
                }, 5000);
            } else {
                showErrors([(response && response.data && response.data.message) || 'An error occurred. Please try again.']);
            }
        }).fail(function() {
            showErrors(['Network error. Please check your connection and try again.']);
        }).always(function() {
            // Re-enable submit button
            $submitBtn.prop('disabled', false).text(submitLabel);
        });
    });
});
