<!DOCTYPE html>
<html>

<head>
  <link rel="icon" href="<?php echo base_url();?>favicon.ico">
  <link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
  <link href="<?php echo base_url();?>vue-app/assets/mdi.min.css" rel="stylesheet">
  <link href="<?php echo base_url();?>vue-app/assets/vuetify.min.css" rel="stylesheet">
  <link href="<?php echo base_url();?>vue-app/assets/bootstrap.min.css" rel="stylesheet">
  <script src="<?php echo base_url();?>vue-app/assets/jquery.min.js"></script>
  <script src="<?php echo base_url();?>vue-app/assets/bootstrap.bundle.min.js"></script>
  <link href="<?php echo base_url();?>vue-app/assets/styles.css" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, minimal-ui">
  <style>
    [v-cloak] { display: none; }
  </style>
</head>

<body class="layout-top-nav">

<?php
  $user = $this->session->userdata('username');
  $this->load->library('Editor_acl');
  $has_schema_permission = false;
  try {
      $has_schema_permission = $this->editor_acl->has_access('schema', 'view');
  } catch (Exception $e) {
      $has_schema_permission = false;
  }
  $user_info = array(
    'username' => $user,
    'is_logged_in' => !empty($user),
    'is_admin' => $this->ion_auth->is_admin(),
    'has_schema_permission' => $has_schema_permission,
  );
?>

  <script>
    var CI = {
      'site_url': '<?php echo site_url(); ?>',
      'base_url': '<?php echo base_url(); ?>',
      'user_info': <?php echo json_encode($user_info); ?>
    };
  </script>

  <div id="app" data-app>
    <v-app>
      <alert-dialog></alert-dialog>
      <confirm-dialog></confirm-dialog>

      <div class="wrapper">
        <vue-global-site-header></vue-global-site-header>
        <div class="content-wrapperx" v-cloak>
          <section class="content">
            <div class="container-fluid">
              <div class="row">
                <div class="col-12">
                  <div class="mt-5 mb-4">
                    <main-navigation-tabs active-tab="issues" v-model="navTabsModel"></main-navigation-tabs>
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-3">
                  <!-- Filters Sidebar -->
                  <v-card class="mt-4">
                    <v-card-title class="text-subtitle-1">
                      <v-icon left small>mdi-filter</v-icon>
                      {{ $t('Filters') }}
                    </v-card-title>
                    <v-card-text>
                      <v-select
                        v-model="filters.status"
                        :items="statusOptions"
                        label="Status"
                        outlined
                        dense
                        hide-details
                        class="mb-3"
                      ></v-select>

                      <v-select
                        v-model="filters.category"
                        :items="categoryOptions"
                        label="Category"
                        outlined
                        dense
                        hide-details
                        class="mb-3"
                      ></v-select>

                      <v-select
                        v-model="filters.severity"
                        :items="severityOptions"
                        label="Severity"
                        outlined
                        dense
                        hide-details
                        class="mb-3"
                      ></v-select>

                      <v-select
                        v-model="filters.applied"
                        :items="appliedOptions"
                        label="Applied"
                        outlined
                        dense
                        hide-details
                        class="mb-3"
                      ></v-select>

                      <v-text-field
                        v-model.number="filters.issue_id"
                        label="Issue ID"
                        type="number"
                        min="1"
                        outlined
                        dense
                        hide-details
                        clearable
                        class="mb-3"
                        placeholder="e.g. 123"
                      ></v-text-field>

                      <v-text-field
                        v-model.number="filters.project_id"
                        label="Project ID"
                        type="number"
                        min="1"
                        outlined
                        dense
                        hide-details
                        clearable
                        class="mb-3"
                        placeholder="e.g. 456"
                      ></v-text-field>

                      <v-btn
                        @click="clearFilters"
                        outlined
                        small
                        block
                      >
                        <v-icon left small>mdi-filter-off</v-icon>
                        Clear Filters
                      </v-btn>
                    </v-card-text>
                  </v-card>
                </div>

                <div class="col-md-9 mt-4">
                  <v-card>
                    <v-card-title class="d-flex align-center justify-space-between flex-wrap">
                      <span class="text-h6">{{ $t('Issues') }}</span>
                    </v-card-title>

                    <v-card-text>
                      <v-text-field
                        v-model="searchQuery"
                        :placeholder="'Search issues...'"
                        dense
                        outlined
                        hide-details
                        clearable
                        class="mb-3"
                        style="max-width: 320px;"
                        prepend-inner-icon="mdi-magnify"
                      ></v-text-field>

                      <v-data-table
                        :headers="headers"
                        :items="issues"
                        :server-items-length="totalIssues"
                        :options.sync="options"
                        :loading="loading"
                        :footer-props="{
                          'items-per-page-options': [10, 25, 50, 100]
                        }"
                        class="elevation-1"
                      >
                        <template v-slot:item.id="{ item }">
                          <a :href="(CI.site_url || '').replace(/\/?$/, '') + '/issues/edit/' + item.id">
                            {{ item.id }}
                          </a>
                        </template>

                        <template v-slot:item.title="{ item }">
                          <div>
                            <a :href="(CI.site_url || '').replace(/\/?$/, '') + '/issues/edit/' + item.id">
                              {{ item.title || '-' }}
                            </a>
                            <div class="text-caption text--secondary mt-1" :title="item.description">
                              {{ truncateText(item.description, 60) }}
                            </div>
                          </div>
                        </template>

                        <template v-slot:item.project="{ item }">
                          <a
                            :href="(CI.site_url || '').replace(/\/?$/, '') + '/editor/edit/' + item.project_id"
                            target="_blank"
                            :title="item.project_title || ('Project ' + item.project_id)"
                          >
                            {{ truncateText(item.project_title || ('Project ' + item.project_id), 35) }}
                          </a>
                        </template>

                        <template v-slot:item.category="{ item }">
                          <v-chip small outlined v-if="item.category">{{ item.category }}</v-chip>
                          <span v-else class="text--disabled">-</span>
                        </template>

                        <template v-slot:item.severity="{ item }">
                          <v-chip
                            v-if="item.severity"
                            small
                            :color="getSeverityColor(item.severity)"
                            outlined
                            class="text-capitalize"
                          >
                            {{ item.severity }}
                          </v-chip>
                          <span v-else class="text--disabled">-</span>
                        </template>

                        <template v-slot:item.status="{ item }">
                          <v-chip
                            small
                            :color="getStatusColor(item.status)"
                            :outlined="item.status === 'open'"
                            class="text-capitalize"
                          >
                            {{ formatStatus(item.status) }}
                          </v-chip>
                        </template>

                        <template v-slot:item.created="{ item }">
                          <span class="text-caption">{{ formatDate(item.created) }}</span>
                        </template>

                        <template v-slot:item.actions="{ item }">
                          <v-btn
                            icon
                            small
                            :href="(CI.site_url || '').replace(/\/?$/, '') + '/issues/edit/' + item.id"
                            title="View / Edit"
                          >
                            <v-icon small>mdi-eye</v-icon>
                          </v-btn>
                        </template>

                        <template v-slot:no-data>
                          <div class="text-center pa-5">
                            <v-icon size="64" color="grey lighten-2">mdi-alert-circle-outline</v-icon>
                            <p class="text-h6 mt-3">No issues found</p>
                          </div>
                        </template>
                      </v-data-table>
                    </v-card-text>
                  </v-card>
                </div>
              </div>
            </div>
          </section>
        </div>
      </div>
    </v-app>
  </div>

  <script src="<?php echo base_url();?>vue-app/assets/vue-i18n.min.js"></script>
  <script src="<?php echo base_url();?>vue-app/assets/vue.min.js"></script>
  <script src="<?php echo base_url();?>vue-app/assets/moment-with-locales.min.js"></script>
  <script src="<?php echo base_url();?>vue-app/assets/vuetify.min.js"></script>
  <script src="<?php echo base_url();?>vue-app/assets/axios.min.js"></script>

  <script>
    <?php
    echo $this->load->view("vue/vue-global-eventbus.js", null, true);
    echo $this->load->view("vue/vue-alert-dialog-component.js", null, true);
    echo $this->load->view("vue/vue-confirm-dialog-component.js", null, true);
    echo $this->load->view("editor_common/global-site-header-component.js", null, true);
    echo $this->load->view("editor_common/main-navigation-tabs-component.js", null, true);
    ?>
  </script>

  <script>
    (function() {
      const translations = <?php echo json_encode(isset($translations) ? $translations : array(), JSON_UNESCAPED_UNICODE); ?>;
      const i18n = new VueI18n({ locale: 'default', messages: { default: translations } });
      const vuetify = new Vuetify({
        theme: {
          themes: {
            light: {
              primary: '#526bc7',
              'primary-dark': '#0c1a4d',
              secondary: '#b0bec5',
              accent: '#8c9eff',
              error: '#b71c1c'
            }
          }
        }
      });

      const apiBase = (CI && CI.site_url ? CI.site_url : '').replace(/\/?$/, '/') + 'api/issues';

      new Vue({
        el: '#app',
        i18n,
        vuetify,
        data() {
          return {
            navTabsModel: 5,
            issues: [],
            totalIssues: 0,
            searchQuery: '',
            searchDebounce: null,
            loading: false,
            filters: {
              status: '',
              category: '',
              severity: '',
              applied: '',
              issue_id: '',
              project_id: ''
            },
            statusOptions: [
              { text: 'All', value: '' },
              { text: 'Open', value: 'open' },
              { text: 'Accepted', value: 'accepted' },
              { text: 'Fixed', value: 'fixed' },
              { text: 'Rejected', value: 'rejected' },
              { text: 'Dismissed', value: 'dismissed' },
              { text: 'False Positive', value: 'false_positive' }
            ],
            categoryOptions: [
              { text: 'All', value: '' },
              { text: 'Typo / Wording', value: 'Typo / Wording' },
              { text: 'Inconsistency', value: 'Inconsistency' },
              { text: 'Missing Data', value: 'Missing Data' },
              { text: 'Format Issue', value: 'Format Issue' },
              { text: 'Completeness', value: 'Completeness' },
              { text: 'Other', value: 'Other' }
            ],
            severityOptions: [
              { text: 'All', value: '' },
              { text: 'Low', value: 'low' },
              { text: 'Medium', value: 'medium' },
              { text: 'High', value: 'high' },
              { text: 'Critical', value: 'critical' }
            ],
            appliedOptions: [
              { text: 'All', value: '' },
              { text: 'Applied', value: '1' },
              { text: 'Not Applied', value: '0' }
            ],
            options: {
              page: 1,
              itemsPerPage: 25,
              sortBy: ['created'],
              sortDesc: [true]
            }
          };
        },
        watch: {
          options: {
            handler() {
              this.loadIssues();
            },
            deep: true
          },
          filters: {
            handler() {
              this.options.page = 1;
              this.loadIssues();
            },
            deep: true
          },
          searchQuery() {
            if (this.searchDebounce) clearTimeout(this.searchDebounce);
            this.searchDebounce = setTimeout(() => {
              this.options.page = 1;
              this.loadIssues();
            }, 350);
          }
        },
        computed: {
          headers() {
            return [
              { text: this.$t('id') || 'ID', value: 'id', sortable: false, width: '70px' },
              { text: this.$t('title') || 'Title', value: 'title', sortable: false, width: '220px' },
              { text: this.$t('project') || 'Project', value: 'project', sortable: false, width: '200px' },
              { text: this.$t('category') || 'Category', value: 'category', sortable: false, width: '130px' },
              { text: this.$t('severity') || 'Severity', value: 'severity', sortable: false, width: '120px' },
              { text: this.$t('status') || 'Status', value: 'status', sortable: false, width: '130px' },
              { text: this.$t('created') || 'Created', value: 'created', sortable: false, width: '120px' },
              { text: '', value: 'actions', sortable: false, align: 'end', width: '80px' }
            ];
          }
        },
        created() {
          this.loadIssues();
        },
        methods: {
          loadIssues() {
            this.loading = true;
            const offset = (this.options.page - 1) * this.options.itemsPerPage;
            const params = { 
              limit: this.options.itemsPerPage, 
              offset: offset 
            };
            
            const q = (this.searchQuery || '').trim();
            if (q) params.search = q;

            // Add filters
            if (this.filters.status) params.status = this.filters.status;
            if (this.filters.category) params.category = this.filters.category;
            if (this.filters.severity) params.severity = this.filters.severity;
            if (this.filters.applied !== '') params.applied = this.filters.applied;
            if (this.filters.issue_id !== '' && this.filters.issue_id != null) params.id = this.filters.issue_id;
            if (this.filters.project_id !== '' && this.filters.project_id != null) params.project_id = this.filters.project_id;

            axios.get(apiBase, { params: params })
              .then(res => {
                if (res.data && res.data.status === 'success') {
                  this.issues = (res.data.issues || []).map(issue => {
                    return {
                      ...issue,
                      project_title: issue.project_title || 'Project ' + issue.project_id
                    };
                  });
                  this.totalIssues = res.data.total != null ? res.data.total : 0;
                } else {
                  this.issues = [];
                  this.totalIssues = 0;
                }
              })
              .catch(err => {
                this.issues = [];
                this.totalIssues = 0;
                const msg = (err.response && err.response.data && err.response.data.message) ? err.response.data.message : (err.message || 'Failed to load issues');
                EventBus.$emit('alert', { message: msg });
              })
              .finally(() => { this.loading = false; });
          },
          truncateText(text, length) {
            if (!text) return '';
            return text.length > length ? text.substring(0, length) + '...' : text;
          },
          formatDate(timestamp) {
            if (!timestamp) return '-';
            return moment.unix(timestamp).format('YYYY-MM-DD');
          },
          getSeverityColor(severity) {
            const colors = {
              low: 'blue',
              medium: 'orange',
              high: 'deep-orange',
              critical: 'red'
            };
            return colors[severity] || 'grey';
          },
          getStatusColor(status) {
            const colors = {
              open: 'primary',
              accepted: 'blue',
              fixed: 'success',
              rejected: 'error',
              dismissed: 'grey',
              false_positive: 'warning'
            };
            return colors[status] || 'grey';
          },
          formatStatus(status) {
            return (status || '').replace(/_/g, ' ');
          },
          clearFilters() {
            this.filters = {
              status: '',
              category: '',
              severity: '',
              applied: '',
              issue_id: '',
              project_id: ''
            };
          }
        }
      });
    })();
  </script>
</body>
</html>
