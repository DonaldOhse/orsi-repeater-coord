<?php
require_once __DIR__ . '/includes/config.php';
$db = get_db();

// Build same filters as index.php
$where  = ['1=1'];
$params = [];
foreach (['district','type','status','county'] as $col) {
    if (!empty($_GET[$col])) { $where[] = "$col = ?"; $params[] = $_GET[$col]; }
}
if (!empty($_GET['search'])) {
    $s = '%' . $_GET['search'] . '%';
    $where[] = "(callsign LIKE ? OR trustee LIKE ? OR sponsor LIKE ? OR city LIKE ? OR county LIKE ?)";
    $params  = array_merge($params, [$s,$s,$s,$s,$s]);
}
if (!empty($_GET['freq_min'])) { $where[] = 'output_freq >= ?'; $params[] = (float)$_GET['freq_min']; }
if (!empty($_GET['freq_max'])) { $where[] = 'output_freq <= ?'; $params[] = (float)$_GET['freq_max']; }

$stmt = $db->prepare("SELECT district,type,mixed_mode,mixed_mode_types,status,private,output_freq,input_freq,callsign,trustee,sponsor,county,city,pl_tone,tone_type,dcs_code,tsq_tone,dmr_color_code,dmr_talk_group,dmr_time_slot,dstar_module,fusion_room,p25_nac,open_system,autopatch,closed_autopatch,skywarn,linked,backup_power,allstar,allstar_node,echolink,echolink_node,internet_link,date_coordinated,last_update,url,notes,latitude,longitude,location_source,antenna_height_agl,tower_height,haat,tx_power_watts,feedline_loss_db,antenna_gain_dbd,erp_watts,contact_name,contact_address,contact_city,contact_state,contact_zip,contact_email,contact_phone,internal_notes FROM repeaters WHERE " . implode(' AND ',$where) . " ORDER BY output_freq");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="OK_Repeaters_' . date('Ymd') . '.csv"');

$out = fopen('php://output','w');
fputcsv($out,['district','type','mixed_mode','mixed_mode_types','status','private','output_freq','input_freq','callsign','trustee','sponsor','county','city','pl_tone','tone_type','dcs_code','tsq_tone','dmr_color_code','dmr_talk_group','dmr_time_slot','dstar_module','fusion_room','p25_nac','open_system','autopatch','closed_autopatch','skywarn','linked','backup_power','allstar','allstar_node','echolink','echolink_node','internet_link','date_coordinated','last_update','url','notes','latitude','longitude','location_source','antenna_height_agl','tower_height','haat','tx_power_watts','feedline_loss_db','antenna_gain_dbd','erp_watts','contact_name','contact_address','contact_city','contact_state','contact_zip','contact_email','contact_phone','internal_notes']);
while ($row = $stmt->fetch()) {
    fputcsv($out, array_values($row));
}
fclose($out);
exit;
