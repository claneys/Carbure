<?php

/**
 * fake-woob.php
 *
 * Test double of the woob CLI used by the tests (woob_path = "php tests/fixtures/fake-woob.php").
 * Usage: fake-woob.php bank <coming|history> <account@bank> [options...]
 *        fake-woob.php bank list [-b <backend>] -f json (two accounts, "none": no account; "2fa" or
 *                                                          a bank added with the login "2fa": a
 *                                                          two-factor authentication first, only
 *                                                          on a terminal)
 *        fake-woob.php bank list -b <backend> -f json -s id (their ids and those of the accounts
 *                                                          below, except "unlisted@<backend>")
 *        fake-woob.php config info <module> -f json       (settings once installed, like woob 3.7)
 *        fake-woob.php config add <module> carbure_install_probe (installs the module; any
 *                                                          setting on the command line is refused:
 *                                                          the credentials must never be there)
 *        fake-woob.php config remove <backend>           (no longer listed by config list)
 *        fake-woob.php config update                     (trusts the keyring by itself the first time,
 *                                                          like woob without a terminal)
 * Like woob 3.7, the first run of any command (no carbure-fake-woob-sources.list) refreshes
 * the repositories, trusting their keyring by itself; installing a module needs a keyring.
 *
 * - account "fail@bank"  : writes on stderr and exits with code 1
 * - account "empty@bank" : no output
 * - account "broken@bank": no output, error on stderr, exit code 0 (module not loaded)
 * - account "forbidden@bank" (and bank list -b forbidden): "[]", the bank refuses woob
 *                          ("Bug(...): 403 Client Error: Forbidden" on stderr), exit code 0
 * - account "slow@bank"  : waits 2 seconds before answering
 * - account "nocoming@bank": the coming transactions fail (code 1), the history works
 * - account "validate@bank": asks a validation in the bank app (even on a terminal) until
 *                          the file carbure-fake-woob-validated exists in its directory
 * - account "2fa@bank"   : asks a two-factor authentication, only possible when the output
 *                          is a terminal (like woob); otherwise fails with code 1
 * - other accounts       : one JSON array of transactions per line, like woob -f json
 * - with --debug          : the bank commands also write debug logs on stderr, like woob
 *
 * The backends added by Carbure are read, like woob, in the file WOOB_BACKENDS (set in woob_path
 * by the tests), else carbure-fake-woob-backends; "bank list" without -b lists the last one.
 *
 * Its state (carbure-fake-woob-* files) is kept in the directory of the test run given by
 * the environment variable CARBURE_TEST_TMP (else the temporary directory, as with
 * ./start.sh --fake-woob), so that two runs on the same machine do not share it.
 */

$type = $argv[2] ?? '';

/**
 * A file of the state of the fake woob, in the directory of the test run
 *
 * @param string $name Name of the file, without its carbure-fake-woob- prefix
 * @return string The path of the file
 */
function fake_woob_file(string $name): string
{
    return (getenv('CARBURE_TEST_TMP') ?: sys_get_temp_dir()) . '/carbure-fake-woob-' . $name;
}

$account = $argv[3] ?? '';

/**
 * The backend added last in the configuration file of the backends, like woob reads it
 *
 * @return array{backend: string, module: string, params: array<string, string>}|null
 */
function fake_woob_added(): ?array
{
    $file = getenv('WOOB_BACKENDS') ?: fake_woob_file('backends');
    $sections = is_file($file) ? parse_ini_file($file, true, INI_SCANNER_RAW) : [];
    if (!$sections) {
        return null;
    }
    $params = end($sections);
    $module = $params['_module'] ?? '';
    unset($params['_module']);
    return ['backend' => (string)key($sections), 'module' => $module, 'params' => $params];
}

/**
 * Like woob 3.7 refreshing its repositories while no keyring is trusted: without a
 * terminal, it trusts the keyring of the repository by itself (with the file
 * carbure-fake-woob-other-keyring, the repository serves another keyring)
 */
function fake_woob_trust_keyring(): void
{
    $keyrings = fake_woob_file('keyrings');
    if (glob("$keyrings/*.gpg")) {
        return;
    }
    $keyring = is_file(fake_woob_file('other-keyring'))
        ? "Another keyring\n" : file_get_contents(__DIR__ . '/woob-repository/trusted.gpg');
    echo "The repository https://updates.woob.tech/3/main/ isn't trusted yet.\n"
        . "Fingerprint of keyring is " . sha1($keyring) . "\n"
        . "Are you sure you want to continue? (Y/n): ";
    if (stream_isatty(STDIN)) {
        $answer = fgets(STDIN);
        if ($answer === false || !in_array(trim($answer), ['', 'y', 'Y'], true)) {
            echo "\nAborted.\n";
            exit(1);
        }
    } else {
        echo "*** ASSUMING YES ***\n";
    }
    @mkdir($keyrings, 0700, true);
    file_put_contents("$keyrings/https___updates_woob_tech_3_main_.gpg", $keyring);
    file_put_contents("$keyrings/https___updates_woob_tech_3_main_.version", "202610130000\n");
}

// Like woob 3.7: the first run of any command (no list of repositories yet) creates the
// list and refreshes the repositories first
$sources = fake_woob_file('sources.list');
if (!is_file($sources)) {
    file_put_contents($sources, "https://updates.woob.tech/%(version)s/main/\n");
    fake_woob_trust_keyring();
}

if (($argv[1] ?? '') === 'config' && $type === 'modules') {
    echo json_encode(['Name' => 'bnp', 'Capabilities' => ['CapBank'], 'Description' => 'BNP Paribas', 'Installed' => true]) . "\n";
    echo json_encode(['Name' => 'boursorama', 'Capabilities' => ['CapBank'], 'Description' => 'Boursorama', 'Installed' => false]) . "\n";
    echo json_encode(['Name' => 'cic', 'Capabilities' => ['CapBank'], 'Description' => 'CIC', 'Installed' => true]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'list') {
    // One JSON object per line, with the (secret) configuration that must not leak;
    // the backends removed by "config remove" are no longer listed
    $removed = file(fake_woob_file('removed'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ([['bnp', 'bnp', 'login=12345678, password=*****'], ['cic_pro', 'cic', 'login=jdoe, password=*****']] as [$name, $module, $configuration]) {
        if (!in_array($name, $removed, true)) {
            echo json_encode(['Name' => $name, 'Module' => $module, 'Configuration' => $configuration]) . "\n";
        }
    }
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
    $installed = $module !== 'boursorama' || is_file(fake_woob_file('installed'));
    $info = ['name' => $module, 'description' => $names[$module] ?? $module, 'installed' => $installed ? 'yes' : 'no'];
    if ($installed) {
        $info['config'] = [
            'login' => ['label' => 'Numéro client', 'default' => '', 'description' => 'Numéro client', 'regexp' => null, 'choices' => null, 'masked' => false, 'required' => true],
            'password' => ['label' => 'Code secret', 'default' => '', 'description' => 'Code secret', 'regexp' => '^(\\d{6})$', 'choices' => null, 'masked' => true, 'required' => true],
            'rotating_password' => ['label' => 'Automatically renew password every 100 connections', 'default' => false, 'description' => '', 'regexp' => null, 'choices' => ['y' => 'True', 'n' => 'False'], 'masked' => false, 'required' => false],
            'website' => ['label' => 'Type de compte', 'default' => 'pp', 'description' => 'Type de compte', 'regexp' => null, 'choices' => ['pp' => 'Particuliers/Professionnels', 'hbank' => 'HelloBank'], 'masked' => false, 'required' => false],
            // Optional without default, like "identity" of boursorama: woob refuses it empty
            'identity' => ['label' => "ID d'identité", 'default' => null, 'description' => '', 'regexp' => null, 'choices' => null, 'masked' => false, 'required' => false],
        ];
    }
    echo json_encode([$info]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'remove') {
    // Records the backends removed (one per line), to check it; with the file
    // carbure-fake-woob-stuck, woob silently removes nothing
    if (!is_file(fake_woob_file('stuck'))) {
        file_put_contents(fake_woob_file('removed'), ($argv[3] ?? '') . "\n", FILE_APPEND);
    }
    exit(0);
}

if (($argv[1] ?? '') === 'config' && $type === 'add') {
    // Carbure only installs a module this way: the settings of a backend (its credentials)
    // must never be on the command line, visible to every process
    if (count($argv) !== 5 || $argv[4] !== 'carbure_install_probe') {
        fwrite(STDERR, "Error: fake woob: settings on the command line: " . count($argv) . " arguments\n");
        exit(2);
    }
    // Installs the module, then stops at the first setting asked; like woob, the
    // signature of the module needs the keyring of its repository
    if (!glob(fake_woob_file('keyrings') . '/*.gpg')) {
        fwrite(STDERR, "Error: No keyring found, please update repos.\n");
        exit(1);
    }
    touch(fake_woob_file('installed'));
    echo "Module {$argv[3]} has been installed!\nAborted.\n";
    exit(130);
}

if (($argv[1] ?? '') === 'config' && $type === 'update') {
    // Like woob 3.7: the first update trusts the keyring of the repository
    // (tests/fixtures/woob-repository/trusted.gpg) by itself, kept in carbure-fake-woob-keyrings
    fake_woob_trust_keyring();
    fwrite(STDERR, "\r  0% Updating repositories\r100% All modules are up-to-date.\n");
    exit(0);
}

if (($argv[1] ?? '') === 'config') {
    exit(0);
}

if (($argv[1] ?? '') === 'bank' && $type === 'list') {
    $index = array_search('-b', $argv);
    // Without -b: the backend added last (or bnp)
    $added = fake_woob_added();
    $backend = $index === false ? ($added['backend'] ?? 'bnp') : $argv[$index + 1];
    // A bank added with the login "wrong": the bank refuses the connection
    if ($backend === ($added['backend'] ?? null) && ($added['params']['login'] ?? '') === 'wrong') {
        fwrite(STDERR, "Error(" . $added['backend'] . "): BrowserIncorrectPassword: Identifiant ou mot de passe incorrect\n");
        exit(1);
    }
    // A bank that asks a two-factor authentication (#299): the backend "2fa", or a bank added
    // with the login "2fa"; only possible when the output is a terminal, like woob
    if ($backend === '2fa' || ($backend === ($added['backend'] ?? null) && ($added['params']['login'] ?? '') === '2fa')) {
        if (!stream_isatty(STDOUT)) {
            fwrite(STDERR, "Error($backend): You have to run bank in interactive mode to perform a two-factor authentication\n");
            exit(1);
        }
        echo "\e[1mVeuillez valider la connexion dans votre application bancaire.\e[0m\n";
        // With the file carbure-fake-woob-2fa-wait, the validation waits for the file
        // carbure-fake-woob-validated (30 s at most), as woob waits for the bank app
        for ($i = 0; is_file(fake_woob_file('2fa-wait')) && !is_file(fake_woob_file('validated')) && $i < 300; $i++) {
            usleep(100000);
        }
    }
    if ($backend === 'forbidden') {
        echo "[]\n";
        fwrite(STDERR, "Bug(forbidden): 403 Client Error: Forbidden\nUse --debug option to print backtraces\n");
        exit(0);
    }
    if ($backend === 'none') {
        fwrite(STDERR, "Error(none): Unable to load module \"none\"\n");
        exit(0);
    }
    if (in_array('-s', $argv, true)) {
        // Only the ids (first step of a synchronization): also the accounts of the tests,
        // except "unlisted@<backend>"
        echo json_encode(array_map(fn($account) => ['id' => "$account@$backend"],
            ['DEMO0000001', '00087654321', '1', '111', '123', '222', '333', 'fail', 'empty', 'broken',
             'forbidden', 'slow', 'nocoming', 'validate', '2fa'])) . "\n";
        exit(0);
    }
    echo json_encode([
        ['id' => "DEMO0000001@$backend", 'label' => 'Compte chèques', 'balance' => '1234.56', 'currency' => 'EUR'],
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

if ($account === 'forbidden@bank') {
    // Like woob 3.7 when the website of the bank refuses it: an empty list, exit code 0
    echo "[]\n";
    fwrite(STDERR, "Bug(bank): 403 Client Error: Forbidden\nUse --debug option to print backtraces\n");
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

if ($account === 'nocoming@bank' && $type === 'coming') {
    fwrite(STDERR, "Error(bank): NotImplementedError: coming transactions are not available\n");
    exit(1);
}

if ($account === 'validate@bank' && !is_file(fake_woob_file('validated'))) {
    fwrite(STDERR, "Error(bank): You have to run bank in interactive mode to perform a two-factor authentication\n");
    exit(1);
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
