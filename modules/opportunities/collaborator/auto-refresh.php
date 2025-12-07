<?php
declare(strict_types=1);

$autoRefreshAsset = asset('modules/opportunities/assets/collaborator-auto-refresh.js');

if (!isset($extraScripts) || !is_array($extraScripts)) {
    $extraScripts = [];
}

if (!in_array($autoRefreshAsset, $extraScripts, true)) {
    $extraScripts[] = $autoRefreshAsset;
}
