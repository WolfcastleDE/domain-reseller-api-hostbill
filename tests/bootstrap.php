<?php

// Load the HostBill DomainModule stub before autoload resolves the real classes
require_once __DIR__ . '/stubs/DomainModule.php';

// Composer autoloader (loads DomainResellerApiClient + DomainResellerApi from classmap)
require_once __DIR__ . '/../vendor/autoload.php';
