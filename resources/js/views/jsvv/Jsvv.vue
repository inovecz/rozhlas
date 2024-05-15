<script setup>

import {onMounted, ref} from "vue";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import {useToast} from "vue-toastification";
import router from "../../router.js";

const jsvvAlarms = ref([]);
const toast = useToast();

onMounted(() => {
  fetchJsvvAlarms();
});

function fetchJsvvAlarms() {
  JsvvAlarmService.fetchJsvvAlarms().then(response => {
    jsvvAlarms.value = response.data;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}


function editJsvvAlarm(id) {
  router.push({name: 'EditJSVV', params: {id}});
}

function sendAlarm(id) {
  const foundJsvvAlarm = jsvvAlarms.value.find(jsvvAlarm => jsvvAlarm.id === id);
  const {reveal, onConfirm, onCancel} = createConfirmDialog(ModalDialog, {
    title: foundJsvvAlarm.name,
    message: 'Alarm bude odeslán do ústředny.'
  });
  reveal();
  onConfirm(() => {
    // Odeslani alarmu
    toast.success('Alarm byl odeslán do ústředny.')
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Poplach JSVV</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Seznam poplachů
          </div>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th>Poplach</th>
              <th>Sekvence</th>
              <th>Mobilní tlačítko</th>
              <th class="text-right">Akce</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="jsvvAlarm of jsvvAlarms" :key="jsvvAlarm.id">
              <td>
                <button @click="sendAlarm(jsvvAlarm.id)" class="btn btn-secondary btn-sm btn-square mr-2"><span class="mdi mdi-play"></span></button>
                {{ jsvvAlarm.name }}
              </td>
              <td>{{ jsvvAlarm.sequence }}</td>
              <td>{{ jsvvAlarm.mobile_button }}</td>
              <td class="flex gap-2 justify-end items-center pt-4">
                <button @click="editJsvvAlarm(jsvvAlarm.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
                <button @click="deleteJsvvAlarm(jsvvAlarm.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
              </td>
            </tr>
          </tbody>
        </table>

      </div>
    </div>
  </div>
</template>

<style scoped>

</style>