document.addEventListener('DOMContentLoaded', () => {
    // Toggle existing vs. new guest inputs on reservation creation
    const modeExisting = document.getElementById('mode_existing');
    const modeNew = document.getElementById('mode_new');
    const existingSelect = document.getElementById('existing_guest_id');
    const newGuestFields = ['full_name', 'phone', 'email', 'id_type', 'id_number']
        .map((id) => document.getElementById(id))
        .filter(Boolean);

    function syncGuestMode() {
        if (!modeExisting || !modeNew || !existingSelect) return;
        const isNew = modeNew.checked;
        existingSelect.disabled = isNew;
        newGuestFields.forEach((el) => {
            el.disabled = !isNew;
        });
    }

    if (modeExisting && modeNew) {
        modeExisting.addEventListener('change', syncGuestMode);
        modeNew.addEventListener('change', syncGuestMode);
        syncGuestMode();
    }

    // Confirm cancellation actions
    document.querySelectorAll('form button[name="confirm_cancel"]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            if (!window.confirm('Cancel this reservation and release its reserved room nights?')) {
                e.preventDefault();
            }
        });
    });
});