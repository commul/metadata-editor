//v-login
Vue.component('v-login', {
    props: ['value'],
    data() {
        return {
            is_logged_in: false,
            is_loading: false,
            login_error: {}
        }
    },
    mounted:function(){
        this.isLoggedIn();
        document.addEventListener("visibilitychange", this.isLoggedIn);
    },
    methods: {
        loginRedirect: function() {
            
            
            if (window.GlobalSessionHandler) {
                window.GlobalSessionHandler.openLoginPopup();
            } else {
                // fallback
                let url = CI.site_url + '/auth/login?mode=popup';
                const w = 500, h = 600;
                const left = (screen.width - w) / 2;
                const top = (screen.height - h) / 2;
                window.open(url, 'loginPopup', `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
            }
        },
        closeDialog: function() {
            this.$emit('input', false);
            if (window.GlobalSessionHandler) {
                window.GlobalSessionHandler.closeLoginDialog();
            }
        },
        isLoggedIn: function() {
            if (this.is_loading) {
                return;
            }

            this.is_loading = true;
            let url = CI.site_url + '/api/editor/is_connected';
            vm = this;
            console.log("checking isLoggedIn");
            axios.get(url)
                .then(function(response) {
                    vm.is_logged_in = true;
                    vm.is_loading = false;
                    // Close dialog if logged in
                    if (vm.is_logged_in && vm.value) {
                        vm.closeDialog();
                    }
                })
                .catch(function(response) {
                    vm.is_logged_in = false;
                    vm.is_loading = false;
                });
        }
    },    
    watch: {
        /*is_logged_in: function() {
            if (this.is_logged_in) {
                this.$emit('input', false);
            }
        }*/
    },
    template: `
        <div class="v-login"   >

        <template>
            
            <v-row justify="center">
            <v-dialog
                v-model="value"
                persistent
                max-width="500"
            >
                <v-card>
                    <button type="button"  @click="$emit('input', false)" class="float-right btn btn-default">
                        <v-icon aria-hidden="false">mdi-close</v-icon>
                    </button>

                    <v-card-title class="text-h5">{{$t('login')}}</v-card-title>
                    <v-card-text v-if="is_logged_in">
                        <div class="alert alert-success">{{$t('you_are_logged_in')}}</div>
                        <v-btn block color="primary" @click="closeDialog">{{$t("close")}}</v-btn>
                    </v-card-text>
                    <v-card-text v-else>

                        <div class="alert alert-warning mb-3">{{$t('session_expired_warning')}}</div>

                        <div v-if="login_error.data" class="alert alert-danger mt-2">{{login_error.data.message}}</div>

                        <v-btn block color="primary" @click="loginRedirect">{{$t('login_opens_new_tab')}}</v-btn>

                    </v-card-text>
                </v-card>

            </v-dialog>
            </v-row>
        </template>
            
        </div>
    `
});


