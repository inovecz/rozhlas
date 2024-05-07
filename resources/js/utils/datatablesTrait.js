import {onMounted, reactive, watch} from "vue";

export function useDataTables(fetchRecords, orderColumnDefault = 'created_at', pageLengthDefault = 5) {
    const orderAsc = reactive({value: true})
    const orderColumn = reactive({value: orderColumnDefault});
    const pageLength = reactive({value: pageLengthDefault});
    const search = reactive({value: null});

    onMounted(() => {
        fetchRecords();
    });

    function orderBy(column) {
        console.log('Previous order', orderColumn.value, orderAsc.value)
        if (orderColumn.value === column) {
            orderAsc.value = !orderAsc.value;
        } else {
            orderColumn.value = column;
            orderAsc.value = true;
        }
        console.log('New order', orderColumn.value, orderAsc.value)
        fetchRecords();
    }

    watch(search, debounce(() => {
        fetchRecords();
    }, 500));

    watch(pageLength, () => fetchRecords());

    return {
        fetchRecords,
        orderAsc,
        orderBy,
        orderColumn,
        pageLength,
        search,
    };
}