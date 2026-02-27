// Sistem de notificări pentru Inventar.live

function showNotification(message, type = 'info', duration = 4000) {
    // Creează elementul de notificare
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    // Structura notificării
    notification.innerHTML = `
        <span class="notification-icon"></span>
        <span class="notification-text">${message}</span>
        <button class="notification-close" onclick="closeNotification(this)">×</button>
    `;
    
    // Adaugă în DOM
    document.body.appendChild(notification);
    
    // Auto-închidere după durata specificată
    if (duration > 0) {
        setTimeout(() => {
            closeNotification(notification.querySelector('.notification-close'));
        }, duration);
    }
}

function closeNotification(closeBtn) {
    const notification = closeBtn.closest('.notification');
    notification.classList.add('hiding');
    
    // Așteaptă animația să se termine
    setTimeout(() => {
        notification.remove();
    }, 300);
}

// Funcții helper pentru diferite tipuri
function showSuccess(message, duration = 4000) {
    showNotification(message, 'success', duration);
}

function showError(message, duration = 5000) {
    showNotification(message, 'error', duration);
}

function showInfo(message, duration = 4000) {
    showNotification(message, 'info', duration);
}

function showWarning(message, duration = 4500) {
    showNotification(message, 'warning', duration);
}

// Verifică dacă există mesaje de notificare în URL (pentru redirect-uri)
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    
    if (urlParams.has('success')) {
        showSuccess(decodeURIComponent(urlParams.get('success')));
        // Curăță URL-ul
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    if (urlParams.has('error')) {
        showError(decodeURIComponent(urlParams.get('error')));
        // Curăță URL-ul
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});