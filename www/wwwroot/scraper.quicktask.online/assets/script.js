document.addEventListener('DOMContentLoaded', () => {
    setupSelectAll('select-all', 'listings-form');
    setupSelectAll('select-all-archive', 'archive-form');
    enforceSelectionBeforeSubmit('listings-form');
    enforceSelectionBeforeSubmit('archive-form');
    setupExportButtons();
});

function setupSelectAll(checkboxId, formId) {
    const checkbox = document.getElementById(checkboxId);
    const form = document.getElementById(formId);
    if (!checkbox || !form) {
        return;
    }
    checkbox.addEventListener('change', () => {
        const checked = checkbox.checked;
        form.querySelectorAll('input[name="selected[]"]').forEach((input) => {
            input.checked = checked;
        });
    });
}

function enforceSelectionBeforeSubmit(formId) {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }
    form.addEventListener('submit', (event) => {
        const anySelected = form.querySelector('input[name="selected[]"]:checked');
        if (!anySelected) {
            event.preventDefault();
            alert('Select at least one listing first.');
        }
    });
}

function setupExportButtons() {
    document.querySelectorAll('[data-export="selected"]').forEach((button) => {
        button.addEventListener('click', () => {
            const status = button.getAttribute('data-status');
            const form = button.closest('form');
            if (!form) {
                return;
            }
            const selected = Array.from(form.querySelectorAll('input[name="selected[]"]:checked')).map((input) => input.value);
            if (!selected.length) {
                alert('Select at least one listing to export.');
                return;
            }
            let exportForm;
            if (status === 'archived') {
                exportForm = document.getElementById('archive-export-form');
            } else {
                exportForm = document.getElementById('export-form');
            }
            if (!exportForm) {
                return;
            }
            exportForm.querySelector('#' + (status === 'archived' ? 'archive-export-ids' : 'export-ids')).value = selected.join(',');
            exportForm.submit();
        });
    });
}
