<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card shadow-sm" style="max-width: 400px; width: 100%;">
        <div class="card-body text-center p-4">
            <div class="mb-3">
                <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
            </div>
            <h1 class="h3 mb-3">You have successfully logged in</h1>
            <p class="text-muted mb-4">
                Your session has been restored.
            </p>
            
            <?php if (isset($popup_mode) && $popup_mode): ?>
            <div class="alert alert-info mb-3" role="alert">
                <small>This window will close automatically. If it doesn't, you can safely close it.</small>
            </div>
            <button class="btn btn-primary" onclick="closeWindow()">Close Window</button>
            <?php endif; ?>
        </div>
    </div>
</div>

    <?php if (isset($popup_mode) && $popup_mode): ?>
    <script src="<?php echo base_url(); ?>vue-app/assets/session_channel.js"></script>
    <script type="text/javascript">
        (function() {
            var messageSent = false;
            
            function closeWindow() {
                // Send SessionChannel message to parent
                if (typeof SessionChannel !== 'undefined' && !messageSent) {
                    try {
                        var sessionChannel = new SessionChannel('session');
                        sessionChannel.postMessage({ type: 'sessionRestored', ts: Date.now() });
                        messageSent = true;
                    } catch (e) {
                        console.warn('Could not send SessionChannel message:', e);
                    }
                }
                
                // Close popup window
                if (window.opener) {
                    try {
                        window.close();
                    } catch (e) {
                        console.warn('Could not close window:', e);
                    }
                }
            }
            
            // Auto-close after a short delay
            setTimeout(function() {
                closeWindow();
            }, 1500);
            
            // Multiple close attempts for browser compatibility
            setTimeout(function() {
                if (window.opener && !window.closed) {
                    try {
                        window.close();
                    } catch (e) {
                        // Ignore
                    }
                }
            }, 2000);
            
            setTimeout(function() {
                if (window.opener && !window.closed) {
                    try {
                        window.close();
                    } catch (e) {
                        // Ignore
                    }
                }
            }, 3000);
            
            // Make closeWindow available globally for button click
            window.closeWindow = closeWindow;
        })();
    </script>
    <?php endif; ?>

