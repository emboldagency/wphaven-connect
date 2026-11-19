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

    // Files chosen so far; each pick adds to this instead of replacing it, so files can come from several folders.
    let chosenFiles = [];

    function fileKey(file) {
        return file.name + '|' + file.size + '|' + file.lastModified;
    }

    function selectedFiles() {
        return chosenFiles.slice();
    }

    function showErrors(errors) {
        const $notice = $('<div class="notice notice-error"></div>');
        errors.forEach(function(error) {
            $notice.append($('<p></p>').text(error));
        });
        $messages.empty().append($notice);
    }

    function formOverheadBytes() {
        let text = 0;
        $form.find('input[type="text"], input[type="email"], textarea').each(function() {
            text += new Blob([$(this).val() || '']).size;
        });
        return text + 64 * 1024;
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

        // Leave room for the text fields and multipart headers, which also count toward post_max_size.
        if (!errors.length && settings.maxTotalBytes && total + formOverheadBytes() > settings.maxTotalBytes) {
            errors.push('The files add up to more than this server accepts (' + formatBytes(settings.maxTotalBytes) + ' in total).');
        }

        return errors;
    }

    function renderFileList() {
        $fileList.empty();

        chosenFiles.forEach(function(file, index) {
            $fileList.append(
                $('<li></li>')
                    .append($('<span class="wphaven-attachment-name"></span>').text(file.name))
                    .append($('<span class="wphaven-attachment-size"></span>').text(formatBytes(file.size)))
                    .append(
                        $('<button type="button" class="button-link wphaven-attachment-remove">Remove</button>')
                            .attr('aria-label', 'Remove ' + file.name)
                            .data('index', index)
                    )
            );
        });
    }

    function filesChanged() {
        renderFileList();

        const errors = fileErrors(chosenFiles);
        if (errors.length) {
            showErrors(errors);
        } else {
            $messages.empty();
        }
    }

    $fileInput.on('change', function() {
        const known = chosenFiles.map(fileKey);
        Array.from(this.files || []).forEach(function(file) {
            if (!known.includes(fileKey(file))) {
                chosenFiles.push(file);
            }
        });

        // Empty the input so picking the same file again after removing it still fires change.
        this.value = '';
        filesChanged();
    });

    $fileList.on('click', '.wphaven-attachment-remove', function() {
        chosenFiles.splice($(this).data('index'), 1);
        filesChanged();
    });

    $('#wphaven-send-another').on('click', function() {
        $successMessage.hide();
        $form.show();
        $('#wphaven-subject').trigger('focus');
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
                const subject = $('#wphaven-subject').val();
                let summary = '"' + subject + '"';
                if (files.length) {
                    summary += ' with ' + files.length + (files.length === 1 ? ' attachment' : ' attachments');
                }
                $('#wphaven-success-summary').text(summary);

                // The confirmation stays until the user asks for a new form, so it can't be missed during a long upload.
                $form[0].reset();
                chosenFiles = [];
                renderFileList();
                $form.hide();
                $successMessage.show();
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
