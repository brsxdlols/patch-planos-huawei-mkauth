<?php
declare(strict_types=1);
include('addons.class.php');

if (!isset($link) || !($link instanceof mysqli)) {
    die('Não foi possível acessar o banco do MK-AUTH.');
}

$nasList = [];
$query = $link->query(
    "SELECT n.nasname, n.shortname, COUNT(r.radacctid) AS abertas,
            MAX(r.acctupdatetime) AS ultima
       FROM nas n
       LEFT JOIN radacct r
         ON BINARY r.nasipaddress = BINARY n.nasname
        AND r.acctstoptime IS NULL
      GROUP BY n.id, n.nasname, n.shortname
      ORDER BY ultima DESC, n.shortname"
);
while ($row = $query->fetch_assoc()) {
    $nasList[] = $row;
}

$selectedNas = trim((string)($_GET['nas'] ?? ''));
if ($selectedNas === '' && $nasList) {
    $selectedNas = (string)$nasList[0]['nasname'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MK-AUTH :: Huawei Online</title>
    <link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css">
    <link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css">
    <link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css">
    <script src="../../scripts/jquery.js"></script>
    <script src="../../scripts/mk-auth.js"></script>
    <style>
        .hw-wrap{padding:1rem;max-width:1600px;margin:auto}
        .hw-cards{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:.75rem;margin:1rem 0}
        .hw-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05)}
        .hw-card small{color:#64748b}.hw-card strong{display:block;font-size:1.35rem;color:#0f172a;margin-top:.25rem}
        .hw-tools{display:flex;gap:.75rem;align-items:end;flex-wrap:wrap}
        .hw-tools label{font-weight:700}.hw-tools select,.hw-tools input{min-width:230px;padding:.55rem;border:1px solid #cbd5e1;border-radius:6px}
        .hw-table-wrap{overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px}
        .hw-table{width:100%;border-collapse:collapse;white-space:nowrap}
        .hw-table th,.hw-table td{padding:.65rem .75rem;border-bottom:1px solid #eef2f7;text-align:left;font-size:.85rem}
        .hw-table th{position:sticky;top:0;background:#0f172a;color:#fff}
        .hw-up{color:#0284c7;font-weight:700}.hw-down{color:#16a34a;font-weight:700}
        .hw-stale{color:#dc2626;font-weight:700}.hw-ok{color:#16a34a}
        .hw-note{background:#eff6ff;border-left:4px solid #2563eb;padding:.75rem;margin:.8rem 0}
        @media(max-width:900px){.hw-cards{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<?php include('../../topo.php'); ?>
<div class="hw-wrap">
    <nav class="breadcrumb has-bullet-separator" aria-label="breadcrumbs">
        <ul><li><a href="#">ADDON</a></li><li class="is-active"><a>Huawei Online</a></li></ul>
    </nav>

    <div class="hw-tools">
        <form method="get">
            <label for="nas">NAS Huawei</label><br>
            <select id="nas" name="nas" onchange="this.form.submit()">
                <?php foreach ($nasList as $nas): ?>
                    <option value="<?= htmlspecialchars($nas['nasname']) ?>"
                        <?= $selectedNas === $nas['nasname'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($nas['shortname'] ?: 'Sem nome') . ' — ' . $nas['nasname'] . ' (' . $nas['abertas'] . ' abertas)') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div>
            <label for="busca">Filtrar clientes</label><br>
            <input id="busca" type="search" placeholder="Login, nome, IP, MAC ou plano">
        </div>
        <div><span id="status">Carregando…</span></div>
    </div>

    <div class="hw-note">
        A taxa é calculada entre dois Interim-Updates do RADIUS. No NAS atual,
        a maioria das sessões usa intervalo de 180 segundos; a primeira taxa
        aparece depois da próxima atualização do cliente.
    </div>

    <div class="hw-cards">
        <div class="hw-card"><small>Clientes online</small><strong id="online">—</strong></div>
        <div class="hw-card"><small>Download atual</small><strong id="download">—</strong></div>
        <div class="hw-card"><small>Upload atual</small><strong id="upload">—</strong></div>
        <div class="hw-card"><small>Sessões medidas</small><strong id="medidos">—</strong></div>
    </div>

    <div class="hw-table-wrap">
        <table class="hw-table">
            <thead><tr>
                <th>Cliente</th><th>IP / MAC</th><th>Plano</th>
                <th>Download</th><th>Upload</th><th>Acumulado</th>
                <th>Online</th><th>Porta</th><th>Atualização</th>
            </tr></thead>
            <tbody id="clientes"><tr><td colspan="9">Carregando sessões…</td></tr></tbody>
        </table>
    </div>
</div>
<?php include('../../baixo.php'); ?>
<script src="../../menu.js.hhvm"></script>
<script>
const selectedNas = <?= json_encode($selectedNas, JSON_UNESCAPED_SLASHES) ?>;
let sessions = [];

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function render() {
    const term = document.getElementById('busca').value.toLowerCase().trim();
    const filtered = sessions.filter(s =>
        [s.login,s.nome,s.ip,s.mac,s.plano,s.porta].join(' ').toLowerCase().includes(term)
    );
    const body = document.getElementById('clientes');
    if (!filtered.length) {
        body.innerHTML = '<tr><td colspan="9">Nenhuma sessão encontrada.</td></tr>';
        return;
    }
    body.innerHTML = filtered.map(s => {
        const stale = s.atraso > Math.max(300, (s.intervalo || 180) * 2);
        return `<tr>
            <td><strong>${esc(s.login)}</strong><br><small>${esc(s.nome)}</small></td>
            <td>${esc(s.ip)}<br><small>${esc(s.mac)}</small></td>
            <td>${esc(s.plano)}</td>
            <td class="hw-down">${esc(s.download_taxa)}</td>
            <td class="hw-up">${esc(s.upload_taxa)}</td>
            <td>↓ ${esc(s.download_total)}<br>↑ ${esc(s.upload_total)}</td>
            <td>${esc(s.online)}</td><td>${esc(s.porta)}</td>
            <td class="${stale ? 'hw-stale' : 'hw-ok'}">${esc(s.atualizado)}<br><small>${s.atraso}s atrás</small></td>
        </tr>`;
    }).join('');
}

async function loadData() {
    if (!selectedNas) return;
    const status = document.getElementById('status');
    try {
        const response = await fetch(`api.php?nas=${encodeURIComponent(selectedNas)}`, {cache:'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Falha na leitura');
        sessions = data.sessoes;
        document.getElementById('online').textContent = data.resumo.online;
        document.getElementById('download').textContent = data.resumo.download_taxa;
        document.getElementById('upload').textContent = data.resumo.upload_taxa;
        document.getElementById('medidos').textContent = `${data.resumo.medidos}/${data.resumo.online}`;
        status.textContent = `Atualizado em ${data.gerado_em}`;
        status.className = 'hw-ok';
        render();
    } catch (error) {
        status.textContent = error.message;
        status.className = 'hw-stale';
    }
}

document.getElementById('busca').addEventListener('input', render);
loadData();
setInterval(loadData, 10000);
</script>
</body>
</html>
