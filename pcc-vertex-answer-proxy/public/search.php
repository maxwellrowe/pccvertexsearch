<?php
// Include this fragment inside a page body. The host supplies Bootstrap 5 CSS.
// Override $pccSearchBase for deployments at another public URL.
$pccSearchBase = rtrim($pccSearchBase ?? '/_resources/vertex/public', '/');
$pccSearchQueryParam = $pccSearchQueryParam ?? 'q';
?>
<script src="<?= htmlspecialchars($pccSearchBase . '/embed.js', ENT_QUOTES, 'UTF-8') ?>" data-mode="inline" data-query-param="<?= htmlspecialchars($pccSearchQueryParam, ENT_QUOTES, 'UTF-8') ?>" data-endpoint="<?= htmlspecialchars($pccSearchBase . '/api/answer.php', ENT_QUOTES, 'UTF-8') ?>" data-feedback-endpoint="<?= htmlspecialchars($pccSearchBase . '/api/feedback.php', ENT_QUOTES, 'UTF-8') ?>"></script>
