<?php

/**
 * fake-woob.php
 *
 * Test double of the woob CLI used by the tests (woob_path = "php tests/fixtures/fake-woob.php").
 * Usage: fake-woob.php bank <coming|history> <account@bank> [options...]
 *        fake-woob.php bank list -b <backend> -f json   (two accounts, "none": no account)
 *        fake-woob.php config info <module> -f json       (settings once installed, like woob 3.7)
 *        fake-woob.php config add <module> '"<backend> key=value ..."' (installs with the probe name)
 *
 * - account "fail@bank"  : writes on stderr and exits with code 1
 * - account "empty@bank" : no output
 * - account "broken@bank": no output, error on stderr, exit code 0 (module not loaded)
 * - account "slow@bank"  : waits 2 seconds before answering
 * - account "2fa@bank"   : asks a two-factor authentication, only possible when the output
 *                          is a terminal (like woob); otherwise fails with code 1
 * - other accounts       : one JSON array of transactions per line, like woob -f json
 * - with --debug          : the bank commands also write debug logs on stderr, like woob
 */

$type = $argv[2] ?? '';
$account = $argv[3] ?? '';

if (($argv[1] ?? '') === 'config' && $type === 'modules') {
    echo json_encode(['Name' => 'bnp', 'Capabilities' => ['CapBank'], 'Description' => 'BNP Paribas', 'Installed' => true]) . "\n";
    echo json_encode(['Name' => 'boursorama', 'Capabilities' => ['CapBank'], 'Description' => 'Boursorama', 'Installed' => false]) . "\n";
    echo json_encode(['Name' => 'cic', 'Capabilities' => ['CapBank'], 'Description' => 'CIC', 'Installed' => true]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'list') {
    // One JSON object per line, with the (secret) configuration that must not leak
    echo json_encode(['Name' => 'bnp', 'Module' => 'bnp', 'Configuration' => 'login=12345678, password=*****']) . "\n";
    echo json_encode(['Name' => 'cic_pro', 'Module' => 'cic', 'Configuration' => 'login=jdoe, password=*****']) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'info') {
    // Like woob 3.7: one JSON array; the settings only once the module is installed
    $module = $argv[3] ?? '';
    if ($module === 'unknown') {
        fwrite(STDERR, "Module \"$module\" does not exist.\n");
        exit(1);
    }
    $names = ['bnp' => 'BNP Paribas', 'boursorama' => 'Boursorama', 'cic' => 'CIC'];
    $installed = $module !== 'boursorama' || is_file(sys_get_temp_dir() . '/carbure-fake-woob-installed');
    $info = ['name' => $module, 'description' => $names[$module] ?? $module, 'installed' => $installed ? 'yes' : 'no'];
    if ($installed) {
        $info['config'] = [
            'login' => ['label' => 'Numéro client', 'default' => '', 'description' => 'Numéro client', 'regexp' => null, 'choices' => null, 'masked' => false, 'required' => true],
            'password' => ['label' => 'Code secret', 'default' => '', 'description' => 'Code secret', 'regexp' => '^(\\d{6})$', 'choices' => null, 'masked' => true, 'required' => true],
            'rotating_password' => ['label' => 'Automatically renew password every 100 connections', 'default' => false, 'description' => '', 'regexp' => null, 'choices' => ['y' => 'True', 'n' => 'False'], 'masked' => false, 'required' => false],
            'website' => ['label' => 'Type de compte', 'default' => 'pp', 'description' => 'Type de compte', 'regexp' => null, 'choices' => ['pp' => 'Particuliers/Professionnels', 'hbank' => 'HelloBank'], 'masked' => false, 'required' => false],
        ];
    }
    echo json_encode([$info]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'remove') {
    file_put_contents(sys_get_temp_dir() . '/carbure-fake-woob-removed', $argv[3] ?? '');
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'add') {
    // Like woob 3.7: at most 2 arguments, the module then "backend key=value ..." quoted
    if (count($argv) > 5) {
        fwrite(STDERR, "Error: too many arguments. Command takes at most 2 arguments\n");
        exit(64);
    }
    $words = explode(' ', trim($argv[4] ?? '', '"'));
    $backend = array_shift($words);
    if ($backend === 'carbure_install_probe') {
        // Installs the module, then stops at the first setting asked
        touch(sys_get_temp_dir() . '/carbure-fake-woob-installed');
        echo "Module {$argv[3]} has been installed!\nAborted.\n";
        exit(130);
    }
    $params = [];
    foreach ($words as $word) {
        [$key, $value] = explode('=', $word, 2) + [1 => ''];
        $params[$key] = $value;
    }
    // Records what woob received, to check it
    file_put_contents(sys_get_temp_dir() . '/carbure-fake-woob-add.json', json_encode(['module' => $argv[3], 'backend' => $backend, 'params' => $params]));
    if ($backend === 'taken') {
        echo "Backend \"taken\" already exists.\n";
        exit(0);
    }
    echo "Backend \"$backend\" successfully added.\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config') {
    exit(0);
}

if (($argv[1] ?? '') === 'bank' && $type === 'list') {
    $index = array_search('-b', $argv);
    // Without -b: the backend added last by "config add" (or bnp)
    $added = @json_decode((string)@file_get_contents(sys_get_temp_dir() . '/carbure-fake-woob-add.json'), true);
    $backend = $index === false ? ($added['backend'] ?? 'bnp') : $argv[$index + 1];
    // A bank added with the login "wrong": the bank refuses the connection
    if ($index === false && ($added['params']['login'] ?? '') === 'wrong') {
        fwrite(STDERR, "Error(" . $added['backend'] . "): BrowserIncorrectPassword: Identifiant ou mot de passe incorrect\n");
        exit(1);
    }
    if ($backend === 'none') {
        fwrite(STDERR, "Error(none): Unable to load module \"none\"\n");
        exit(0);
    }
    echo json_encode([
        ['id' => "00012345678@$backend", 'label' => 'Compte chèques', 'balance' => '1234.56', 'currency' => 'EUR'],
        ['id' => "00087654321@$backend", 'label' => 'Livret A', 'balance' => '5000.00', 'currency' => 'EUR'],
    ]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'bank' && in_array('--debug', $argv, true)) {
    fwrite(STDERR, "DEBUG:woob.browser:Request: GET https://bank.example/accounts\n");
    fwrite(STDERR, "DEBUG:woob.browser:Response: 200 OK\n");
    // A debug line that mentions an error without being one
    fwrite(STDERR, "2026-10-12 20:40:53,791:DEBUG:woob.backend.bnp.browser:3.7:browsers.py:1040:internal_callback Handle https://bank.example/error with ErrorPage\n");
}

if ($account === 'fail@bank') {
    fwrite(STDERR, "AttributeError: 'NoneType' object has no attribute 'iter_accounts'\n");
    // Like woob: the session is stored after the error
    if (in_array('--debug', $argv, true)) {
        fwrite(STDERR, "2026-10-12 20:40:54,021:DEBUG:woob.backend.bnp.browser:3.7:browsers.py:1292:dump_state Stored cookies into storage\n");
    }
    exit(1);
}

if ($account === 'empty@bank') {
    exit(0);
}

if ($account === 'broken@bank') {
    fwrite(STDERR, "Error(bnp): Unable to load module \"bnp\": No module named 'curl_cffi'\n");
    exit(0);
}

if ($account === '2fa@bank') {
    if (!stream_isatty(STDOUT)) {
        fwrite(STDERR, "Error(bank): You have to run bank in interactive mode to perform a two-factor authentication\n");
        exit(1);
    }
    // Message of a DecoupledValidation, then the data once validated
    echo "\e[1mVeuillez valider la connexion dans votre application bancaire.\e[0m\n";
}

if ($account === 'slow@bank') {
    sleep(2);
}

$month = date('Y-m');

if ($type === 'coming') {
    echo json_encode([
        ['date' => "$month-05", 'rdate' => "$month-04", 'amount' => -42.5, 'raw' => 'FACTURE CARTE DU 040126 BOULANGERIE CARTE 4974', 'type' => 12, 'card' => '4974XXXXXXXX1234'],
        ['date' => "$month-05", 'rdate' => "$month-05", 'amount' => -10, 'raw' => 'VIR NOT A CARD', 'type' => 1],
    ]) . "\n";
} else {
    echo json_encode([
        ['date' => "$month-02", 'rdate' => "$month-02", 'amount' => -60.1, 'raw' => 'PRLV SEPA EDF MDT/123', 'type' => 2],
        ['date' => "$month-03", 'rdate' => "$month-02", 'amount' => -25, 'raw' => 'FACTURE CARTE DU 020126 SUPERMARCHE CARTE 4974', 'type' => 7, 'card' => '4974XXXXXXXX1234'],
    ]) . "\n";
    echo "not json, ignored\n";
    echo json_encode(['date' => "$month-28", 'rdate' => "$month-28", 'amount' => 2500, 'raw' => 'VIR SALAIRE', 'type' => 1]) . "\n";
}
