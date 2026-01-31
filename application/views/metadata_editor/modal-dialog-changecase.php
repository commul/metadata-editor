<template v-if="changeCaseDialog">
  <v-dialog
    v-model="changeCaseDialog"
    max-width="360"
    persistent
    content-class="change-case-dialog"
  >
    <v-card>
      <v-card-title class="text-subtitle-1 font-weight-medium">
        {{ $t('change_case') }}
      </v-card-title>      
      <v-card-text>
        <div class="text-caption text--secondary mb-2">{{ $t('change_case_description') }}</div>        
        <v-divider></v-divider>
        <div class="text-caption text--secondary mb-2">{{ $t('type') }}</div>
        <v-select
          v-model="changeCaseType"
          label=""
          :items="[
            { text: $t('title_case'), value: 'title' },
            { text: $t('uppercase'), value: 'upper' },
            { text: $t('lowercase'), value: 'lower' }
          ]"
          item-text="text"
          item-value="value"
          outlined
          dense
          hide-details
          class="mb-4"
        ></v-select>

        
        <v-checkbox
          v-model="changeCaseFields"
          value="name"
          hide-details
          class="mt-0"
          dense
        >
          <template v-slot:label>
            <span class="body-2">{{ $t('name') }}</span>
          </template>
        </v-checkbox>
        <v-checkbox
          v-model="changeCaseFields"
          value="labl"
          hide-details
          class="mt-0"
          dense
        >
          <template v-slot:label>
            <span class="body-2">{{ $t('label') }}</span>
          </template>
        </v-checkbox>

        <v-alert
          v-if="changeCaseUpdateStatus"
          type="info"
          dense
          outlined
          class="mt-3 mb-0"
        >
          {{ changeCaseUpdateStatus }}
        </v-alert>
      </v-card-text>
      <v-divider></v-divider>
      <v-card-actions class="px-4 pb-4 pt-3" style="flex-direction: column; align-items: stretch;">
        <v-btn
          :disabled="changeCaseFields.length === 0"
          color="primary"
          block
          @click="changeCase"
        >
          {{ $t('apply') }}
        </v-btn>
        <v-btn
          :disabled="changeCaseFields.length === 0"
          block
          text
          class="mt-2"
          @click="changeCaseDialog = false"
        >
          {{ $t('cancel') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>