<?php
declare(strict_types=1);

$pccWidgetScript = '/embed.js';
$pccWidgetEndpoint = '/api/answer.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Widget Test</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
  </style>
</head>
<body>
  <h1>Widget Test Page</h1>
  <p>This page loads the Lance widget with local paths.</p>

  <?php include __DIR__ . '/widget.php'; ?>
</body>
</html>
