<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/StateStore.php';
require_once __DIR__ . '/PreviewValidator.php';
require_once __DIR__ . '/SourceReader.php';
require_once __DIR__ . '/ShopifyClient.php';
require_once __DIR__ . '/InitialLoad.php';
require_once __DIR__ . '/SyncPrices.php';
require_once __DIR__ . '/ProgressPresenter.php';

function app_config(): Config
{
    static $config;
    return $config ??= Config::load(dirname(__DIR__));
}

function app_store(): StateStore
{
    static $store;
    return $store ??= new StateStore(app_config()->sqlitePath);
}

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
