// audit.js

// Polyfill for crypto.randomUUID (fixes local non-HTTPS issues and older browsers)
if (typeof window.crypto === 'undefined' || typeof window.crypto.randomUUID !== 'function') {
    window.crypto = window.crypto || {};
    window.crypto.randomUUID = function () {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    };
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.auditTool === 'undefined') {
        console.error('[AI Audit Tool] auditTool config object not found. Check wp_localize_script.');
        return;
    }

    const formIds = Array.isArray(auditTool.formIds) ? auditTool.formIds : (auditTool.formIds ? [auditTool.formIds] : []);
    const popupId = auditTool.popupId || '';
    const ajaxUrl = auditTool.ajaxurl || '';
    const nonce   = auditTool.nonce || '';

    if (!ajaxUrl || !nonce) {
        console.error('[AI Audit Tool] ajaxurl or nonce missing from auditTool.');
        return;
    }

    // Bind each configured Elementor form ID
    formIds.forEach(rawId => {
        const formId = (rawId || '').toString().trim();
        if (!formId) return;

        // Original behaviour: bind by element ID
        const form = document.getElementById(formId);
        if (!form) return;

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            let auditHandled = false;

            // Hide Elementor success messages and loaders
            const style = document.createElement('style');
            style.innerHTML = `
                .elementor-message-success,
                .elementor-message,
                .elementor-form-spinner,
                .dialog-lightbox-loading,
                .elementor-button .spinner {
                    display: none !important;
                }
            `;
            document.head.appendChild(style);

            let popupOpened = false;

            // Fallback popup (if Elementor popup module is not available)
            const useFallbackPopup = () => {
                popupOpened = true;

                let backdrop = document.getElementById('audit-fallback-backdrop');
                let popup    = document.getElementById('audit-fallback-popup');

                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'audit-fallback-backdrop';
                    backdrop.style.position = 'fixed';
                    backdrop.style.inset = '0';
                    backdrop.style.background = 'rgba(0,0,0,0.45)';
                    backdrop.style.zIndex = '9998';
                    document.body.appendChild(backdrop);
                }

                if (!popup) {
                    popup = document.createElement('div');
                    popup.id = 'audit-fallback-popup';
                    popup.style.position = 'fixed';
                    popup.style.top = '50%';
                    popup.style.left = '50%';
                    popup.style.transform = 'translate(-50%, -50%)';
                    popup.style.background = '#ffffff';
                    popup.style.padding = '30px 20px';
                    popup.style.boxShadow = '0 12px 30px rgba(0,0,0,0.3)';
                    popup.style.borderRadius = '10px';
                    popup.style.maxWidth = '420px';
                    popup.style.width = '90%';
                    popup.style.zIndex = '9999';
                    popup.style.textAlign = 'center';
                    popup.innerHTML = `
                        <div class="analyzer-progress">
                            <div class="progress-circle" style="--value: 0%;">
                                <span class="progress-text">0%</span>
                            </div>
                            <p class="progress-message">Analyzing your website audit request... this may take 2–5 minutes.</p>
                            <p class="progress-text-nearly" style="display:none;">We’re nearly there...</p>
                        </div>
                        <div class="analyzer-results" style="display:none;"></div>
                    `;
                    document.body.appendChild(popup);
                }
            };

            // Attempt to open Elementor popup, fall back if not available
            const openPopup = () => {
                try {
                    if (
                        popupId &&
                        typeof elementorFrontend !== 'undefined' &&
                        elementorFrontend.modules &&
                        elementorFrontend.modules.popup &&
                        typeof elementorFrontend.modules.popup.showPopup === 'function'
                    ) {
                        elementorFrontend.modules.popup.showPopup({ id: popupId });
                        popupOpened = true;
                    } else {
                        console.warn('[AI Audit Tool] Elementor popup module not available – using fallback popup.');
                        useFallbackPopup();
                    }
                } catch (err) {
                    console.warn('[AI Audit Tool] Error opening Elementor popup, using fallback:', err);
                    useFallbackPopup();
                }
            };

            // Poll for progress elements inside the popup / fallback
            let pollCount      = 0;
            const maxPoll      = 50;
            const pollInterval = 50; // ms
            let progressCircle = null;
            let progressText   = null;
            let progressNearly = null;

            const pollContainer = (callback) => {
                const container = document.querySelector('.analyzer-progress') ||
                                  document.getElementById('audit-tool-progress') ||
                                  document.querySelector('.audit-tool-progress');

                if (container) {
                    progressCircle = container.querySelector('.progress-circle');
                    progressText   = container.querySelector('.progress-text');
                    progressNearly = container.querySelector('.progress-text-nearly') ||
                                     container.querySelector('.progress-nearly');

                    if (progressCircle && progressText && progressNearly) {
                        if (callback) callback();
                        return;
                    }
                }

                if (pollCount < maxPoll) {
                    pollCount++;
                    setTimeout(() => pollContainer(callback), pollInterval);
                } else if (callback) {
                    callback();
                }
            };

            // Handle popup close (fallback or Elementor)
            function closePopup() {
                // Fallback overlay
                const backdrop = document.getElementById('audit-fallback-backdrop');
                const popup    = document.getElementById('audit-fallback-popup');
                if (backdrop) backdrop.remove();
                if (popup) popup.remove();

                // Elementor popup
                try {
                    if (
                        popupId &&
                        typeof elementorFrontend !== 'undefined' &&
                        elementorFrontend.modules &&
                        elementorFrontend.modules.popup &&
                        typeof elementorFrontend.modules.popup.closePopup === 'function'
                    ) {
                        elementorFrontend.modules.popup.closePopup({ id: popupId });
                    }
                } catch (err) {
                    // Ignore
                }
            }

            // Start animation once we have the progress elements
            const startAnimation = () => {
                if (!progressCircle || !progressText) return;

                let progress = 0;
                let finalPhaseStarted = false;

                const setVisuals = () => {
                    // progress-circle relies on CSS variable --value as a percentage
                    progressCircle.style.setProperty('--value', progress + '%');
                    const txt = progressCircle.querySelector('.progress-text');
                    if (txt) {
                        txt.textContent = progress + '%';
                    }
                };

                setVisuals();

                const animationInterval = setInterval(() => {
                    if (auditHandled) {
                        clearInterval(animationInterval);
                        return;
                    }

                    if (progress < 90) {
                        const increment = Math.floor(Math.random() * 5) + 1; // 1–5%
                        progress = Math.min(progress + increment, 90);
                        setVisuals();
                    } else if (!finalPhaseStarted) {
                        finalPhaseStarted = true;
                        if (progressNearly) {
                            progressNearly.style.display = 'block';
                        }
                    }
                }, 700);

                // Expose a handle so we can force complete on success/error
                form._auditAnimationInterval = animationInterval;
            };

            // Open popup (Elementor or fallback), then poll for container
            openPopup();
            pollContainer(startAnimation);

            // Build AJAX payload
            const data = new FormData(form);

            // Ensure action + nonce are present
            data.append('action', 'audit_analyze');
            data.append('nonce', nonce);

            // Debug log all fields (kept from original behaviour)
            for (let [key, value] of data.entries()) {
                console.log('[AI Audit Tool] Field:', key, '=>', value);
            }

            // Send AJAX to WordPress
            fetch(ajaxUrl, {
                method: 'POST',
                body: data
            })
                .then(res => {
                    const contentType = res.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        return res.text().then(text => {
                            throw new Error('Non-JSON response: ' + text);
                        });
                    }
                    return res.json();
                })
                .then(json => {
                    auditHandled = true;

                    if (form._auditAnimationInterval) {
                        clearInterval(form._auditAnimationInterval);
                    }

                    // Final visual state
                    const container = document.querySelector('.analyzer-progress') ||
                                      document.getElementById('audit-tool-progress') ||
                                      document.querySelector('.audit-tool-progress');
                    const resultsContainer = document.querySelector('.analyzer-results');

                    if (container) container.style.display = 'none';
                    if (resultsContainer) resultsContainer.style.display = 'block';

                    if (progressCircle) {
                        progressCircle.style.setProperty('--value', '100%');
                        const txt = progressCircle.querySelector('.progress-text');
                        if (txt) txt.textContent = '100%';
                    }

                    const message = (json && json.success && json.data && json.data.message)
                        ? json.data.message
                        : json && json.success
                            ? 'Congratulations, your audit has been sent to your inbox.'
                            : (json && json.data && json.data.message)
                                ? json.data.message
                                : 'Sorry, something went wrong while generating your audit.';

                    if (resultsContainer) {
                        resultsContainer.innerHTML = `
                            <p>${message}</p>
                            <button type="button" class="audit-close-btn" style="
                                margin-top: 10px;
                                padding: 8px 18px;
                                border-radius: 4px;
                                border: none;
                                cursor: pointer;
                                background: #004b8d;
                                color: #fff;
                                font-size: 0.95em;
                            ">Close</button>
                        `;
                        const closeBtn = resultsContainer.querySelector('.audit-close-btn');
                        if (closeBtn) {
                            closeBtn.addEventListener('click', closePopup);
                        }
                    } else {
                        alert(message);
                        closePopup();
                    }
                })
                .catch(err => {
                    console.error('[AI Audit Tool] AJAX error:', err);
                    auditHandled = true;

                    if (form._auditAnimationInterval) {
                        clearInterval(form._auditAnimationInterval);
                    }

                    const container = document.querySelector('.analyzer-progress') ||
                                      document.getElementById('audit-tool-progress') ||
                                      document.querySelector('.audit-tool-progress');
                    const resultsContainer = document.querySelector('.analyzer-results');

                    if (container) container.style.display = 'none';

                    const message = 'Sorry, a network or server error prevented the audit from completing. Please try again.';

                    if (resultsContainer) {
                        resultsContainer.innerHTML = `
                            <p style="color:#c62828;">${message}</p>
                            <button type="button" class="audit-close-btn" style="
                                margin-top: 10px;
                                padding: 8px 18px;
                                border-radius: 4px;
                                border: none;
                                cursor: pointer;
                                background: #c62828;
                                color: #fff;
                                font-size: 0.95em;
                            ">Close</button>
                        `;
                        const closeBtn = resultsContainer.querySelector('.audit-close-btn');
                        if (closeBtn) {
                            closeBtn.addEventListener('click', closePopup);
                        }
                    } else {
                        alert(message + ' (Check console for details.)');
                        closePopup();
                    }
                });
        });
    });
});
