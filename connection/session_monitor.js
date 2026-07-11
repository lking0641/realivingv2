// session_monitor.js
// Automatic session timeout with hard refresh

(function() {
    // ============================================
    // SESSION TIMEOUT SETTINGS (must match PHP)
    // ============================================
    // FOR TESTING: 10 seconds (uncomment this line)
    // const SESSION_TIMEOUT = 10 * 1000; // 10 seconds in milliseconds
    
    // FOR PRODUCTION: 9 hours (uncomment this line)
     const SESSION_TIMEOUT = 9 * 60 * 60 * 1000; // 9 hours in milliseconds
    // ============================================
    
    // Warning before timeout (5 minutes before)
    const WARNING_TIME = 5 * 60 * 1000; // 5 minutes before timeout
    
    let lastActivityTime = Date.now();
    let timeoutWarningShown = false;
    
    // Update last activity time on user interaction
    function resetTimer() {
        lastActivityTime = Date.now();
        timeoutWarningShown = false;
    }
    
    // Check if session has timed out
    function checkTimeout() {
        const currentTime = Date.now();
        const inactiveTime = currentTime - lastActivityTime;
        
        // Show warning before timeout
        if (inactiveTime >= (SESSION_TIMEOUT - WARNING_TIME) && !timeoutWarningShown) {
            timeoutWarningShown = true;
            showTimeoutWarning();
        }
        
        // Hard refresh when timeout is reached
        if (inactiveTime >= SESSION_TIMEOUT) {
            performHardRefresh();
        }
    }
    
    // Show warning modal/notification
    function showTimeoutWarning() {
        // Create warning overlay
        const warningDiv = document.createElement('div');
        warningDiv.id = 'session-timeout-warning';
        warningDiv.innerHTML = `
            <div style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
                padding: 20px 25px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                z-index: 99999;
                max-width: 350px;
                animation: slideIn 0.3s ease-out;
            ">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="ri-time-line" style="font-size: 24px;"></i>
                    <div>
                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 5px;">
                            Session Expiring Soon
                        </div>
                        <div style="font-size: 13px; opacity: 0.9;">
                            Your session will expire in 5 minutes due to inactivity.
                        </div>
                        <button onclick="resetSessionTimer()" style="
                            margin-top: 10px;
                            background: white;
                            color: #d97706;
                            border: none;
                            padding: 8px 16px;
                            border-radius: 6px;
                            font-weight: 600;
                            cursor: pointer;
                            font-size: 13px;
                        ">
                            Stay Logged In
                        </button>
                    </div>
                </div>
            </div>
            <style>
                @keyframes slideIn {
                    from {
                        transform: translateX(400px);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            </style>
        `;
        document.body.appendChild(warningDiv);
    }
    
    // Perform hard refresh to trigger logout
    function performHardRefresh() {
        console.log('Session timeout reached. Performing hard refresh...');
        
        // Show logout message overlay
        const overlay = document.createElement('div');
        overlay.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.9);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
            ">
                <div style="
                    background: white;
                    padding: 40px;
                    border-radius: 16px;
                    text-align: center;
                    max-width: 400px;
                ">
                    <div style="
                        width: 80px;
                        height: 80px;
                        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        margin: 0 auto 20px;
                    ">
                        <i class="ri-time-line" style="font-size: 40px; color: white;"></i>
                    </div>
                    <h2 style="font-size: 24px; margin-bottom: 10px; color: #1f2937;">
                        Session Expired
                    </h2>
                    <p style="color: #6b7280; margin-bottom: 20px;">
                        Your session has expired due to inactivity. You will be redirected to login...
                    </p>
                    <div style="
                        display: inline-block;
                        width: 40px;
                        height: 40px;
                        border: 4px solid #f3f4f6;
                        border-top-color: #f59e0b;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                    "></div>
                </div>
            </div>
            <style>
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            </style>
        `;
        document.body.appendChild(overlay);
        
        // Wait 2 seconds then hard refresh
        setTimeout(() => {
            // Hard refresh (bypasses cache)
            window.location.reload(true);
        }, 2000);
    }
    
    // Global function to reset timer from warning button
    window.resetSessionTimer = function() {
        resetTimer();
        const warning = document.getElementById('session-timeout-warning');
        if (warning) {
            warning.remove();
        }
    };
    
    // Listen to user activity events
    const activityEvents = [
        'mousedown', 'mousemove', 'keypress', 
        'scroll', 'touchstart', 'click'
    ];
    
    activityEvents.forEach(event => {
        document.addEventListener(event, resetTimer, true);
    });
    
    // Check timeout every second
    setInterval(checkTimeout, 1000);
    
    // Initialize
    console.log('Session monitor initialized. Timeout:', SESSION_TIMEOUT / 1000, 'seconds');
})();