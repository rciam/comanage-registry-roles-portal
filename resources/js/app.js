$(document).ready(function() {
    var table_assign = $('#assign-roles-tbl').DataTable({
        columnDefs: [{
            orderable: false,
            targets: [0,5,6]
        }],
        "order": [[ 0, 'asc' ]],
        responsive: true
    });

    var table_revoke = $('#revoke-roles-tbl').DataTable({
        columnDefs: [{
            orderable: false,
            targets: [0,5,6]
        }],
        "order": [[ 0, 'asc' ]],
        responsive: true
    });
} );
