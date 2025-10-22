<script setup>

import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import LocationService from "../../services/LocationService.js";
import {ref} from "vue";
import router from "../../router.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";

const locationGroups = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchLocationGroups, 'name', 5);

function fetchLocationGroups(paginatorUrl = null) {
  LocationService.fetchLocationGroups(paginatorUrl, search.value, pageLength.value, orderColumn.value, orderAsc.value).then(response => {
    locationGroups.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function editLocationGroup(id = null) {
  router.push({name: 'EditLocationGroup', params: {id}});
}

function deleteLocationGroup(id) {
  const foundLocationGroup = locationGroups.value.data.find(locationGroup => locationGroup.id === id);
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu chcete smazat lokalitu?',
    message: `Lokalita ${foundLocationGroup.name} bude trvale smazána. Spolu s tím dojde k odpojení všech míst, která jsou s touto lokalitou svázána. Tato akce je nevratná.`
  });
  reveal();
  onConfirm(() => {
    LocationService.deleteLocationGroup(id).then(() => {
      toast.success('Lokalita byla smazána');
      fetchRecords();
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat lokalitu');
    });
  });
}
</script>

<template>
  <PageContent label="Nastavení lokalit">
    <Box label="Seznam lokalit">
      <template #header>
        <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
        <Button route-to="CreateLocationGroup" icon="mdi-plus-box" size="sm"/>
      </template>

      <div class="overflow-x-auto">
        <table class="table">
          <!-- head -->
          <thead>
            <tr>
              <th @click="orderBy('name')">
                <div class="flex items-center cursor-pointer underline">
                  Název lokality
                  <span v-if="orderColumn.value === 'name'">
                    <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th>
                <div class="flex items-center">
                  Počet míst
                </div>
              </th>
              <th @click="orderBy('modbus_group_address')">
                <div class="flex items-center cursor-pointer underline">
                  Modbus adresa
                  <span v-if="orderColumn.value === 'modbus_group_address'">
                    <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th>
                <div class="flex items-center">
                  Typ subtónu
                </div>
              </th>
              <th>
                <div class="flex items-center">
                  Subtón
                </div>
              </th>
              <th>
                <div class="flex items-center">
                  Subtón (záznam)
                </div>
              </th>
              <th class="text-right">Akce</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="locationGroup in locationGroups.data" :key="locationGroup.id" class="hover">
              <td>
                {{ locationGroup.name }}
                <span v-if="locationGroup.is_hidden" class="mdi mdi-eye-off"></span>
              </td>
              <td>
                <span>{{ locationGroup.locations_count }}</span>
              </td>
              <td>
                <span>{{ typeof locationGroup.modbus_group_address === 'number' ? locationGroup.modbus_group_address : (locationGroup.modbus_group_address ?? '-') }}</span>
              </td>
              <td>
                <span>{{ locationGroup.subtone_type }}</span>
              </td>
              <td>
                <span v-for="(listenSubtone, index) of locationGroup.subtone_data.listen" :key="index">
                  {{ listenSubtone }}{{ index < locationGroup.subtone_data.listen.length - 1 ? ', ' : '' }}
                </span>
              </td>
              <td>
                <span v-for="(recordSubtone, index) of locationGroup.subtone_data.record" :key="index">
                  {{ recordSubtone }}{{ index < locationGroup.subtone_data.record.length - 1 ? ', ' : '' }}
                </span>
              </td>
              <td class="flex gap-2 justify-end items-center pt-4">
                <button @click="editLocationGroup(locationGroup.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
                <button @click="deleteLocationGroup(locationGroup.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="locationGroups?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
        <div class="flex justify-between items-center py-2 px-1">
          <div>
            <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
              <option value="5">5</option>
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
          </div>
          <div v-if="locationGroups?.meta?.last_page > 1">
            <div class="flex justify-center items-center">
              <div class="join join-horizontal">
                <template v-for="page in locationGroups?.meta?.links">
                  <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>

    </Box>
  </PageContent>
</template>

<style scoped>
</style>
