/*!
 * Vue Plugin for Global Login Dialog Management
 * Provides reactive login dialog state and methods to Vue components
 */

(function() {
  'use strict';

  var GlobalLoginPlugin = {
    install: function(Vue, options) {
      // Global mixin to provide login dialog state and methods
      Vue.mixin({
        data: function() {
          return {
            login_dialog: false,
            _globalSessionHandlerInitialized: false
          };
        },
        mounted: function() {
          var vm = this;
          
          // Initialize connection to GlobalSessionHandler
          if (window.GlobalSessionHandler) {
            // Set initial state
            vm.login_dialog = window.GlobalSessionHandler.loginDialogOpen;
            
            // Register callback for state changes
            window.GlobalSessionHandler.onLoginDialogChange(function(isOpen) {
              vm.login_dialog = isOpen;
            });

            // Register callback for checking login status (used by login component)
            if (vm.isLoggedIn && typeof vm.isLoggedIn === 'function') {
              window.GlobalSessionHandler.setCheckLoginStatusCallback(function() {
                vm.isLoggedIn();
              });
            }
            
            vm._globalSessionHandlerInitialized = true;
          }
        },
        beforeDestroy: function() {
          // Cleanup if needed
          if (this._globalSessionHandlerInitialized && window.GlobalSessionHandler) {
            // Unregister callbacks would require storing the callback reference
            // For now, we'll rely on Vue's cleanup
          }
        },
        methods: {
          /**
           * Open login popup window
           * Can be called from any component
           */
          openLoginPopup: function() {
            if (window.GlobalSessionHandler) {
              var loginUrl = typeof CI !== 'undefined' ? CI.site_url + '/auth/login' : '/auth/login';
              return window.GlobalSessionHandler.openLoginPopup(loginUrl);
            }
          },

          /**
           * Close login dialog
           */
          closeLoginDialog: function() {
            if (window.GlobalSessionHandler) {
              window.GlobalSessionHandler.closeLoginDialog();
            }
            this.login_dialog = false;
          }
        }
      });

      // Register v-login component globally if it exists
      // This assumes vue-login-component.js is loaded separately
    }
  };

  // Export for use with Vue
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = GlobalLoginPlugin;
  } else if (typeof define === 'function' && define.amd) {
    define([], function() { return GlobalLoginPlugin; });
  } else {
    window.GlobalLoginPlugin = GlobalLoginPlugin;
  }

})();

