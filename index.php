<?php
declare(strict_types=1);

/*
  Simple PHP URL Shortener
  - data stored in data/data.json
  - run with: php -S localhost:8000
  - API: GET /?action=list  or  GET /api (returns JSON)
*/

function data_file(): string { return __DIR__ . '/data/data.json'; }
function ensure_data_file(): void {
    $p = data_file();
    if (!file_exists($p)) {
        if (!is_dir(dirname($p))) {
            mkdir(dirname($p), 0777, true);
        }
        file_put_contents($p, json_encode([]));
    }
}
function load_items(): array {
    ensure_data_file();
    $json = file_get_contents(data_file());
    $arr = json_decode($json ?: '[]', true);
    return is_array($arr) ? $arr : [];
}
function save_items(array $items): void {
    $tmp = data_file() . '.tmp';
    file_put_contents($tmp, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, data_file());
}
function find_by_short(string $code, array $items) {
    foreach ($items as $it) if (($it['short'] ?? '') === $code) return $it;
    return null;
}
function gen_short_code(array $items): string {
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $len = 6;
    for ($attempt=0;$attempt<10;$attempt++){
        $code = '';
        for ($i=0;$i<$len;$i++){
            $code .= $alphabet[random_int(0,61)];
        }
        if (find_by_short($code,$items) === null) return $code;
    }
    return substr(md5((string)microtime(true)), 0, $len);
}
function normalize_and_validate_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'http://' . $url;
    if (filter_var($url, FILTER_VALIDATE_URL)) return $url;
    return null;
}

// ---- Routing ----
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Redirect if short code is accessed
if ($uriPath !== '/' && $uriPath !== '/index.php') {
    $code = ltrim($uriPath, '/');
    $items = load_items();
    $found = find_by_short($code, $items);
    if ($found) {
        $original = trim($found['url'] ?? '');
        if (!preg_match('#^https?://#i', $original)) {
            $original = 'http://' . $original;
        }
        foreach ($items as &$it) {
            if (($it['short'] ?? '') === $code) {
                $it['visits'] = (int)($it['visits'] ?? 0) + 1;
                break;
            }
        }
        save_items($items);
        header('Location: ' . $original, true, 302);
        exit;
    }
    http_response_code(404);
    echo "<h2>404 - Short link not found</h2><p><a href='/'>Back</a></p>";
    exit;
}

// Create new short URL
$feedback = '';
if ($method === 'POST' && isset($_POST['longurl'])) {
    $url = normalize_and_validate_url($_POST['longurl'] ?? '');
    if ($url === null) {
        $feedback = "Invalid URL. Use format like https://example.com";
    } else {
        $items = load_items();
        foreach ($items as $it) {
            if (($it['url'] ?? '') === $url) {
                $created = $it;
                $feedback = "URL already shortened.";
                break;
            }
        }
        if (!isset($created)) {
            $short = gen_short_code($items);
            $created = [
                'short' => $short,
                'url' => $url,
                'created_at' => date('Y-m-d H:i:s'),
                'visits' => 0
            ];
            $items[] = $created;
            save_items($items);
            $feedback = "Short link created.";
        }
    }
}

// Simple API
if (isset($_GET['action']) && in_array($_GET['action'], ['api','list'])) {
    header('Content-Type: application/json');
    echo json_encode(load_items());
    exit;
}

$items = load_items();
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hostUrl = $proto . '://' . $_SERVER['HTTP_HOST'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>URL Shortener</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f5f6fa}</style>
</head>
<body class="p-4">
<div class="container">
  <h1 class="mb-3">URL Shortener</h1>
  <?php if ($feedback): ?><div class="alert alert-info"><?= htmlspecialchars($feedback) ?></div><?php endif; ?>
  <div class="card mb-4">
    <div class="card-body">
      <form method="post" class="row g-2">
        <div class="col-md-9"><input name="longurl" class="form-control" placeholder="Enter a long URL" required></div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary">Create</button></div>
      </form>
      <small class="text-muted">Links look like <code><?= htmlspecialchars($hostUrl) ?>/code</code></small>
    </div>
  </div>
  <?php if (isset($created)): ?>
    <div class="alert alert-success">
      Short URL: <a target="_blank" href="<?= htmlspecialchars($hostUrl . '/' . $created['short']) ?>"><?= htmlspecialchars($hostUrl . '/' . $created['short']) ?></a>
    </div>
  <?php endif; ?>
  <div class="card">
    <div class="card-body">
      <h5>Saved Links</h5>
      <?php if (!$items): ?>
        <p class="text-muted">No links yet.</p>
      <?php else: ?>
        <table class="table table-sm">
          <thead><tr><th>Short</th><th>Original</th><th>Created</th><th>Visits</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($items) as $it): ?>
            <tr>
              <td><a target="_blank" href="<?= htmlspecialchars($hostUrl . '/' . $it['short']) ?>"><?= htmlspecialchars($it['short']) ?></a></td>
              <td><a target="_blank" href="<?= htmlspecialchars($it['url']) ?>"><?= htmlspecialchars($it['url']) ?></a></td>
              <td><?= htmlspecialchars($it['created_at']) ?></td>
              <td><?= htmlspecialchars($it['visits']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
