<?php
require __DIR__ . '/../src/bootstrap.php';

use App\DB\Connection;

$pdo = Connection::get();

$tables = [
    'active_alerts',
    'incoming_alerts',
    'pending_alerts',
    'sent_alerts',
    'zones'
];

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function qs(array $overrides = []) { $params = array_merge($_GET, $overrides); return http_build_query($params); }

$selected = $_GET['table'] ?? $tables[0];
if (!in_array($selected, $tables, true)) {
    $selected = $tables[0];
}

$perPageDefault = 100;
$perPageMax = 1000;
$pageParam = "{$selected}_page";
$page = max(1, (int)($_GET[$pageParam] ?? 1));
$perPage = max(1, min($perPageMax, (int)($_GET['per_page'] ?? $perPageDefault)));

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM " . $selected);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $total = 0;
}

$offset = ($page - 1) * $perPage;
$maxPage = max(1, (int)ceil(max(1, $total) / $perPage));

try {
    $stmt = $pdo->prepare("SELECT * FROM " . $selected . " ORDER BY rowid ASC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

try {
    $colStmt = $pdo->query("PRAGMA table_info(" . $selected . ")");
    $colsInfo = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = array_map(fn($c) => $c['name'], $colsInfo);
} catch (Exception $e) {
    $cols = $rows[0] ? array_keys($rows[0]) : [];
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>View table: <?php echo esc($selected); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{
      --bg:#f7fafc; --card:#ffffff; --muted:#6b7280; --accent:#0d6efd; --accent-2:#2563eb;
      --border:#e6edf3; --zebra:#fbfdff;
      --pad:12px;
    }
    html,body{height:100%;margin:0;font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:var(--bg);color:#0f172a}
    .wrap{max-width:1200px;margin:24px auto;padding:18px}
    header{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
    .brand{font-size:18px;font-weight:700;color:var(--accent-2)}
    .tabs{display:flex;gap:8px;flex-wrap:wrap}
    .tab{background:transparent;border:1px solid var(--border);padding:8px 12px;border-radius:8px;color:#0f172a;text-decoration:none;font-weight:600}
    .tab.active{background:linear-gradient(180deg, #0d6efd 0%, #2563eb 100%);color:#fff;border-color:transparent;box-shadow:0 6px 18px rgba(37,99,235,0.12)}
    .controls{margin-left:auto;display:flex;gap:8px;align-items:center}
    .btn{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);cursor:pointer;font-weight:600}
    .btn.primary{background:var(--accent);color:#fff;border-color:transparent}
    .meta{color:var(--muted);font-size:13px;margin-left:8px}
    .card{background:var(--card);border-radius:12px;padding:14px;border:1px solid var(--border);box-shadow:0 6px 18px rgba(2,6,23,0.04)}
    .toolbar{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
    .search{padding:10px;border:1px solid var(--border);border-radius:8px;min-width:220px}
    .table-wrap{overflow:auto;border-radius:8px;border:1px solid var(--border);margin-top:8px}
    table{width:100%;border-collapse:collapse;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, "Roboto Mono", monospace;font-size:13px}
    thead th{position:sticky;top:0;background:linear-gradient(180deg,#fff,#fbfdff);padding:12px;text-align:left;border-bottom:1px solid var(--border);z-index:2}
    tbody td{padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
    tbody tr:nth-child(even){background:var(--zebra)}
    .small{font-size:12px;color:var(--muted)}
    .pager{display:flex;gap:8px;align-items:center;margin-top:12px}
    @media (max-width:720px){ .controls{width:100%;justify-content:stretch} .search{flex:1} .tabs{width:100%;} }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="brand">Alerts — DB Viewer</div>

      <nav class="tabs" aria-label="Tables">
        <?php foreach ($tables as $t): ?>
          <a class="tab <?php echo $t === $selected ? 'active' : ''; ?>" href="?<?php echo qs(['table' => $t, 'per_page' => $perPage, "{$t}_page" => 1]); ?>"><?php echo esc($t); ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="controls">
        <div class="meta">Rows: <?php echo $total; ?></div>
        <button class="btn" onclick="location.href='/users_table.php'">Back to App</button>
      </div>
    </header>

    <section class="card" aria-labelledby="table-title">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 id="table-title" style="margin:0"><?php echo esc($selected); ?></h2>
        <div class="small"> — page <?php echo $page; ?> / <?php echo $maxPage; ?></div>
      </div>

      <div class="toolbar" style="margin-top:12px">
        <input id="q" class="search" placeholder="Filter visible rows (client-side) — type columns or values">
        <select id="colFilter" class="search" style="max-width:220px">
          <option value="">Filter column (all)</option>
          <?php foreach($cols as $c): ?><option value="<?php echo esc($c); ?>"><?php echo esc($c); ?></option><?php endforeach; ?>
        </select>
        <button class="btn" id="exportCsv">Export CSV</button>
        <button class="btn" id="copyTable">Copy</button>

        <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
          <form method="get" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="table" value="<?php echo esc($selected); ?>">
            <label class="small">Per page:
              <input type="number" name="per_page" value="<?php echo esc($perPage); ?>" min="1" max="<?php echo $perPageMax; ?>" style="width:90px;margin-left:8px;padding:6px;border-radius:6px;border:1px solid var(--border)">
            </label>
            <button type="submit" class="btn">Apply</button>
          </form>
        </div>
      </div>

      <div class="table-wrap" id="tableWrap" tabindex="0">
        <table id="dataTable" role="table" aria-label="Table data">
          <thead>
            <tr>
              <?php foreach ($cols as $c): ?><th><?php echo esc($c); ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <?php foreach ($cols as $c): ?>
                  <td><?php echo esc($r[$c] ?? ''); ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="<?php echo max(1, count($cols)); ?>" class="small">No rows to display.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pager">
        <?php if ($page > 1): ?>
          <a class="btn" href="?<?php echo qs([$pageParam => $page - 1, 'table' => $selected, 'per_page' => $perPage]); ?>">« Prev</a>
        <?php endif; ?>
        <?php if ($page < $maxPage): ?>
          <a class="btn primary" href="?<?php echo qs([$pageParam => $page + 1, 'table' => $selected, 'per_page' => $perPage]); ?>">Next »</a>
        <?php endif; ?>
      </div>
    </section>

    <footer style="margin-top:18px;color:var(--muted);font-size:13px">
      Read-only viewer — non-destructive. Showing only the selected table.
    </footer>
  </div>

<script>
(function(){
  const q = document.getElementById('q');
  const colFilter = document.getElementById('colFilter');
  const table = document.getElementById('dataTable');
  const tbody = table.querySelector('tbody');

  function textOf(row){
    return Array.from(row.cells).map(td=>td.textContent.trim()).join('||').toLowerCase();
  }

  function applyFilter(){
    const term = (q.value||'').toLowerCase().trim();
    const col = colFilter.value;
    Array.from(tbody.rows).forEach(row=>{
      if (!term){
        row.style.display='';
        return;
      }
      if (col){
        const idx = Array.from(table.tHead.rows[0].cells).findIndex(c=>c.textContent===col);
        if (idx >= 0){
          const cellText = (row.cells[idx]?.textContent||'').toLowerCase();
          row.style.display = cellText.includes(term) ? '' : 'none';
          return;
        }
      }
      row.style.display = textOf(row).includes(term) ? '' : 'none';
    });
  }

  q.addEventListener('input', applyFilter);
  colFilter.addEventListener('change', applyFilter);

  document.getElementById('exportCsv').addEventListener('click', () => {
    const rows = [Array.from(table.tHead.rows[0].cells).map(th=>th.textContent)];
    Array.from(tbody.rows).forEach(tr=>{
      if (tr.style.display === 'none') return;
      rows.push(Array.from(tr.cells).map(td=>td.textContent.replace(/\n/g,' ').replace(/\r/g,'').trim()));
    });
    const csv = rows.map(r=>r.map(c=>`"${String(c).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = '<?php echo esc($selected); ?>.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  });

  document.getElementById('copyTable').addEventListener('click', async () => {
    try {
      let text = Array.from(table.tHead.rows[0].cells).map(th=>th.textContent).join('\t') + '\n';
      Array.from(tbody.rows).forEach(tr=>{
        if (tr.style.display === 'none') return;
        text += Array.from(tr.cells).map(td=>td.textContent.trim()).join('\t') + '\n';
      });
      await navigator.clipboard.writeText(text);
      alert('Table copied to clipboard');
    } catch(e) { alert('Copy failed'); }
  });

})();
</script>
</body>
</html>