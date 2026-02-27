<?php
declare(strict_types=1);

// Optional overrides before include:
// $pccWidgetScript = '/_resources/lance-widget/public/embed.js';
// $pccWidgetEndpoint = '/_resources/lance-widget/public/api/answer.php';
$script = $pccWidgetScript ?? '/_resources/lance-widget/public/embed.js';
$endpoint = $pccWidgetEndpoint ?? '/_resources/lance-widget/public/api/answer.php';
?>
<script src="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>"></script>
