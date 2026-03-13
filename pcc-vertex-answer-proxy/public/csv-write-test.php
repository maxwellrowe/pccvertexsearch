<?php
declare(strict_types=1);

function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ensure_dir(string $path): void {
  if (!is_dir($path)) {
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('Failed to create directory: ' . $path);
    }
  }
}

function resolve_writable_dir(array $candidates): ?string {
  foreach ($candidates as $candidate) {
    $dir = rtrim((string) $candidate, '/');
    if ($dir === '') {
      continue;
    }
    if (is_dir($dir)) {
      if (is_writable($dir)) {
        return $dir;
      }
      continue;
    }
    if (@mkdir($dir, 0775, true) && is_writable($dir)) {
      return $dir;
    }
  }
  return null;
}

function open_csv_for_append(string $csvPath) {
  $warning = null;
  set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
    $warning = $errstr;
    return true;
  });
  try {
    $fh = fopen($csvPath, 'ab');
  } finally {
    restore_error_handler();
  }

  if ($fh === false) {
    $message = 'Unable to open CSV for writing.';
    if ($warning) {
      $message .= ' fopen warning: ' . $warning;
    }
    $last = error_get_last();
    if (is_array($last) && !empty($last['message'])) {
      $message .= ' last error: ' . $last['message'];
    }
    throw new RuntimeException($message);
  }

  return $fh;
}

function append_test_csv_row(string $csvPath): void {
  ensure_dir(dirname($csvPath));

  $fh = open_csv_for_append($csvPath);

  try {
    if (!flock($fh, LOCK_EX)) {
      throw new RuntimeException('Unable to lock CSV file.');
    }

    if (ftell($fh) === 0) {
      if (fputcsv($fh, ['question', 'answer', 'timestamp', 'reference_urls']) === false) {
        throw new RuntimeException('Failed to write CSV header.');
      }
    }

    $row = [
      'CSV write test question',
      'CSV write test answer',
      date('c'),
      'https://pasadena.edu/student-services/get-help/index.php',
    ];
    if (fputcsv($fh, $row) === false) {
      throw new RuntimeException('Failed to write CSV row.');
    }

    fflush($fh);
    flock($fh, LOCK_UN);
  } finally {
    fclose($fh);
  }
}

$storageBase = realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage');
$preferredLogDir = $storageBase . '/logs';
$tmpLogDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/pcc-vertex-answer-proxy/logs';
$resolvedLogDir = resolve_writable_dir([$preferredLogDir, $tmpLogDir]);
$logDir = $resolvedLogDir ?: $preferredLogDir;
$csvPath = $logDir . '/question_log.csv';

$didSubmit = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$ok = false;
$error = '';
$details = [];

if ($didSubmit) {
  try {
    append_test_csv_row($csvPath);
    $ok = true;
    $details[] = 'Write completed.';
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$details[] = 'CSV path: ' . $csvPath;
$details[] = 'Preferred logs dir: ' . $preferredLogDir;
$details[] = 'Temp fallback logs dir: ' . $tmpLogDir;
$details[] = 'Resolved logs dir: ' . ($resolvedLogDir ?: '(none)');
$details[] = 'CSV dir: ' . dirname($csvPath);
$details[] = 'CSV dir realpath: ' . (realpath(dirname($csvPath)) ?: '(not resolved)');
$details[] = 'open_basedir: ' . ((string) ini_get('open_basedir') ?: '(not set)');
$details[] = 'disable_functions: ' . ((string) ini_get('disable_functions') ?: '(none)');
$details[] = 'Logs dir exists: ' . (is_dir($logDir) ? 'yes' : 'no');
$details[] = 'Logs dir writable: ' . (is_writable($logDir) ? 'yes' : 'no');
$details[] = 'CSV exists: ' . (is_file($csvPath) ? 'yes' : 'no');
$details[] = 'CSV writable: ' . ((is_file($csvPath) && is_writable($csvPath)) ? 'yes' : 'no');
$details[] = 'Process user: ' . (get_current_user() ?: '(unknown)');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CSV Write Test</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 2rem; max-width: 900px; }
    button { padding: 0.6rem 1rem; cursor: pointer; }
    .status { margin-top: 1rem; padding: 0.75rem; border-radius: 6px; }
    .ok { background: #e8f8ec; border: 1px solid #6abf81; }
    .err { background: #fdecec; border: 1px solid #dc6b6b; }
    pre { margin-top: 1rem; background: #f6f8fa; padding: 1rem; border-radius: 6px; overflow-x: auto; }
  </style>
</head>
<body>
  <h1>CSV Write Test</h1>
  <p>Click the button to append one test row to <code>storage/logs/question_log.csv</code>.</p>

  <form method="post">
    <button type="submit">Write Test Row</button>
  </form>

  <?php if ($didSubmit && $ok): ?>
    <div class="status ok">Success: test row written.</div>
  <?php endif; ?>

  <?php if ($didSubmit && !$ok): ?>
    <div class="status err">Error: <?= h($error === '' ? 'Unknown error.' : $error) ?></div>
  <?php endif; ?>

  <pre><?= h(implode("\n", $details)) ?></pre>
</body>
</html>
