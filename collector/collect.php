<?php

umask(0027);

/*
 * Retorna o conteudo de um bloco delimitado por chaves.
 * $position deve apontar para a chave de abertura.
 */
function readBlock(string $text, int &$position): string {
    if (($text[$position] ?? '') !== '{') {
        throw new RuntimeException('Inicio de bloco invalido');
    }

    $start = ++$position;
    $depth = 1;
    $length = strlen($text);

    while ($position < $length) {
        $char = $text[$position];

        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;

            if ($depth === 0) {
                $body = substr($text, $start, $position - $start);
                $position++;
                return $body;
            }
        }

        $position++;
    }

    throw new RuntimeException('Resposta IPsec com bloco incompleto');
}

/* Separa blocos nomeados sem depender da quantidade de Child SAs. */
function namedBlocks(string $text): array {
    $blocks = [];
    $position = 0;
    $length = strlen($text);

    while ($position < $length) {
        while ($position < $length && ctype_space($text[$position])) {
            $position++;
        }

        if ($position >= $length) {
            break;
        }

        if (!preg_match(
            '/\G([^\s{}]+)\s*\{/A',
            $text,
            $match,
            0,
            $position
        )) {
            throw new RuntimeException('Estrutura IPsec inesperada');
        }

        $position += strlen($match[0]) - 1;
        $blocks[] = [
            'name' => $match[1],
            'body' => readBlock($text, $position)
        ];
    }

    return $blocks;
}

function numberField(string $text, string $name): ?int {
    $pattern = '/(?:^|\s)' . preg_quote($name, '/') . '=(\d+)(?=\s|$)/';

    return preg_match($pattern, $text, $match)
        ? (int) $match[1]
        : null;
}

$config = require __DIR__ . '/config.php';
$expected = $config['vti'];

$result = [
    'collection_ok' => 0,
    'collected_at' => time(),
    'tunnels' => [],
    'discovery' => []
];

try {
    $tunnels = [];
    $byReqid = [];

    foreach ($expected as $interface => [$unit, $path, $reqid]) {
        $byReqid[$reqid] = $interface;

        $tunnels[$interface] = [
            'unit' => $unit,
            'path' => $path,
            'reqid' => $reqid,
            'ike_established' => 0,
            'child_installed' => 0,
            'session_up' => 0,
            'ike_age_seconds' => null,
            'child_age_seconds' => null,
            'child_id' => null,
            'bytes_in' => null,
            'bytes_out' => null,
            'packets_in' => null,
            'packets_out' => null
        ];
    }

    $output = [];
    $exitCode = 0;

    exec(
        '/usr/local/sbin/swanctl --list-sas --raw 2>&1',
        $output,
        $exitCode
    );

    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Falha na consulta IPsec'
        );
    }

    $raw = trim(implode("\n", $output));
    $position = 0;
    $length = strlen($raw);

    while ($position < $length) {
        while ($position < $length && ctype_space($raw[$position])) {
            $position++;
        }

        if ($position >= $length) {
            break;
        }

        if (!preg_match(
            '/\G(?:list-sa event|list-sas reply)\s*\{/A',
            $raw,
            $match,
            0,
            $position
        )) {
            throw new RuntimeException('Formato inesperado na consulta IPsec');
        }

        $position += strlen($match[0]) - 1;
        $event = readBlock($raw, $position);

        foreach (namedBlocks($event) as $ikeBlock) {
            $body = $ikeBlock['body'];

            if (!preg_match(
                '/\bchild-sas\s*\{/',
                $body,
                $childMarker,
                PREG_OFFSET_CAPTURE
            )) {
                throw new RuntimeException('Sessao sem bloco child-sas');
            }

            $markerStart = $childMarker[0][1];
            $ikeHeader = substr($body, 0, $markerStart);

            $childPosition =
                $markerStart + strlen($childMarker[0][0]) - 1;

            $childrenText = readBlock($body, $childPosition);

            $ikeUp = preg_match(
                '/(?:^|\s)state=ESTABLISHED(?=\s|$)/',
                $ikeHeader
            ) === 1;

            foreach (namedBlocks($childrenText) as $childBlock) {
                $child = $childBlock['body'];
                $reqid = numberField($child, 'reqid');

                if ($reqid === null || !isset($byReqid[$reqid])) {
                    continue;
                }

                $interface = $byReqid[$reqid];

                if ($ikeUp) {
                    $tunnels[$interface]['ike_established'] = 1;
                }

                $childUp = preg_match(
                    '/(?:^|\s)state=INSTALLED(?=\s|$)/',
                    $child
                ) === 1;

                if (!$ikeUp || !$childUp) {
                    continue;
                }

                $childId = numberField($child, 'uniqueid');

                if ($childId === null) {
                    throw new RuntimeException('Child SA sem uniqueid');
                }

                if ($tunnels[$interface]['child_id'] !== null &&
                    $childId <= $tunnels[$interface]['child_id']) {
                    continue;
                }

                $tunnel = &$tunnels[$interface];

                $tunnel['child_installed'] = 1;
                $tunnel['session_up'] = 1;
                $tunnel['child_id'] = $childId;
                $tunnel['ike_age_seconds'] =
                    numberField($ikeHeader, 'established');

                foreach ([
                    'install-time' => 'child_age_seconds',
                    'bytes-in' => 'bytes_in',
                    'bytes-out' => 'bytes_out',
                    'packets-in' => 'packets_in',
                    'packets-out' => 'packets_out'
                ] as $source => $destination) {
                    $tunnel[$destination] = numberField($child, $source);
                }

                unset($tunnel);
            }
        }
    }

    foreach ($expected as $interface => [$unit, $path, $reqid]) {
        $result['discovery'][] = [
            '{#IFNAME}' => $interface,
            '{#UNIT}' => $unit,
            '{#PATH}' => $path,
            '{#REQID}' => $reqid
        ];
    }

    // EXTRA_POLICY_VPNS
    $extra = $config['policy'];

    $seen = [];

    foreach ($extra as $connection => $config) {
        $seen[$connection] = [
            'ike' => 0,
            'children' => []
        ];
    }

    $position = 0;

    while ($position < strlen($raw)) {
        while ($position < strlen($raw) &&
               ctype_space($raw[$position])) {
            $position++;
        }

        if ($position >= strlen($raw)) {
            break;
        }

        if (!preg_match(
            '/\G(?:list-sa event|list-sas reply)\s*\{/A',
            $raw,
            $match,
            0,
            $position
        )) {
            throw new RuntimeException('Formato inesperado nas VPNs adicionais');
        }

        $position += strlen($match[0]) - 1;
        $event = readBlock($raw, $position);

        foreach (namedBlocks($event) as $ikeBlock) {
            $connection = $ikeBlock['name'];

            if (!isset($extra[$connection])) {
                continue;
            }

            $body = $ikeBlock['body'];

            if (!preg_match(
                '/\bchild-sas\s*\{/',
                $body,
                $childMarker,
                PREG_OFFSET_CAPTURE
            )) {
                throw new RuntimeException('Bloco child-sas ausente');
            }

            $start = $childMarker[0][1];
            $header = substr($body, 0, $start);
            $childPosition = $start + strlen($childMarker[0][0]) - 1;
            $childrenText = readBlock($body, $childPosition);

            if (!preg_match(
                '/(?:^|\s)state=ESTABLISHED(?=\s|$)/',
                $header
            )) {
                continue;
            }

            $seen[$connection]['ike'] = 1;

            foreach (namedBlocks($childrenText) as $childBlock) {
                $child = $childBlock['body'];

                if (!preg_match('/(?:^|\s)name=([^\s{}]+)/', $child, $name)) {
                    throw new RuntimeException('Child SA sem nome');
                }

                if (in_array(
                    $name[1],
                    $extra[$connection]['children'],
                    true
                ) && preg_match(
                    '/(?:^|\s)state=INSTALLED(?=\s|$)/',
                    $child
                )) {
                    $seen[$connection]['children'][$name[1]] = true;
                }
            }
        }
    }

    foreach ($extra as $connection => $config) {
        $installed = count($seen[$connection]['children']);
        $expectedCount = count($config['children']);

        // 0 = nenhuma; 1 = todas; 2 = parcialmente instalada.
        $state = $installed === 0
            ? 0
            : ($installed === $expectedCount ? 1 : 2);

        $tunnels[$connection] = [
            'unit' => $config['unit'],
            'path' => '',
            'connection' => $connection,
            'ike_established' => $seen[$connection]['ike'],
            'child_installed' => $installed > 0 ? 1 : 0,
            'session_up' => $state,
            'children_expected' => $expectedCount,
            'children_installed' => $installed
        ];

        $result['discovery'][] = [
            '{#IFNAME}' => $connection,
            '{#UNIT}' => $config['unit'],
            '{#PATH}' => '',
            '{#REQID}' => ''
        ];
    }

    $result['tunnels'] = $tunnels;
    $result['collection_ok'] = 1;

} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
}

$json = json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_THROW_ON_ERROR
) . "\n";

$target = $config['status_file'];
$temp = tempnam(dirname($target), '.status-');

if ($temp === false) {
    fwrite(STDERR, "Falha ao criar arquivo temporario\n");
    exit(1);
}

if (file_put_contents($temp, $json) !== strlen($json) ||
    !chgrp($temp, $config['status_group']) ||
    !chmod($temp, 0640) ||
    !rename($temp, $target)) {
    @unlink($temp);
    fwrite(STDERR, "Falha ao publicar JSON\n");
    exit(1);
}

echo $json;
exit($result['collection_ok'] ? 0 : 1);
