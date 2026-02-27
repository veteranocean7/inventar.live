/**
 * PWA Install Assistant pentru Inventar.live
 * Asistență instalare PWA pentru Android și iOS
 * Versiune: 1.0.0
 */

const PWAInstallAssistant = (function() {
    'use strict';

    // Variabile globale
    let deferredPrompt = null;
    let pwaInstalat = localStorage.getItem('pwa_instalat') === 'true';
    let pwaRefuzat = localStorage.getItem('pwa_refuzat');
    let esteIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    let esteStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    let modalCreat = false;

    /**
     * Inițializare
     */
    function init() {
        // Dacă rulează deja ca PWA, nu mai afișa modalul
        if (esteStandalone) {
            console.log('[PWA Install] Aplicația rulează în mod standalone');
            document.body.classList.add('pwa-standalone');
            localStorage.setItem('pwa_instalat', 'true');
            return;
        }

        // Creează modalul
        createModal();

        // Event pentru Android/Chrome
        window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

        // Event când aplicația e instalată
        window.addEventListener('appinstalled', handleAppInstalled);

        // Pentru iOS - afișează modal după încărcare
        if (esteIOS && !esteStandalone && !pwaInstalat) {
            scheduleModalDisplay(8000); // 8 secunde pentru iOS
        }

        console.log('[PWA Install] Asistent inițializat', { esteIOS, esteStandalone, pwaInstalat });
    }

    /**
     * Handler pentru beforeinstallprompt (Android/Chrome)
     */
    function handleBeforeInstallPrompt(e) {
        console.log('[PWA Install] beforeinstallprompt declanșat');
        e.preventDefault();
        deferredPrompt = e;

        // Afișează modal dacă nu a fost refuzat recent (24h)
        if (!esteStandalone && !pwaInstalat) {
            scheduleModalDisplay(5000); // 5 secunde pentru Android
        }
    }

    /**
     * Handler pentru appinstalled
     */
    function handleAppInstalled(evt) {
        console.log('[PWA Install] Aplicația a fost instalată');
        localStorage.setItem('pwa_instalat', 'true');
        deferredPrompt = null;
        hideModal();
    }

    /**
     * Programează afișarea modalului
     */
    function scheduleModalDisplay(delay) {
        let ultimRefuz = pwaRefuzat ? new Date(pwaRefuzat).getTime() : 0;
        let acum = new Date().getTime();
        let ore24 = 24 * 60 * 60 * 1000;

        console.log('[PWA Install] scheduleModalDisplay:', {
            delay: delay,
            pwaRefuzat: pwaRefuzat,
            ultimRefuz: ultimRefuz,
            acum: acum,
            diferenta: acum - ultimRefuz,
            ore24: ore24,
            vaAfisa: (acum - ultimRefuz > ore24)
        });

        if (acum - ultimRefuz > ore24) {
            console.log('[PWA Install] Programare afișare modal în ' + delay + 'ms');
            setTimeout(function() {
                showModal();
            }, delay);
        } else {
            console.log('[PWA Install] Modal blocat - refuzat recent (cooldown 24h)');
        }
    }

    /**
     * Creează elementele UI pentru modal
     */
    function createModal() {
        if (modalCreat) return;

        // Container modal
        const modal = document.createElement('div');
        modal.id = 'pwa-install-modal';
        modal.className = 'pwa-modal';
        modal.style.display = 'none';

        modal.innerHTML = `
            <div class="pwa-modal-content">
                <div class="pwa-modal-header">
                    <img src="icons/icon-96x96.png" alt="Inventar" class="pwa-logo">
                    <h3>Instalează Inventar.live</h3>
                </div>
                <div class="pwa-modal-body">
                    <p>Adaugă aplicația pe ecranul principal pentru acces rapid și funcționalitate offline.</p>
                    <div class="pwa-features">
                        <div class="pwa-feature">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>Acces rapid de pe ecranul principal</span>
                        </div>
                        <div class="pwa-feature">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>Funcționează și offline</span>
                        </div>
                        <div class="pwa-feature">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>Sincronizare automată</span>
                        </div>
                    </div>
                </div>
                <div class="pwa-modal-footer">
                    <button class="pwa-btn-install" id="pwa-btn-install">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Instalează
                    </button>
                    <button class="pwa-btn-later" id="pwa-btn-later">Mai târziu</button>
                </div>
                <!-- Instrucțiuni iOS -->
                <div id="pwa-ios-instructions" class="pwa-ios-guide" style="display: none;">
                    <p><strong>Pe iPhone/iPad:</strong></p>
                    <ol>
                        <li>Apasă pe <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> (Share)</li>
                        <li>Derulează și apasă "Add to Home Screen"</li>
                        <li>Apasă "Add"</li>
                    </ol>
                </div>
            </div>
        `;

        // Adaugă stiluri
        addStyles();

        // Adaugă în DOM
        document.body.appendChild(modal);

        // Bind evenimente
        document.getElementById('pwa-btn-install').addEventListener('click', installPWA);
        document.getElementById('pwa-btn-later').addEventListener('click', hideModal);

        modalCreat = true;
    }

    /**
     * Adaugă stiluri CSS pentru modal
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            /* Stiluri Modal PWA Install */
            .pwa-modal * {
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
            }
            .pwa-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                width: 100%;
                height: 100%;
                min-height: 100vh;
                min-height: -webkit-fill-available;
                background: rgba(0,0,0,0.6);
                z-index: 99999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                padding: env(safe-area-inset-top, 20px) env(safe-area-inset-right, 20px) env(safe-area-inset-bottom, 20px) env(safe-area-inset-left, 20px);
                animation: pwaFadeIn 0.3s ease;
                -webkit-overflow-scrolling: touch;
                touch-action: manipulation;
            }
            @keyframes pwaFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .pwa-modal-content {
                background: white;
                border-radius: 16px;
                max-width: 360px;
                width: calc(100% - 40px);
                margin: auto;
                padding: 24px;
                box-sizing: border-box;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: pwaSlideUp 0.3s ease;
            }
            @keyframes pwaSlideUp {
                from { transform: translateY(30px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            .pwa-modal-header {
                text-align: center;
                margin-bottom: 16px;
            }
            .pwa-logo {
                width: 64px;
                height: 64px;
                border-radius: 12px;
                margin-bottom: 12px;
            }
            .pwa-modal-header h3 {
                margin: 0;
                font-size: 1.3rem;
                color: #1e293b;
            }
            .pwa-modal-body p {
                color: #64748b;
                font-size: 0.95rem;
                line-height: 1.5;
                margin-bottom: 16px;
                text-align: center;
            }
            .pwa-features {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .pwa-feature {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 0.9rem;
                color: #475569;
            }
            .pwa-modal-footer {
                margin-top: 20px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .pwa-btn-install {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 14px 20px;
                background: linear-gradient(135deg, #007BFF 0%, #0056b3 100%);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
            }
            .pwa-btn-install:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 20px rgba(0, 123, 255, 0.4);
            }
            .pwa-btn-later {
                padding: 12px 20px;
                background: transparent;
                color: #64748b;
                border: none;
                font-size: 16px;
                cursor: pointer;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
            }
            .pwa-btn-later:hover {
                color: #1e293b;
            }
            .pwa-ios-guide {
                margin-top: 16px;
                padding: 12px;
                background: #f8fafc;
                border-radius: 8px;
                font-size: 0.85rem;
            }
            .pwa-ios-guide ol {
                margin: 8px 0 0 16px;
                padding: 0;
            }
            .pwa-ios-guide li {
                margin-bottom: 6px;
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Afișează modalul
     */
    function showModal() {
        console.log('[PWA Install] showModal() apelat');
        const modal = document.getElementById('pwa-install-modal');
        const iosGuide = document.getElementById('pwa-ios-instructions');
        const btnInstall = document.getElementById('pwa-btn-install');

        console.log('[PWA Install] Modal element:', modal);

        if (modal) {
            modal.style.display = 'flex';
            console.log('[PWA Install] Modal afișat cu display: flex');

            // Pentru iOS, afișează instrucțiunile manuale
            if (esteIOS && !esteStandalone) {
                if (iosGuide) iosGuide.style.display = 'block';
                if (btnInstall) btnInstall.style.display = 'none';
            }
        } else {
            console.error('[PWA Install] Modal element NU a fost găsit!');
        }
    }

    /**
     * Ascunde modalul
     */
    function hideModal() {
        const modal = document.getElementById('pwa-install-modal');
        if (modal) {
            modal.style.display = 'none';
            localStorage.setItem('pwa_refuzat', new Date().toISOString());
        }
    }

    /**
     * Instalează PWA (Android/Chrome)
     */
    function installPWA() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choiceResult) {
                if (choiceResult.outcome === 'accepted') {
                    console.log('[PWA Install] Utilizatorul a acceptat instalarea');
                    localStorage.setItem('pwa_instalat', 'true');
                } else {
                    console.log('[PWA Install] Utilizatorul a refuzat instalarea');
                    localStorage.setItem('pwa_refuzat', new Date().toISOString());
                }
                deferredPrompt = null;
                hideModal();
            });
        }
    }

    // Public API
    return {
        init: init,
        show: showModal,
        hide: hideModal,
        install: installPWA,
        isInstalled: function() { return pwaInstalat || esteStandalone; },
        isIOS: function() { return esteIOS; }
    };
})();

// Auto-init când DOM e gata
if (typeof window !== 'undefined') {
    window.PWAInstallAssistant = PWAInstallAssistant;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', PWAInstallAssistant.init);
    } else {
        PWAInstallAssistant.init();
    }
}
