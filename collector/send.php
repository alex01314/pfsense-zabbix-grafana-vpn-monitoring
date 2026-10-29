<?php

$socket = null;
$exitCode = 1;

try {
    $config = require __DIR__ . '/config.php';
    $content = @file_get_contents($config['status_file']);
    if ($content === false) {
        throw new RuntimeException('Nao foi possivel ler status.json');
    }
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) ||
        !isset($data['collected_at'], $data['collection_ok']) ||
        !is_int($data['collected_at'])) {
        throw new RuntimeException('Formato de coleta invalido');
    }
    $age = time() - $data['collected_at'];
    if ($age < -30 || $age > 180) {
        throw new RuntimeException('Coleta desatualizada');
    }

    // Envia tambem collection_ok=0 para informar falhas do coletor.
    $payload = json_encode([
        'request' => 'sender data',
        'data' => [[
            'host' => $config['zabbix_host'],
            'key' => $config['zabbix_key'],
            'value' => $content,
        ]],
    ], JSON_THROW_ON_ERROR);
    $socket = @stream_socket_client(
        'tcp://' . $config['zabbix_server'] . ':' . $config['zabbix_port'],
        $errorCode, $errorMessage, 5
    );
    if ($socket === false) {
        // Nao incluir endereco ou mensagens de rede no log publico.
        throw new RuntimeException('Falha na conexao com Zabbix');
    }
    stream_set_timeout($socket, 5);
    $packet = "ZBXD\x01" . pack('V2', strlen($payload), 0) . $payload;
    $offset = 0;
    while ($offset < strlen($packet)) {
        $written = fwrite($socket, substr($packet, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Falha ao enviar dados');
        }
        $offset += $written;
    }
    $readExact = function (int $length) use ($socket): string {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($socket, $length - strlen($buffer));
            if ($part === false || $part === '') {
                throw new RuntimeException('Resposta incompleta ou tempo de espera excedido');
            }
            $buffer .= $part;
        }
        return $buffer;
    };
    $header = $readExact(13);
    if (substr($header, 0, 5) !== "ZBXD\x01") {
        throw new RuntimeException('Cabecalho de resposta inesperado');
    }
    $size = unpack('Vlow/Vhigh', substr($header, 5, 8));
    if ($size['high'] !== 0 || $size['low'] < 1 || $size['low'] > 1048576) {
        throw new RuntimeException('Tamanho de resposta invalido');
    }
    $response = json_decode($readExact($size['low']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response) ||
        ($response['response'] ?? '') !== 'success' ||
        !preg_match('/processed:\s*1;\s*failed:\s*0;\s*total:\s*1\b/', $response['info'] ?? '')) {
        throw new RuntimeException('Zabbix nao confirmou o recebimento do item');
    }
    echo "Envio confirmado: processed=1 failed=0 total=1\n";
    $exitCode = 0;
} catch (Throwable $error) {
    fwrite(STDERR, 'ERRO: ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($socket)) {
        fclose($socket);
    }
}
exit($exitCode);
