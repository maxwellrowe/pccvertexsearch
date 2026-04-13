<?php
declare(strict_types=1);

// Optional overrides before include:
// $pccWidgetScript = '/_resources/vertex/public/embed.js';
// $pccWidgetEndpoint = '/_resources/vertex/public/api/answer.php';
// $pccWidgetFeedbackEndpoint = '/_resources/vertex/public/api/feedback.php';
// $pccWidgetVersion = '20260313-1';
$script = $pccWidgetScript ?? '/_resources/vertex/public/embed.js';
$endpoint = $pccWidgetEndpoint ?? '/_resources/vertex/public/api/answer.php';
$feedbackEndpoint = $pccWidgetFeedbackEndpoint ?? '/_resources/vertex/public/api/feedback.php';
$version = $pccWidgetVersion ?? gmdate('Ymd');
$scriptWithVersion = $script;
if ($version !== '') {
  $scriptWithVersion .= (str_contains($scriptWithVersion, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}
?>
<script src="<?= htmlspecialchars($scriptWithVersion, ENT_QUOTES, 'UTF-8') ?>" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>" data-feedback-endpoint="<?= htmlspecialchars($feedbackEndpoint, ENT_QUOTES, 'UTF-8') ?>"></script>
