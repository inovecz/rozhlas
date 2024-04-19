<script setup>

import {durationToTime, formatDate} from "../../helper.js";
import {onMounted, reactive, ref} from "vue";
import ScheduleService from "../../services/ScheduleService.js";
import router from "../../router.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import {useToast} from "vue-toastification";

const schedules = ref([]);
let orderColumn = 'scheduled_at';
let orderAsc = true;
const pageLength = ref(5);
const search = reactive({value: null});
const toast = useToast();

const props = defineProps({
  type: String
});

onMounted(() => {
  fetchRecords();
});

emitter.on('refetchScheduleLists', () => {
  fetchRecords();
});

function fetchRecords(paginatorUrl) {
  schedules.value = ScheduleService.fetchRecords(props.type, paginatorUrl, search.value, pageLength.value, orderColumn, orderAsc).then(response => {
    schedules.value = response;
    if (response.next_end_at !== null) {
      const targetDateTime = new Date(response.next_end_at);
      const timeDiff = targetDateTime.getTime() - Date.now();
      if (timeDiff > 0) {
        // + 10s due to the delay of the server
        setTimeout(() => {emitter.emit('refetchScheduleLists')}, timeDiff + 10000);
      } else {
        console.error("Čas pro refetch je v minulosti");
      }
    }
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function orderBy(column) {
  if (orderColumn === column) {
    orderAsc = !orderAsc;
  } else {
    orderColumn = column;
    orderAsc = true;
  }
  fetchRecords();
}

function editSchedule(id) {
  router.push({name: 'EditSchedule', params: {id}});
}

function deleteSchedule(id) {
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu si přejete smazat úkol?',
    message: 'Tato akce je nevratná, dojde k trvalému smazání úkolu.'
  });
  reveal();
  onConfirm(() => {
    ScheduleService.deleteRecord(id).then(() => {
      fetchRecords();
      toast.success('Záznam byl úspěšně smazán');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat záznam');
    });
  });
}
</script>

<template>
  <div class="component-box">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        {{ props.type === 'planned' ? 'Naplánované úkoly' : 'Archivované úkoly' }}
      </div>
      <router-link v-if="props.type==='planned'"
                   :to="{ name: 'CreateSchedule' }"
                   class="btn btn-sm btn-primary">
        Nový úkol
      </router-link>
    </div>
    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('title')">
              <div class="flex items-center cursor-pointer underline">
                Název
                <span v-if="orderColumn === 'title'">
                      <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
              </div>
            </th>
            <th @click="orderBy('scheduled_at')">
              <div class="flex items-center cursor-pointer underline">
                {{ props.type === 'planned' ? 'Naplánováno na' : 'Spuštěno' }}
                <span v-if="orderColumn === 'scheduled_at'">
                      <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
              </div>
            </th>
            <th @click="orderBy('is_repeating')">
              <div class="flex items-center cursor-pointer underline">
                Opakování
                <span v-if="orderColumn === 'is_repeating'">
                      <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
              </div>
            </th>
            <th @click="orderBy('duration')">
              <div class="flex items-center cursor-pointer underline">
                Délka
                <span v-if="orderColumn === 'duration'">
                      <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="schedule in schedules.data" :key="schedule.id" class="hover">
            <td>
              <div class="flex items-center gap-3">
                <div>
                  <div class="font-bold">{{ schedule.title }}</div>
                </div>
              </div>
            </td>
            <td>
              {{ formatDate(new Date(schedule.scheduled_at), 'd.m.Y H:i') }}
            </td>
            <td>
              <span v-if="schedule.is_repeating" class="mdi mdi-check text-xl"></span>
              <span v-else class="mdi mdi-close text-xl"></span>
            </td>
            <td>
              {{ durationToTime(schedule.duration) }}
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="editSchedule(schedule.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button @click="deleteSchedule(schedule.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="schedules?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength" @change="fetchRecords()" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="schedules?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in records?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>