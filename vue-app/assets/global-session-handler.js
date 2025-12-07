/*!
 * Global Session Handler for Vue Pages
 * Handles session expiration across all Vue-based pages using SessionChannel
 */

(function() {
  'use strict';

  // Global state for login dialog and popup window
  window.GlobalSessionHandler = {
    loginDialogOpen: false,
    loginPopupWindow: null,
    sessionChannel: null,
    loginDialogCallbacks: [],
    _interceptorSetup: false,
    _axiosInterceptorId: null,

    /**
     * Initialize the global session handler
     */
    init: function() {
      // Initialize SessionChannel
      if (typeof SessionChannel !== 'undefined') {
        this.sessionChannel = new SessionChannel('session');
        this.setupSessionChannelListeners();
      } else {
        console.warn('SessionChannel not loaded. Session expiration handling may not work properly.');
      }

      // Setup axios interceptor if axios is available
      // Try multiple times in case axios loads after this script
      if (typeof axios !== 'undefined') {
        this.setupAxiosInterceptor();
      } else {
        console.warn('Axios not loaded yet. Will retry...');
        // Retry after a short delay in case axios loads asynchronously
        var self = this;
        var retryCount = 0;
        var retryInterval = setInterval(function() {
          retryCount++;
          if (typeof axios !== 'undefined') {
            clearInterval(retryInterval);
            self.setupAxiosInterceptor();
          } else if (retryCount >= 10) {
            // Stop retrying after 5 seconds (10 * 500ms)
            clearInterval(retryInterval);
            console.warn('Axios not loaded after retries. Session expiration handling may not work properly.');
          }
        }, 500);
      }
    },

    /**
     * Setup SessionChannel listeners for session restoration
     */
    setupSessionChannelListeners: function() {
      var self = this;
      
      this.sessionChannel.onMessage(function(msg) {
        if (msg.type === 'sessionRestored') {
          console.log('Session restored via SessionChannel');
          
          // Close popup if open
          if (self.loginPopupWindow && !self.loginPopupWindow.closed) {
            try {
              self.loginPopupWindow.close();
            } catch (e) {
              console.warn('Could not close popup window:', e);
            }
            self.loginPopupWindow = null;
          }

          // Return focus to main window
          if (window.focus) {
            window.focus();
          }

          // Close login dialog and notify callbacks
          self.loginDialogOpen = false;
          self.notifyLoginDialogCallbacks(false);
          
          // Check login status after a short delay to ensure session is fully restored
          setTimeout(function() {
            if (self.checkLoginStatusCallback) {
              self.checkLoginStatusCallback();
            }
          }, 500);
        }
      });
    },

    /**
     * Setup axios response interceptor to catch 401 errors
     */
    setupAxiosInterceptor: function() {
      var self = this;
      
      // Check if interceptor already set up to avoid duplicates
      if (this._interceptorSetup) {
        return;
      }
      
      // Add our interceptor
      this._axiosInterceptorId = axios.interceptors.response.use(
        function(response) {
          return response;
        },
        function(error) {
          // Check for 401 Unauthorized status
          // Handle both error.response (server responded) and error.request (no response)
          if (error.response) {
            if (error.response.status === 401) {
              console.log('GlobalSessionHandler: 401 Unauthorized detected, showing login dialog');
              self.showLoginDialog();
            }
          } else if (error.request) {
            // Request was made but no response received
            // Check if it's a network error that might indicate session issue
            if (error.code === 'ECONNABORTED' || error.message === 'Network Error') {
              // Don't show login for network errors - they're not session related
              console.log('GlobalSessionHandler: Network error:', error.message);
            }
          } else {
            // Error in request setup
            console.log('GlobalSessionHandler: Request setup error:', error.message);
          }
          return Promise.reject(error);
        }
      );
      
      this._interceptorSetup = true;
      console.log('GlobalSessionHandler: Axios interceptor set up successfully (ID: ' + this._axiosInterceptorId + ')');
    },

    /**
     * Show login dialog - triggers callback to Vue components
     */
    showLoginDialog: function() {
      console.log('GlobalSessionHandler: showLoginDialog called, current state:', this.loginDialogOpen);
      if (!this.loginDialogOpen) {
        this.loginDialogOpen = true;
        console.log('GlobalSessionHandler: Setting loginDialogOpen to true, notifying callbacks');
        this.notifyLoginDialogCallbacks(true);
      } else {
        console.log('GlobalSessionHandler: Login dialog already open');
      }
    },

    /**
     * Close login dialog
     */
    closeLoginDialog: function() {
      this.loginDialogOpen = false;
      this.notifyLoginDialogCallbacks(false);
    },

    /**
     * Open login popup window
     */
    openLoginPopup: function(loginUrl) {
      // If popup is already open, focus it
      if (this.loginPopupWindow && !this.loginPopupWindow.closed) {
        this.loginPopupWindow.focus();
        return this.loginPopupWindow;
      }

      // Calculate centered position
      var w = 500;
      var h = 600;
      var left = (screen.width / 2) - (w / 2);
      var top = (screen.height / 2) - (h / 2);

      // Add mode=popup to URL to indicate popup mode
      var url = loginUrl || (typeof CI !== 'undefined' ? CI.site_url + '/auth/login' : '/auth/login');
      if (url.indexOf('?') > -1) {
        url += '&mode=popup';
      } else {
        url += '?mode=popup';
      }

      // Open popup window
      var popup = window.open(
        url,
        'loginPopup',
        'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
      );

      if (popup) {
        this.loginPopupWindow = popup;
        
        // Monitor popup close
        var checkClosed = setInterval(function() {
          if (popup.closed) {
            clearInterval(checkClosed);
            this.loginPopupWindow = null;
          }
        }.bind(this), 500);
      } else {
        console.error('Failed to open login popup. Popup may be blocked.');
        alert('Please allow popups for this site to login without losing your work.');
      }

      return popup;
    },

    /**
     * Register callback for login dialog state changes
     */
    onLoginDialogChange: function(callback) {
      if (typeof callback === 'function') {
        this.loginDialogCallbacks.push(callback);
        // Immediately call with current state
        callback(this.loginDialogOpen);
      }
    },

    /**
     * Unregister callback
     */
    offLoginDialogChange: function(callback) {
      var index = this.loginDialogCallbacks.indexOf(callback);
      if (index > -1) {
        this.loginDialogCallbacks.splice(index, 1);
      }
    },

    /**
     * Notify all registered callbacks of login dialog state change
     */
    notifyLoginDialogCallbacks: function(isOpen) {
      this.loginDialogCallbacks.forEach(function(callback) {
        try {
          callback(isOpen);
        } catch (e) {
          console.error('Error in login dialog callback:', e);
        }
      });
    },

    /**
     * Set callback for checking login status after session restore
     */
    setCheckLoginStatusCallback: function(callback) {
      this.checkLoginStatusCallback = callback;
    }
  };

  // Auto-initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      window.GlobalSessionHandler.init();
    });
  } else {
    // DOM already loaded
    window.GlobalSessionHandler.init();
  }

})();

