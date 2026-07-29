const huawei_online_url = window.location.protocol + "//" + window.location.hostname +
    (window.location.port ? ':' + window.location.port : '') + "/admin/";
add_menu.provedor('{"plink":"' + huawei_online_url +
    'addons/huawei_online/","ptext":"Huawei Online"}');
