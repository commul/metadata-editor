/*!
 * SessionChannel.js
 * Hybrid cross-tab communication utility
 * Supports BroadcastChannel (modern browsers) with localStorage fallback.
 */
(function (root, factory) {
    if (typeof define === "function" && define.amd) {
      define([], factory);
    } else if (typeof exports === "object") {
      module.exports = factory();
    } else {
      root.SessionChannel = factory();
    }
  })(typeof self !== "undefined" ? self : this, function () {
    "use strict";
  
    class SessionChannel {
      constructor(channelName = "session") {
        this.channelName = channelName;
        this.supportsBroadcastChannel = "BroadcastChannel" in window;
        this.onMessageCallback = null;
  
        if (this.supportsBroadcastChannel) {
          this.channel = new BroadcastChannel(channelName);
          this.channel.onmessage = (event) => {
            if (this.onMessageCallback) this.onMessageCallback(event.data);
          };
        } else {
          window.addEventListener("storage", (event) => {
            if (event.key !== channelName || !event.newValue) return;
            try {
              const data = JSON.parse(event.newValue);
              if (this.onMessageCallback) this.onMessageCallback(data);
            } catch (err) {
              console.warn("SessionChannel: invalid message data", err);
            }
          });
        }
      }
  
      onMessage(callback) {
        this.onMessageCallback = callback;
      }
  
      postMessage(data) {
        if (this.supportsBroadcastChannel) {
          this.channel.postMessage(data);
        } else {
          localStorage.setItem(
            this.channelName,
            JSON.stringify({ ts: Date.now(), ...data })
          );
          setTimeout(() => localStorage.removeItem(this.channelName), 500);
        }
      }
  
      close() {
        if (this.channel) this.channel.close();
      }
    }
  
    return SessionChannel;
  });
  