<?php

// Build-time guard: fail if any given CA bundle still trusts an emSign certificate.

$found = 0;

foreach (array_slice($argv, 1) as $bundle) {
    $pem = file_get_contents($bundle);
    if ($pem === false) {
        fwrite(STDERR, "cannot read $bundle\n");
        exit(1);
    }

    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches);
    if (count($matches[0]) === 0) {
        fwrite(STDERR, "no certificates in $bundle\n");
        exit(1);
    }

    foreach ($matches[0] as $cert) {
        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            fwrite(STDERR, "$bundle: unparseable certificate\n");
            exit(1);
        }
        $names = $parsed['name'] . ' ' . json_encode($parsed['issuer']);
        if (stripos($names, 'emsign') !== false || stripos($names, 'emudhra') !== false) {
            fwrite(STDERR, "$bundle: {$parsed['name']}\n");
            $found++;
        }
    }

    echo "$bundle: " . count($matches[0]) . " certificates checked\n";
}

exit($found === 0 ? 0 : 1);
