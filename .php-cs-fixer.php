<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->append([__DIR__ . '/ext_localconf.php']);

$rules = $config->getRules();
unset($rules['header_comment']);
$config->setRules($rules);

return $config;
