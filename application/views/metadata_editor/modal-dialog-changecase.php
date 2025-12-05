<template v-if="changeCaseDialog">
  <v-row justify="center">
    <v-dialog
      v-model="changeCaseDialog"
      scrollable
      max-width="300px"
    >
      
      <v-card>
        <v-card-title>{{$t('change_case')}}</v-card-title>
        <v-divider></v-divider>
        <v-card-text>

        <div class="form-group">
            <label for="ChangeCaseType">{{$t('type')}}</label>
            <select class="form-control" id="ChangeCaseType" v-model="changeCaseType">
                <option value="title">{{$t('title_case')}}</option>
                <option value="upper">{{$t('uppercase')}}</option>
                <option value="lower">{{$t('lowercase')}}</option>      
            </select>            
        </div>

        <div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="name" id="defaultCheck1" v-model="changeCaseFields" >
                <label class="form-check-label" for="defaultCheck1">
                    {{$t('name')}}
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="labl" id="defaultCheck2" v-model="changeCaseFields" >
                <label class="form-check-label" for="defaultCheck2">
                    {{$t('label')}}
                </label>
            </div>
        </div>

        </v-card-text>
        <v-divider></v-divider>
        <v-card-actions  style="flex-direction:column;align-items: stretch;">
            <div style="text-align:center;margin-bottom:10px;">{{changeCaseUpdateStatus}}</div>
            <div>
                <v-btn :disabled="changeCaseFields.length==0" color="primary" block small  @click="changeCase" >{{$t('apply')}}</v-btn>
                <v-btn :disabled="changeCaseFields.length==0"  block small @click="changeCaseDialog=false" class="mt-2" >{{$t('cancel')}}</v-btn>
            </div>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-row>
</template>