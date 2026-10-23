<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Search PCC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap-override.css" rel="stylesheet">
  <link href="./css/legacy.css" rel="stylesheet">
  <link href="./css/utilities.css" rel="stylesheet">
  <link href="./css/components.css" rel="stylesheet">
  <link href="./css/pcc-custom.css" rel="stylesheet">
</head>
<body>
  <main class="container py-4" style="max-width: 960px">
    <h1 class="h3 mb-4">Search PCC</h1>
    <?php
    $pccSearchBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/search-test.php')), '/');
    include __DIR__ . '/search.php';
    ?>
  </main>
</body>
</html>
