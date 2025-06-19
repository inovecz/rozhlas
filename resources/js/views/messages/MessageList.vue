<script setup>
import {ref} from "vue";
import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import MessageService from "../../services/MessageService.js";
import {formatDate} from "../../helper.js";
import Box from "../../components/custom/Box.vue";
import Select from "../../components/forms/Select.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";

const messages = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchMessages, 'created_at', 10, false);

const channels = {
  'SMS': 'SMS',
  'EMAIL': 'E-mail'
}

const states = {
  'SENT': 'Odesláno',
  'RECEIVED': 'Přijato',
  'FAILED': 'Chyba'
}

const filter = ref({
  type: '',
  state: '',
});

function fetchMessages(paginatorUrl) {
  MessageService.fetchMessages(paginatorUrl, search, pageLength, orderColumn.value, orderAsc.value, filter.value).then(response => {
    messages.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <Box label="Seznam zpráv">
    <template #header>
      <div class="flex flex-col gap-2">


        <div class="flex flex-wrap justify-end gap-4">
          <Select v-model="filter.type" @change="fetchMessages()" :options="[
            {value: '', label: 'Všechny kanály'},
            {value: 'SMS', label: 'SMS'},
            {value: 'EMAIL', label: 'E-mail'}
            ]" data-class="select-bordered select-sm"/>
          <Select v-model="filter.state" @change="fetchMessages()" :options="[
            {value: '', label: 'Všechny stavy'},
            {value: 'SENT', label: 'Odesláno'},
            {value: 'RECEIVED', label: 'Přijato'},
            {value: 'FAILED', label: 'Chyba'}
            ]" data-class="select-bordered select-sm"/>
        </div>
        <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
      </div>
      <Button route-to="CreateMessage" icon="mdi-plus-box" size="sm" class="btn-primary"/>
    </template>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('contact.fullname')">
              <div class="flex items-center cursor-pointer underline">
                Kontakt
                <span v-if="orderColumn.value === 'contact.fullname'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('type')">
              <div class="flex items-center cursor-pointer underline">
                Kanál
                <span v-if="orderColumn.value === 'type'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('state')">
              <div class="flex items-center cursor-pointer underline">
                Stav
                <span v-if="orderColumn.value === 'state'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer underline">
                Datum
                <span v-if="orderColumn.value === 'created_at'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('content')">
              <div class="flex items-center cursor-pointer underline">
                Zpráva
                <span v-if="orderColumn.value === 'content'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="message in messages.data" :key="message.id" class="hover">
            <td>
              {{ message.contact.fullname }}
            </td>
            <td>
              {{ channels[message.type] }}
            </td>
            <td>
              {{ states[message.state] }}
            </td>
            <td>
              {{ formatDate(new Date(message.created_at), 'd.m.Y H:i') }}
            </td>
            <td class="max-w-36">
              <div class="truncate" :title="message.content">{{ message.content }}</div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="messages?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="messages?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in messages?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </Box>
</template>

<style scoped>
</style>