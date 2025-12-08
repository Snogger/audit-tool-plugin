jQuery(document).ready(function($) {
    /* ---------------------------------------------------------
     * LOGO UPLOAD / REMOVE
     * ------------------------------------------------------ */
    $('#audit-tool-upload-logo').on('click', function(e) {
        e.preventDefault();

        const uploader = wp.media({
            title: 'Select Logo',
            button: { text: 'Use this image' },
            multiple: false
        });

        uploader.on('select', function() {
            const attachment = uploader.state().get('selection').first().toJSON();
            $('#audit_tool_logo_id').val(attachment.id);
            $('#audit-tool-logo-preview').attr('src', attachment.url).show();
            $('#audit-tool-remove-logo').show();
        });

        uploader.open();
    });

    $('#audit-tool-remove-logo').on('click', function(e) {
        e.preventDefault();
        $('#audit_tool_logo_id').val('');
        $('#audit-tool-logo-preview').hide();
        $(this).hide();
    });

    /* ---------------------------------------------------------
     * SETTINGS LOCK / UNLOCK (Edit / Cancel)
     * ------------------------------------------------------ */
    const $form = $('form[action="options.php"]');

    if ($form.length) {
        // All editable controls EXCEPT hidden fields.
        const $inputs = $form.find('input:not([type="hidden"]), textarea, select');

        // The main submit button(s).
        const $submitButtons = $form.find('input[type="submit"], button[type="submit"]');

        // Container for our edit/cancel buttons.
        const $controlsWrapper = $('<p class="audit-tool-settings-actions"></p>');

        const $editBtn = $('<button/>', {
            type: 'button',
            id: 'audit_tool_settings_edit',
            class: 'button button-primary',
            text: 'Edit settings'
        });

        const $cancelBtn = $('<button/>', {
            type: 'button',
            id: 'audit_tool_settings_cancel',
            class: 'button',
            text: 'Cancel',
            style: 'margin-left:8px; display:none;'
        });

        $controlsWrapper.append($editBtn).append($cancelBtn);

        // Insert our buttons just above the form.
        $form.before($controlsWrapper);

        // Initial state: everything locked.
        function lockSettings() {
            $inputs.prop('disabled', true);
            $submitButtons.prop('disabled', true);
            $editBtn.show();
            $cancelBtn.hide();
        }

        // Editing state: inputs + save enabled.
        function unlockSettings() {
            $inputs.prop('disabled', false);
            $submitButtons.prop('disabled', false);
            $editBtn.hide();
            $cancelBtn.show();
        }

        // Lock immediately on load.
        lockSettings();

        // Edit -> unlock fields.
        $editBtn.on('click', function(e) {
            e.preventDefault();
            unlockSettings();
        });

        // Cancel -> reload page, discarding unsaved changes.
        $cancelBtn.on('click', function(e) {
            e.preventDefault();
            // Easiest safe reset: just reload the settings page.
            window.location.reload();
        });
    }
});
