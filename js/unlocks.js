document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('claim-modal');
    const closeModal = document.querySelector('.close-modal');

    if (closeModal && modal) {
        closeModal.addEventListener('click', () => {
            modal.style.display = 'none';
        });
        window.addEventListener('click', (e) => {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    if (typeof window.initClaimModalCategoryButtons === 'function') {
        window.initClaimModalCategoryButtons();
    }
    if (typeof window.loadUnlocks === 'function') {
        window.loadUnlocks();
    }
    if (typeof window.loadStreak === 'function') {
        window.loadStreak();
    }
});
